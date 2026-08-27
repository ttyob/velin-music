<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use InvalidArgumentException;

/**
 * ArtistProfileScrapeRequest 是核心交给艺人资料插件的最小身份请求。
 *
 * 核心只传递已经物化的艺人显示名和可选的本地 MBID；插件负责所有 MusicBrainz、Wikidata 与
 * Wikipedia 查询。请求不携带歌曲路径、任务 ID、数据库连接、代理或凭据，构造失败发生在任何网络
 * 或文件副作用之前。已知 MBID 只能作为身份提示，插件仍须向 MusicBrainz 复验返回身份。
 */
final readonly class ArtistProfileScrapeRequest
{
    private const MAX_NAME_CHARACTERS = 255;

    public string $artistName;
    public ?string $musicBrainzId;

    /**
     * @param string $artistName 本地已确认艺人实体的显示名。
     * @param ?string $musicBrainzId 本地辅助库或既有资料提供的规范 UUID；缺失时由插件按名称严格解析。
     */
    public function __construct(string $artistName, ?string $musicBrainzId = null)
    {
        $artistName = trim($artistName);
        if ($artistName === '' || !mb_check_encoding($artistName, 'UTF-8')
            || mb_strlen($artistName, 'UTF-8') > self::MAX_NAME_CHARACTERS) {
            throw new InvalidArgumentException('ARTIST_PROFILE_REQUEST_NAME_INVALID');
        }
        if ($musicBrainzId !== null) {
            $musicBrainzId = strtolower(trim($musicBrainzId));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $musicBrainzId) !== 1) {
                throw new InvalidArgumentException('ARTIST_PROFILE_REQUEST_MBID_INVALID');
            }
        }
        $this->artistName = $artistName;
        $this->musicBrainzId = $musicBrainzId;
    }
}
