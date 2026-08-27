<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * AlbumScrapeCompletionRequest 是核心交给插件的最小专辑身份请求。
 *
 * 核心只传递已经物化的专辑名称、专辑艺人和可选发行身份；插件负责第三方查询、评分和最终取舍。
 * 请求不携带歌曲路径、文件标签、数据库连接、任务 ID、代理或凭据，构造失败发生在任何网络副作用之前。
 */
final readonly class AlbumScrapeCompletionRequest
{
    /** @param list<string> $albumArtists */
    public function __construct(
        public string $albumTitle,
        public array $albumArtists,
        public ?string $musicBrainzReleaseId = null,
        public ?string $musicBrainzReleaseGroupId = null,
    ) {
        $this->text($albumTitle, 'ALBUM_TITLE');
        if (!array_is_list($albumArtists) || $albumArtists === [] || count($albumArtists) > 20) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_REQUEST_ARTISTS_INVALID');
        }
        foreach ($albumArtists as $artist) $this->text($artist, 'ALBUM_ARTIST');
        foreach (['musicBrainzReleaseId' => $musicBrainzReleaseId, 'musicBrainzReleaseGroupId' => $musicBrainzReleaseGroupId] as $field => $value) {
            if ($value !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', strtolower(trim($value))) !== 1) {
                throw new InvalidArgumentException('ALBUM_SCRAPE_REQUEST_' . strtoupper($field) . '_INVALID');
            }
        }
    }

    private function text(string $value, string $field): void
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
            throw new InvalidArgumentException('ALBUM_SCRAPE_REQUEST_' . $field . '_INVALID');
        }
    }
}
