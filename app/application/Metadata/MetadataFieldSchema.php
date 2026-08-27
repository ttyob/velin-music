<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Media\MediaMetadata;
use DateTimeImmutable;
use Throwable;

/**
 * 定义歌曲元数据字段的唯一名称、类型、边界和来源映射。
 *
 * Controller、单项编辑、批量方案和扫描同步必须复用本类，不能各自接受不同字段或宽松类型。所有值在
 * JSON 编码前已规范化；空字符串按字段语义变为 null，列表去重并保留顺序，外部 ID 只接受固定键。
 * `cover` 与 `lyrics` 在详情中由独立领域投影，本类不允许用任意字符串冒充文件路径或歌词正文。
 */
final class MetadataFieldSchema
{
    /** @var list<string> */
    public const FIELDS = [
        'title', 'sortTitle', 'artists', 'albumArtists', 'album', 'trackNumber', 'trackTotal',
        'discNumber', 'discTotal', 'releaseDate', 'genres', 'moods', 'contributors', 'label',
        'edition', 'explicit', 'externalIds',
    ];

    /** @var list<string> */
    public const LIST_FIELDS = ['artists', 'albumArtists', 'genres', 'moods', 'contributors'];

    /** 将 FFprobe 的原始规范对象转换为完整字段快照。 */
    public function fromMediaMetadata(MediaMetadata $metadata): array
    {
        return [
            'title' => $metadata->title,
            'sortTitle' => $metadata->sortTitle,
            'artists' => $metadata->artists,
            'albumArtists' => $metadata->albumArtists,
            'album' => $metadata->albumTitle,
            'trackNumber' => $metadata->trackNumber,
            'trackTotal' => $metadata->trackTotal,
            'discNumber' => $metadata->discNumber,
            'discTotal' => $metadata->discTotal,
            'releaseDate' => $metadata->releaseDate,
            'genres' => $metadata->genres,
            'moods' => [],
            'contributors' => $metadata->composer === null ? [] : [$metadata->composer],
            'label' => null,
            'edition' => null,
            'explicit' => null,
            'externalIds' => array_filter([
                'isrc' => $metadata->isrc,
                'musicbrainzTrackId' => $metadata->musicbrainzTrackId,
                'musicbrainzArtistId' => $metadata->musicbrainzArtistId,
                'musicbrainzReleaseId' => $metadata->musicbrainzReleaseId,
                'musicbrainzReleaseGroupId' => $metadata->musicbrainzReleaseGroupId,
            ], static fn (?string $value): bool => $value !== null),
        ];
    }

    /**
     * 将刮削候选转换为稀疏字段快照；未提供的键必须保持缺失，不能用 null 清掉原始标签。
     *
     * @param array<string,mixed> $candidate 已由 ScrapeMetadataCandidate 校验的候选值。
     * @return array<string,mixed>
     */
    public function fromScrapeCandidate(array $candidate): array
    {
        $mapping = [
            'title' => 'title', 'artists' => 'artists', 'albumArtists' => 'albumArtists',
            'albumTitle' => 'album', 'trackNumber' => 'trackNumber', 'trackTotal' => 'trackTotal',
            'discNumber' => 'discNumber', 'discTotal' => 'discTotal', 'releaseDate' => 'releaseDate',
            'genres' => 'genres', 'label' => 'label', 'edition' => 'edition', 'explicit' => 'explicit',
        ];
        $result = [];
        foreach ($mapping as $source => $field) {
            if (array_key_exists($source, $candidate) && $candidate[$source] !== null && $candidate[$source] !== '') {
                $result[$field] = $this->normalize($field, $candidate[$source]);
            }
        }
        $external = [];
        foreach (['isrc', 'musicbrainzTrackId', 'musicbrainzArtistId', 'musicbrainzReleaseId', 'musicbrainzReleaseGroupId'] as $key) {
            if (is_string($candidate[$key] ?? null) && trim($candidate[$key]) !== '') $external[$key] = trim($candidate[$key]);
        }
        if ($external !== []) $result['externalIds'] = $this->normalize('externalIds', $external);
        return $result;
    }

