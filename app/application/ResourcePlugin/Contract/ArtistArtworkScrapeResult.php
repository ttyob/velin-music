<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * 艺人资料图插件的单一最终结论。
 *
 * URL 只在当前调用栈短暂存在，核心收到后必须重新执行固定 CDN/HTTPS/图片事实校验；插件不得把候选
 * 列表、平台 ID 或原始响应夹带在结果中。非 matched 状态不能携带任何资源字段。
 */
final readonly class ArtistArtworkScrapeResult
{
    public const MATCHED = 'matched';
    public const UNMATCHED = 'unmatched';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public ?string $artistName = null,
        public ?string $artworkUrl = null,
        public ?int $confidence = null,
    ) {
        if (!in_array($status, [self::MATCHED, self::UNMATCHED, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('ARTIST_ARTWORK_RESULT_STATUS_INVALID');
        }
        if ($status !== self::MATCHED) {
            if ($artistName !== null || $artworkUrl !== null || $confidence !== null) {
                throw new InvalidArgumentException('ARTIST_ARTWORK_RESULT_NON_MATCHED_PAYLOAD');
            }
            return;
        }
        if ($artistName === null || trim($artistName) === '' || mb_strlen(trim($artistName), 'UTF-8') > 500
            || $artworkUrl === null || trim($artworkUrl) === '' || $confidence === null
            || $confidence < 85 || $confidence > 100 || strlen($artworkUrl) > 2_000
            || filter_var($artworkUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($artworkUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('ARTIST_ARTWORK_RESULT_MATCHED_INVALID');
        }
    }
}
