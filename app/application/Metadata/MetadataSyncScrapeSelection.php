<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Scrape\ScrapeMetadataCandidate;
use JsonException;

/**
 * 表达一次人工单曲刮削中“字段或资源 -> 固定渠道/opaque 候选”的选择，不携带第三方正文或定位信息。
 *
 * 当前生产候选只提交唯一插件键 `metadata-scrape`；旧九渠道键仅用于滚动升级期间确认已经冻结的历史
 * 候选。服务端仍从任务快照读取真实值并在 Worker 复验；生产插件 Provider 收到旧键只返回 unavailable，
 * 绝不会重新启用核心 Helper。`null` 资源表示管理员明确不导入该资源，不会回退到其他渠道。JSON 大小
 * 有界且顺序稳定，适合 CAS、审计摘要和滚动部署读取。
 */
final readonly class MetadataSyncScrapeSelection
{
    /** @var array<string,string> 人工可选字段到候选 evidence 后缀的固定映射。 */
    public const METADATA_FIELDS = [
        'title' => 'title',
        'artists' => 'artists',
        'albumArtists' => 'album_artists',
        'albumTitle' => 'album_title',
        'trackNumber' => 'track_number',
        'trackTotal' => 'track_total',
        'discNumber' => 'disc_number',
        'discTotal' => 'disc_total',
        'releaseDate' => 'release_date',
        'genres' => 'genres',
        'isrc' => 'isrc',
        'musicbrainzTrackId' => 'musicbrainz_track_id',
        'musicbrainzArtistId' => 'musicbrainz_artist_id',
        'musicbrainzReleaseId' => 'musicbrainz_release_id',
        'musicbrainzReleaseGroupId' => 'musicbrainz_release_group_id',
    ];

    /** @var list<string> */
    private const CHANNELS = [
        'metadata-scrape',
        // 只兼容已经冻结候选的旧任务；生产 Provider 不会再查询这些核心渠道。
        'netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music', 'musicbrainz', 'lrclib',
    ];

    /**
     * @param array<string,string> $metadata 每个键独立选择的渠道；允许只选部分字段。
     */
    private function __construct(
        public array $metadata,
        public ?string $lyrics,
        public ?string $artwork,
        public array $relatedArtwork,
    ) {
    }

    /**
     * 校验 HTTP 选择结构并规范化字段顺序。
     *
     * 结构必须精确包含 metadata、lyrics、artwork、relatedArtwork；关联图片只接收
     * `实体类型:ULID -> 候选 ULID`。候选的实体、搜索版本和证据由确认服务从数据库复验，浏览器不能
     * 提交图片 URL、字节或平台响应。至少选择一项，避免空确认推进任务版本。
     */
    public static function fromPayload(mixed $payload): self
    {
        if (is_array($payload) && !array_is_list($payload) && !array_key_exists('relatedArtwork', $payload)) {
            // 滚动部署中的旧管理端没有该键；它等价于明确不选择专辑图或艺人图，不得自动补选。
            $payload['relatedArtwork'] = [];
        }
        if (!is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($payload), ['metadata', 'lyrics', 'artwork', 'relatedArtwork']) !== []
            || array_diff(['metadata', 'lyrics', 'artwork', 'relatedArtwork'], array_keys($payload)) !== []
            || !is_array($payload['metadata']) || ($payload['metadata'] !== [] && array_is_list($payload['metadata']))
            || !is_array($payload['relatedArtwork'])
            || ($payload['relatedArtwork'] !== [] && array_is_list($payload['relatedArtwork']))) {
            throw new MediaMetadataInvalid('逐项候选选择结构无效。');
        }
        $metadata = [];
        foreach ($payload['metadata'] as $field => $channel) {
            if (!is_string($field) || !isset(self::METADATA_FIELDS[$field]) || !is_string($channel)) {
                throw new MediaMetadataInvalid('逐项元数据来源无效。');
            }
            self::assertChannel($channel);
            $metadata[$field] = $channel;
        }
        ksort($metadata);
        $lyrics = self::nullableChannel($payload['lyrics']);
        $artwork = self::nullableChannel($payload['artwork']);
        $relatedArtwork = [];
        foreach ($payload['relatedArtwork'] as $entityKey => $candidateId) {
            if (!is_string($entityKey)
                || preg_match('/^(song|album|artist):[0-9A-HJKMNP-TV-Z]{26}$/', $entityKey) !== 1
                || !is_string($candidateId)
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $candidateId) !== 1) {
                throw new MediaMetadataInvalid('关联图片候选选择无效。');
            }
            $relatedArtwork[$entityKey] = $candidateId;
        }
        ksort($relatedArtwork);
        if ($metadata === [] && $lyrics === null && $artwork === null && $relatedArtwork === []) {
            throw new MediaMetadataInvalid('至少选择一项元数据或资源。');
        }
        return new self($metadata, $lyrics, $artwork, $relatedArtwork);
    }

    /** 从任务 JSON 恢复并重新执行与 HTTP 相同的白名单校验。 */
    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaMetadataInvalid('逐项候选选择快照损坏。', previous: $exception);
        }
        if (!is_array($payload) || !in_array($payload['version'] ?? null, [1, 2], true)) {
            throw new MediaMetadataInvalid('逐项候选选择版本无效。');
        }
        if ($payload['version'] === 1) $payload['relatedArtwork'] = [];
        unset($payload['version']);
        return self::fromPayload($payload);
    }

    /** 编码稳定、有界且不含候选值的任务快照。 */
    public function toJson(): string
    {
        return json_encode([
            'version' => 2,
            'metadata' => $this->metadata,
            'lyrics' => $this->lyrics,
            'artwork' => $this->artwork,
            'relatedArtwork' => $this->relatedArtwork,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** 返回去重且排序稳定的全部被选渠道，用于冻结和复验候选摘要。 */
    public function sources(): array
    {
        $sources = array_values($this->metadata);
        if ($this->lyrics !== null) $sources[] = $this->lyrics;
        if ($this->artwork !== null) $sources[] = $this->artwork;
        $sources = array_values(array_unique($sources));
        sort($sources);
        return $sources;
    }

    /** 标题来源优先作为兼容主渠道；否则使用第一个字段或资源来源。 */
    public function primarySource(): string
    {
        if (isset($this->metadata['title'])) return $this->metadata['title'];
        $sources = $this->sources();
        return $sources[0] ?? 'artwork_provider';
    }

    /** 候选只有携带明确字段证据时才能被该字段选择，防止把本地回退伪装成平台值。 */
    public static function candidateProvides(ScrapeMetadataCandidate $candidate, string $field): bool
    {
        $suffix = self::METADATA_FIELDS[$field] ?? null;
        return is_string($suffix)
            && array_key_exists($field, $candidate->metadata)
            && in_array('music_source_field_' . $suffix, $candidate->evidence, true);
    }

    /**
     * 对被选渠道的标准候选建立聚合摘要。
     *
     * 调用方必须为 selections 中每个来源提供 matched 候选；键排序后编码，避免数据库或 Provider 返回
     * 顺序变化造成假冲突。摘要不覆盖歌词正文或图片字节，资源可用性仍由应用阶段单独复验。
     *
     * @param array<string,ScrapeMetadataCandidate> $candidates
     */
    public function candidateDigest(array $candidates): string
    {
        $snapshots = [];
        foreach ($this->sources() as $source) {
            $candidate = $candidates[$source] ?? null;
            if (!$candidate instanceof ScrapeMetadataCandidate || $candidate->source !== $source) {
                throw new MediaMetadataConflict('所选渠道候选已经变化。');
            }
            $snapshots[$source] = $candidate->toJson();
        }
        return hash('sha256', json_encode($snapshots, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function nullableChannel(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new MediaMetadataInvalid('资源来源选择无效。');
        self::assertChannel($value);
        return $value;
    }

    private static function assertChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new MediaMetadataInvalid('逐项候选包含未知渠道。');
        }
    }
}