    /** 按字段类型执行严格规范化；`clear` 操作也必须调用本方法生成该字段合法空值。 */
    public function normalize(string $field, mixed $value): mixed
    {
        if (!in_array($field, self::FIELDS, true)) throw new MediaMetadataInvalid('不支持的元数据字段。');
        if (in_array($field, self::LIST_FIELDS, true)) return $this->stringList($value, $field);
        if (in_array($field, ['trackNumber', 'trackTotal', 'discNumber', 'discTotal'], true)) {
            if ($value === null) return null;
            if (!is_int($value) || $value < 0 || $value > 9999) throw new MediaMetadataInvalid('曲目或碟号无效。');
            return $value;
        }
        if ($field === 'explicit') {
            if ($value !== null && !is_bool($value)) throw new MediaMetadataInvalid('显式标记无效。');
            return $value;
        }
        if ($field === 'externalIds') return $this->externalIds($value);
        if ($value === null || $value === '') {
            if ($field === 'title' || $field === 'album') throw new MediaMetadataInvalid('标题和专辑不能为空。');
            return null;
        }
        if (!is_string($value)) throw new MediaMetadataInvalid('文本字段类型无效。');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $maximum = in_array($field, ['title', 'album'], true) ? 500 : 255;
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) throw new MediaMetadataInvalid('文本字段长度无效。');
        if ($field === 'releaseDate') {
            if (preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value) !== 1) throw new MediaMetadataInvalid('发行日期格式无效。');
            try {
                if (strlen($value) === 10 && (new DateTimeImmutable($value))->format('Y-m-d') !== $value) {
                    throw new MediaMetadataInvalid('发行日期无效。');
                }
            } catch (MediaMetadataInvalid $error) {
                throw $error;
            } catch (Throwable) {
                throw new MediaMetadataInvalid('发行日期无效。');
            }
        }
        return $value;
    }

    /** 返回字段的显式空值；标题和专辑不允许 clear。 */
    public function emptyValue(string $field): mixed
    {
        if (in_array($field, ['title', 'album'], true)) throw new MediaMetadataInvalid('该字段不能清空。');
        return in_array($field, self::LIST_FIELDS, true) || $field === 'externalIds' ? [] : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 64) throw new MediaMetadataInvalid('列表字段无效。');
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) throw new MediaMetadataInvalid('列表字段类型无效。');
            $item = preg_replace('/\s+/u', ' ', trim($item)) ?? trim($item);
            if ($item === '' || mb_strlen($item, 'UTF-8') > 255) throw new MediaMetadataInvalid('列表项长度无效。');
            $key = mb_strtolower($item, 'UTF-8');
            if (!isset($result[$key])) $result[$key] = $item;
        }
        if (in_array($field, ['artists', 'albumArtists'], true) && $result === []) {
            throw new MediaMetadataInvalid('艺术家列表不能为空。');
        }
        return array_values($result);
    }

    /** @return array<string,string> */
    private function externalIds(mixed $value): array
    {
        // PHP 把空关联数组也视为 list；空集合是合法 clear/初始化值，非空列表仍必须拒绝。
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 16) throw new MediaMetadataInvalid('外部 ID 无效。');
        $allowed = ['isrc', 'musicbrainzTrackId', 'musicbrainzArtistId', 'musicbrainzReleaseId', 'musicbrainzReleaseGroupId'];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !in_array($key, $allowed, true) || !is_string($item)) throw new MediaMetadataInvalid('外部 ID 键或值无效。');
            $item = trim($item);
            if ($item === '' || strlen($item) > 255 || preg_match('/[\x00-\x1F\x7F]/', $item) === 1) throw new MediaMetadataInvalid('外部 ID 值无效。');
            $result[$key] = $item;
        }
        ksort($result);
        return $result;
    }
}
