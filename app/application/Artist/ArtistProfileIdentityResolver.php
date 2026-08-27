<?php

declare(strict_types=1);

namespace app\application\Artist;

/** 为艺人资料任务提供不联网的唯一身份解析边界。 */
interface ArtistProfileIdentityResolver
{
    /**
     * 返回名称唯一对应的 MusicBrainz 身份；无库、无匹配或同名歧义均返回 null，禁止猜测。
     */
    public function resolve(string $artistName): ?ArtistProfileIdentity;
}
