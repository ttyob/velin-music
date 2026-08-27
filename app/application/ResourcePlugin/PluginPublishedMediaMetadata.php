<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\Media\MediaMetadata;
use JsonException;

/**
 * 保存资源插件已经写入音频文件、且可由核心发布账本证明归属的描述元数据。
 *
 * `filename_only` 网络库不会读取远端音频正文，但资源插件在上传前已经持有并校验本地完整文件。核心把
 * 这份受限快照与发布路径、大小、摘要和远端发现 ETag 一起保存，扫描时只有对象身份仍完全一致才允许
 * 将其作为 raw 标签使用。快照不保存歌词正文、封面地址、平台 ID、凭据或物理路径；未知字段拒绝进入
 * 账本，避免插件借通用发布接口扩展持久化数据面。
 *
 * 该对象只做内存校验与转换，不访问数据库、文件或网络。非法输入抛出异常并使发布在任何外部副作用前
 * 失败；重复序列化结果稳定，可用于幂等摘要比较。
 */
final readonly class PluginPublishedMediaMetadata
{
    private const OPTIONAL_INTS = ['trackNumber', 'trackTotal', 'discNumber', 'discTotal'];

    /** @param array<string,mixed> $values */
    private function __construct(private array $values)
    {
    }

    /**
     * 从插件可选快照和发布命令的必填身份建立规范值。
     *
     * 插件未传快照时仍使用生成目标路径的标题、艺人和专辑，保证旧插件二进制与新增核心参数兼容；传入
     * 快照时只接受白名单字段，并要求标题和至少一名艺人非空。专辑为空表示单曲，不伪造 `单曲` raw
     * 标签。字段越界或类型错误抛出 `PluginMediaPublicationFailed`，上传尚未开始，因此无需补偿。
     *
     * @param array<string,mixed>|null $metadata
     * @throws PluginMediaPublicationFailed
     */
    public static function fromPublicationCommand(
        ?array $metadata,
        string $fallbackArtist,
        string $fallbackAlbum,
        string $fallbackTitle,
    ): self {
        $metadata ??= [];
        $allowed = ['title', 'artists', 'albumTitle', 'albumArtists', 'trackNumber', 'trackTotal',
            'discNumber', 'discTotal', 'genres', 'releaseDate', 'isrc', 'durationMs'];
        if (array_diff(array_keys($metadata), $allowed) !== []) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        $title = self::text($metadata['title'] ?? $fallbackTitle, 500);
        $artists = self::texts($metadata['artists'] ?? [trim($fallbackArtist)], 20, 255);
        $album = self::nullableText($metadata['albumTitle'] ?? $fallbackAlbum, 500);
        $albumArtists = self::texts($metadata['albumArtists'] ?? $artists, 20, 255);
        if ($title === '' || $artists === [] || $albumArtists === []) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        $values = [
            'schemaVersion' => 1,
            'title' => $title,
            'artists' => $artists,
            'albumTitle' => $album,
            'albumArtists' => $albumArtists,
        ];
        foreach (self::OPTIONAL_INTS as $field) {
            $values[$field] = self::nullableInt($metadata[$field] ?? null, 1, 9999);
        }
        $values['genres'] = self::texts($metadata['genres'] ?? [], 50, 120);
        $values['releaseDate'] = self::releaseDate($metadata['releaseDate'] ?? null);
        $values['isrc'] = self::isrc($metadata['isrc'] ?? null);
        $values['durationMs'] = self::nullableInt($metadata['durationMs'] ?? null, 0, 2_147_483_647);
        return new self($values);
    }

    /**
     * 从数据库恢复已经由发布入口校验的快照。
     *
     * 数据库损坏、未知版本或非规范 JSON 均返回 null，扫描器随后退回普通 `filename_only` 占位，不能
     * 因账本异常信任路径文本。恢复过程再次执行与写入相同的类型约束，但不使用任何路径回退值。
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value) || ($value['schemaVersion'] ?? null) !== 1) return null;
            unset($value['schemaVersion']);
            return self::fromPublicationCommand($value, '', '', '');
        } catch (JsonException|PluginMediaPublicationFailed) {
            return null;
        }
    }

    /** 使用稳定字段顺序生成不包含可选资源正文的账本 JSON。 */
    public function toJson(): string
    {
        return json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 转换为扫描目录写入器使用的媒体对象。
     *
     * 描述字段来自受信发布快照；技术字段只使用插件明确提供的时长和库存扩展名，未读取过的编码、码率
     * 等保持 null。rawTags 使用常见 FFprobe 标签键，使完整度策略把这次扫描识别为 metadata 模式；
     * `velin.remoteProbe=published` 明确区分主动跳过正文和账本接管。方法不写库或文件。
     */
    public function toMediaMetadata(string $extension): MediaMetadata
    {
        $value = $this->values;
        $format = [
            'title' => $value['title'],
            'artist' => implode('; ', $value['artists']),
            'album_artist' => implode('; ', $value['albumArtists']),
        ];
        if (is_string($value['albumTitle'])) $format['album'] = $value['albumTitle'];
        if ($value['trackNumber'] !== null) $format['track'] = (string) $value['trackNumber'];
        if ($value['discNumber'] !== null) $format['disc'] = (string) $value['discNumber'];
        if ($value['releaseDate'] !== null) $format['date'] = $value['releaseDate'];
        if ($value['genres'] !== []) $format['genre'] = implode('; ', $value['genres']);
        if ($value['isrc'] !== null) $format['isrc'] = $value['isrc'];
        $releaseYear = is_string($value['releaseDate'])
            && preg_match('/^(\d{4})/', $value['releaseDate'], $match) === 1 ? (int) $match[1] : null;
        return new MediaMetadata(
            title: $value['title'],
            sortTitle: null,
            artists: $value['artists'],
            albumArtists: $value['albumArtists'],
            albumTitle: $value['albumTitle'] ?? '单曲',
            albumSortTitle: null,
            hasTaggedAlbum: $value['albumTitle'] !== null,
            trackNumber: $value['trackNumber'],
            trackTotal: $value['trackTotal'],
            discNumber: $value['discNumber'],
            discTotal: $value['discTotal'],
            genres: $value['genres'],
            releaseDate: $value['releaseDate'],
            releaseYear: $releaseYear,
            composer: null,
            comment: null,
            bpm: null,
            isrc: $value['isrc'],
            musicbrainzTrackId: null,
            musicbrainzArtistId: null,
            musicbrainzReleaseId: null,
            musicbrainzReleaseGroupId: null,
            durationMs: $value['durationMs'] ?? 0,
            codecName: null,
            containerName: strtolower($extension),
            bitrate: null,
            bitDepth: null,
            sampleRate: null,
            channels: null,
            replaygainTrackGain: null,
            replaygainTrackPeak: null,
            replaygainAlbumGain: null,
            replaygainAlbumPeak: null,
            rawTags: [
                'format' => $format,
                'velin' => [
                    'remoteMetadataMode' => 'filename_only',
                    'remoteProbe' => 'published',
                    'publicationMetadataVersion' => 1,
                ],
            ],
        );
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $limit || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) return '';
        return $value;
    }

    private static function nullableText(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') return null;
        $text = self::text($value, $limit);
        if ($text === '') throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        return $text;
    }

    /** @return list<string> */
    private static function texts(mixed $value, int $count, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $count) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        $result = [];
        foreach ($value as $item) {
            $text = self::text($item, $limit);
            if ($text === '') throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
            if (!in_array($text, $result, true)) $result[] = $text;
        }
        return $result;
    }

    private static function nullableInt(mixed $value, int $minimum, int $maximum): ?int
    {
        if ($value === null) return null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        return $value;
    }

    private static function releaseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)
            || preg_match('/^(?:1\d{3}|2\d{3})(?:-(?:0[1-9]|1[0-2])(?:-(?:0[1-9]|[12]\d|3[01]))?)?$/D', $value) !== 1) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        return $value;
    }

    private static function isrc(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D', $value) !== 1) {
            throw new PluginMediaPublicationFailed('PLUGIN_MEDIA_METADATA_INVALID');
        }
        return $value;
    }
}
