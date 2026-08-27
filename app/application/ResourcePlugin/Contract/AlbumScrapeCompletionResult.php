<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * AlbumScrapeCompletionResult 是插件向核心交付的单一专辑最终结论。
 *
 * 插件内部可以查询多个来源，但核心只接收已经完成身份确认和字段取舍的一条结果；结果不包含候选列表、
 * 平台私有 ID、原始响应或请求地址。核心负责字段来源优先级、事务、封面下载校验和资源发布。
 */
final readonly class AlbumScrapeCompletionResult
{
    public const MATCHED = 'matched';
    public const UNMATCHED = 'unmatched';
    public const UNAVAILABLE = 'unavailable';

    /** @var self::MATCHED|self::UNMATCHED|self::UNAVAILABLE */
    public string $status;

    /** @var null|array<string,mixed> */
    public ?array $metadata;

    public ?int $confidence;
    public ?string $artworkUrl;
    /** @var list<string> 同一专辑身份下按可信度排序的封面地址。 */
    public array $artworkUrls;

    /** @param array<string,mixed>|null $metadata 最终专辑字段，不得包含歌曲技术事实或未知键。 */
    public function __construct(string $status, ?array $metadata = null, ?int $confidence = null, ?string $artworkUrl = null, array $artworkUrls = [])
    {
        if (!in_array($status, [self::MATCHED, self::UNMATCHED, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_STATUS_INVALID');
        }
        if ($status !== self::MATCHED) {
            if ($metadata !== null || $confidence !== null || $artworkUrl !== null || $artworkUrls !== []) {
                throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_NON_MATCHED_PAYLOAD');
            }
            $this->status = $status;
            $this->metadata = null;
            $this->confidence = null;
            $this->artworkUrl = null;
            $this->artworkUrls = [];
            return;
        }
        if (!is_array($metadata) || array_is_list($metadata) || !is_int($confidence) || $confidence < 85 || $confidence > 100) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_MATCHED_INVALID');
        }
        $allowed = ['title', 'albumArtists', 'releaseDate', 'discTotal', 'musicbrainzReleaseId', 'musicbrainzReleaseGroupId'];
        if (array_diff(array_keys($metadata), $allowed) !== [] || !is_string($metadata['title'] ?? null)
            || !is_array($metadata['albumArtists'] ?? null) || $metadata['albumArtists'] === []) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
        }
        $normalized = [
            'title' => $this->text($metadata['title'], 500),
            'albumArtists' => $this->texts($metadata['albumArtists']),
        ];
        foreach (['releaseDate' => 10, 'musicbrainzReleaseId' => 36, 'musicbrainzReleaseGroupId' => 36] as $field => $length) {
            if (!array_key_exists($field, $metadata)) continue;
            if (!is_string($metadata[$field]) || trim($metadata[$field]) === '' || strlen(trim($metadata[$field])) > $length) {
                throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
            }
            $value = trim($metadata[$field]);
            if ($field === 'releaseDate') {
                if (preg_match('/^(?:1\d{3}|2\d{3})(?:-(?:0[1-9]|1[0-2])(?:-(?:0[1-9]|[12]\d|3[01]))?)?$/D', $value) !== 1
                    || (strlen($value) === 10 && (($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) === false
                        || $date->format('Y-m-d') !== $value))) {
                    throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
                }
            } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', strtolower($value)) !== 1) {
                throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
            }
            $normalized[$field] = $field === 'releaseDate' ? $value : strtolower($value);
        }
        if (array_key_exists('discTotal', $metadata)) {
            if (!is_int($metadata['discTotal']) || $metadata['discTotal'] < 1 || $metadata['discTotal'] > 999) {
                throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
            }
            $normalized['discTotal'] = $metadata['discTotal'];
        }
        $this->status = self::MATCHED;
        $this->metadata = $normalized;
        $this->confidence = $confidence;
        if (!array_is_list($artworkUrls) || count($artworkUrls) > 9) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_ARTWORK_INVALID');
        }
        $urls = [];
        foreach (array_merge($artworkUrl === null ? [] : [$artworkUrl], $artworkUrls) as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_ARTWORK_INVALID');
            $value = $this->url($value);
            $urls[$value] = $value;
        }
        $this->artworkUrls = array_values($urls);
        $this->artworkUrl = $this->artworkUrls[0] ?? null;
    }

    private function text(string $value, int $maximum): string
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
        }
        return $value;
    }

    /** @param list<mixed> $values @return list<string> */
    private function texts(array $values): array
    {
        if (!array_is_list($values) || count($values) > 20) throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_METADATA_INVALID');
            $text = $this->text($value, 300);
            $result[mb_strtolower($text, 'UTF-8')] ??= $text;
        }
        return array_values($result);
    }

    private function url(string $value): string
    {
        if (strlen($value) > 2_000 || filter_var($value, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_RESULT_ARTWORK_INVALID');
        }
        return $value;
    }
}
