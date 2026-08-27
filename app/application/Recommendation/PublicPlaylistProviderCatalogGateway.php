<?php

declare(strict_types=1);

namespace app\application\Recommendation;

/**
 * 为公开歌单推荐服务提供插件声明的平台目录。
 *
 * 该接口把展示名称和登录需求也交给插件声明；核心不维护第三方平台枚举。目录只允许脱敏 provider
 * key，具体歌单条目仍通过 PublicPlaylistCatalogGateway 返回并在核心匹配。
 */
interface PublicPlaylistProviderCatalogGateway extends PublicPlaylistCatalogGateway
{
    /** @return list<array{key:string,name:string,loginRequired:bool}> */
    public function providers(): array;
}
