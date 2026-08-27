<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use app\application\Playlist\PlatformPlaylistDocument;

/**
 * ExternalPlaylistSyncHook 定义平台歌单目录和远端同步的插件边界。
 *
 * 核心定义权限、匹配、版本锁、失败保留旧内容和审计规则；插件负责平台目录、登录配置、限流以及
 * 平台私有字段转换。catalog 的 key 只能是不可逆摘要，syncPlaylist 返回的文档仍不得包含平台私有数据。
 */
interface ExternalPlaylistSyncHook extends PhpResourcePlugin
{
    /**
     * 返回插件可用的平台目录声明。
     *
     * 返回值只允许稳定 key、展示名称和是否需要登录，不能泄漏第三方 URL、账号或令牌。目录读取不写业务库。
     *
     * @return list<array{key:string,name:string,loginRequired:bool}>
     */
    public function playlistProviders(): array;

    /**
     * 查询一个平台的脱敏歌单目录。
     *
     * 目录条目只包含摘要和匹配证据；上游失败必须抛出 PublicPlaylistCatalogUnavailable，调用方不得用空结果
     * 覆盖上次成功同步结果。
     *
     * @return list<array{key:string,title:string,description:string,entries:list<array{title:string,artists:list<string>,album:?string,durationMs:?int}>}>
     * @throws \app\application\Recommendation\PublicPlaylistCatalogUnavailable
     */
    public function playlistCatalog(string $source): array;

    /**
     * 按目录 key 读取一份远端歌单的最新内容。
     *
     * key 必须来自同一插件之前返回的目录；实现仍需重新校验来源和远端响应，失败不产生核心写入副作用。
     *
     * @throws \app\application\Recommendation\PublicPlaylistCatalogUnavailable
     */
    public function syncPlaylist(string $source, string $playlistKey): PlatformPlaylistDocument;
}
