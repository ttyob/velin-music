<?php

declare(strict_types=1);

namespace app\application\Artist;

/**
 * 表示由本地只读艺人库或固定 MusicBrainz 搜索唯一确认的外部身份。
 *
 * 该值不授予媒体访问权限，也不表示简介已经存在；调用方只能在艺人实体已经由业务库确认后使用。
 */
final readonly class ArtistProfileIdentity
{
    public function __construct(
        public string $musicBrainzId,
        public ?string $chineseName,
        public ?string $originalName,
        public ?string $countryCode,
    ) {
    }
}
