<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * MetadataScrapeCompletionResult 是 `metadata_scrape` 插件向核心交付的单一最终结论。
 *
 * `matched` 代表插件已经在包内完成第三方查询、评分和取舍；核心只能校验并采用这一条结论，不能索取或
 * 重排插件内部候选。`unmatched` 是正常的无可信结果，`unavailable` 表示插件暂时不能提供结论，两者均
 * 不得夹带字段、歌词或封面。该对象不包含平台 ID、候选列表、原始响应、Cookie 或任何内部来源信息。
 *
 * metadata 仅允许稳定的通用音乐字段。核心会在自身边界再次把它叠加到已冻结的本地候选，并负责审批、
     * 数据库事务、歌词解析、歌曲/专辑封面下载校验与发布。封面允许固定来源历史接口返回 HTTP，后续统一
     * HostPolicy 仍会校验平台域名和公开 DNS、升级为 HTTPS 并固定 IP；本 DTO 不执行网络或文件操作。
 */
final readonly class MetadataScrapeCompletionResult
{
    public const MATCHED = 'matched';
    public const UNMATCHED = 'unmatched';
    public const UNAVAILABLE = 'unavailable';

    /** @var list<string> */
    private const METADATA_FIELDS = [
        'title', 'artists', 'albumTitle', 'albumArtists', 'trackNumber', 'trackTotal', 'discNumber', 'discTotal',
        'releaseDate', 'genres', 'composer', 'isrc', 'musicbrainzTrackId', 'musicbrainzArtistId',
        'musicbrainzReleaseId', 'musicbrainzReleaseGroupId',
    ];

    public string $status;

    /** @var array<string,mixed>|null */
    public ?array $metadata;

    public ?int $confidence;

    public ?string $lyrics;

    /** @var list<string> 按插件可信度排序的歌词正文；核心逐项解析，首项保持旧字段兼容。 */
    public array $lyricsCandidates;

    public ?string $artworkUrl;

    /** @var list<string> 按插件可信度排序的歌曲封面地址；核心逐项执行安全下载与图片校验。 */
    public array $artworkUrls;

    /** 插件确认的专辑正面图地址；核心只将其交给专辑图片状态机。 */
    public ?string $albumArtworkUrl;

    /** @var list<string> 按插件可信度排序的专辑封面地址。 */
    public array $albumArtworkUrls;

    /**
     * @param array<string,mixed>|null $metadata 插件最终认可的通用描述字段；未知字段与技术文件事实均禁止。
     */
    public function __construct(
        string $status,
        ?array $metadata = null,
        ?int $confidence = null,
        ?string $lyrics = null,
        ?string $artworkUrl = null,
        ?string $albumArtworkUrl = null,
        array $lyricsCandidates = [],
        array $artworkUrls = [],
        array $albumArtworkUrls = [],
    ) {
        if (!in_array($status, [self::MATCHED, self::UNMATCHED, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_STATUS_INVALID');
        }
        $this->status = $status;
        if ($status !== self::MATCHED) {
            if ($metadata !== null || $confidence !== null || $lyrics !== null || $artworkUrl !== null || $albumArtworkUrl !== null
                || $lyricsCandidates !== [] || $artworkUrls !== [] || $albumArtworkUrls !== []) {
                throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_NON_MATCHED_PAYLOAD');
            }
            $this->metadata = null;
            $this->confidence = null;
            $this->lyrics = null;
            $this->lyricsCandidates = [];
            $this->artworkUrl = null;
            $this->artworkUrls = [];
            $this->albumArtworkUrl = null;
            $this->albumArtworkUrls = [];
            return;
        }
        if (!is_array($metadata) || array_is_list($metadata) || !is_int($confidence) || $confidence < 85 || $confidence > 100) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_MATCHED_INVALID');
        }
        $this->metadata = self::metadata($metadata);
        $this->confidence = $confidence;
        $this->lyricsCandidates = self::lyricsList($lyricsCandidates, $lyrics);
        $this->lyrics = $this->lyricsCandidates[0] ?? null;
        $this->artworkUrls = self::urlList($artworkUrls, $artworkUrl);
        $this->artworkUrl = $this->artworkUrls[0] ?? null;
        $this->albumArtworkUrls = self::urlList($albumArtworkUrls, $albumArtworkUrl);
        $this->albumArtworkUrl = $this->albumArtworkUrls[0] ?? null;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private static function metadata(array $metadata): array
    {
        if (array_diff(array_keys($metadata), self::METADATA_FIELDS) !== []
            || !is_string($metadata['title'] ?? null) || !is_array($metadata['artists'] ?? null)) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        $result = [
            'title' => self::text($metadata['title'], 500),
            'artists' => self::texts($metadata['artists'], 20, 300),
        ];
        foreach (['albumTitle' => 500, 'composer' => 300, 'isrc' => 32, 'musicbrainzTrackId' => 64,
            'musicbrainzArtistId' => 64, 'musicbrainzReleaseId' => 64, 'musicbrainzReleaseGroupId' => 64] as $field => $maximum) {
            if (!array_key_exists($field, $metadata)) continue;
            if (!is_string($metadata[$field])) throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            $result[$field] = self::text($metadata[$field], $maximum);
        }
        if (isset($result['isrc']) && preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D', $result['isrc']) !== 1) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        foreach (['musicbrainzTrackId', 'musicbrainzArtistId', 'musicbrainzReleaseId', 'musicbrainzReleaseGroupId'] as $field) {
            if (isset($result[$field]) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $result[$field]) !== 1) {
                throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            }
        }
        foreach (['albumArtists' => [20, 300], 'genres' => [16, 100]] as $field => [$items, $maximum]) {
            if (!array_key_exists($field, $metadata)) continue;
            if (!is_array($metadata[$field])) throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            $result[$field] = self::texts($metadata[$field], $items, $maximum);
        }
        foreach (['trackNumber' => 9_999, 'trackTotal' => 9_999, 'discNumber' => 999, 'discTotal' => 999] as $field => $maximum) {
            if (!array_key_exists($field, $metadata)) continue;
            if (!is_int($metadata[$field]) || $metadata[$field] < 1 || $metadata[$field] > $maximum) {
                throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            }
            $result[$field] = $metadata[$field];
        }
        if (isset($result['trackNumber'], $result['trackTotal']) && $result['trackNumber'] > $result['trackTotal']) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        if (isset($result['discNumber'], $result['discTotal']) && $result['discNumber'] > $result['discTotal']) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        if (array_key_exists('releaseDate', $metadata)) {
            if (!is_string($metadata['releaseDate']) || preg_match('/^(?:1\d{3}|2\d{3})(?:-(?:0[1-9]|1[0-2])(?:-(?:0[1-9]|[12]\d|3[01]))?)?$/D', $metadata['releaseDate']) !== 1) {
                throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            }
            if (strlen($metadata['releaseDate']) === 10) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $metadata['releaseDate']);
                if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $metadata['releaseDate']) {
                    throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
                }
            }
            $result['releaseDate'] = $metadata['releaseDate'];
        }
        return $result;
    }

    /** @param list<mixed> $values @return list<string> */
    private static function texts(array $values, int $maximumItems, int $maximumCharacters): array
    {
        if (!array_is_list($values) || $values === [] || count($values) > $maximumItems) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
            $text = self::text($value, $maximumCharacters);
            $result[mb_strtolower($text, 'UTF-8')] ??= $text;
        }
        return array_values($result);
    }

    private static function text(string $value, int $maximumCharacters): string
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximumCharacters) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_METADATA_INVALID');
        }
        return $value;
    }

    private static function lyrics(?string $lyrics): ?string
    {
        if ($lyrics === null) return null;
        if ($lyrics === '' || strlen($lyrics) > 1_048_576 || !mb_check_encoding($lyrics, 'UTF-8')) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_LYRICS_INVALID');
        }
        return $lyrics;
    }

    private static function artworkUrl(?string $artworkUrl): ?string
    {
        if ($artworkUrl === null) return null;
        if ($artworkUrl === '' || strlen($artworkUrl) > 2_000 || filter_var($artworkUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($artworkUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_ARTWORK_INVALID');
        }
        return $artworkUrl;
    }

    /**
     * 规范化插件已经完成身份收敛的歌词候选。
     *
     * 列表最多保留九个固定渠道结果并按正文摘要去重；旧单值放在首位以维持滚动升级兼容。这里只校验
     * 协议大小和编码，LRC 结构由核心领域对象逐项验证，某一项失败不能阻止后续候选回退。
     *
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function lyricsList(array $values, ?string $legacy): array
    {
        if (!array_is_list($values) || count($values) > 9) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_LYRICS_INVALID');
        }
        $result = [];
        foreach (array_merge($legacy === null ? [] : [$legacy], $values) as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_LYRICS_INVALID');
            $value = self::lyrics($value);
            $result[hash('sha256', $value)] ??= $value;
        }
        return array_values($result);
    }

    /** @param list<mixed> $values @return list<string> */
    private static function urlList(array $values, ?string $legacy): array
    {
        if (!array_is_list($values) || count($values) > 9) {
            throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_ARTWORK_INVALID');
        }
        $result = [];
        foreach (array_merge($legacy === null ? [] : [$legacy], $values) as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('METADATA_SCRAPE_RESULT_ARTWORK_INVALID');
            $value = self::artworkUrl($value);
            $result[$value] = $value;
        }
        return array_values($result);
    }
}
