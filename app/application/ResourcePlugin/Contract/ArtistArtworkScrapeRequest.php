<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * 艺人资料图插件请求，只携带无路径的代表歌曲证据。
 *
 * 代表歌曲必须真实署名目标艺人；调用方在构造前负责从已授权歌曲实体冻结这些字段。对象没有网络、
 * 数据库或文件副作用，插件不能从中推导任意 URL 或平台私有 ID。
 */
final readonly class ArtistArtworkScrapeRequest
{
    /** @param list<string> $representativeArtists */
    public function __construct(
        public string $artist,
        public string $representativeTitle,
        public array $representativeArtists,
        public ?string $representativeAlbum = null,
        public ?int $representativeDurationMs = null,
    ) {
        $this->text($artist, 'ARTIST');
        $this->text($representativeTitle, 'TITLE');
        if (!array_is_list($representativeArtists) || $representativeArtists === []) {
            throw new InvalidArgumentException('ARTIST_ARTWORK_REQUEST_ARTISTS_INVALID');
        }
        foreach ($representativeArtists as $value) $this->text($value, 'ARTISTS');
        if ($representativeAlbum !== null) $this->text($representativeAlbum, 'ALBUM');
        if ($representativeDurationMs !== null && ($representativeDurationMs < 0 || $representativeDurationMs > 86_400_000)) {
            throw new InvalidArgumentException('ARTIST_ARTWORK_REQUEST_DURATION_INVALID');
        }
    }

    private function text(string $value, string $field): void
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
            throw new InvalidArgumentException('ARTIST_ARTWORK_REQUEST_' . $field . '_INVALID');
        }
    }
}
