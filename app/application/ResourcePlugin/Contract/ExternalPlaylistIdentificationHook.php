<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

use app\application\Playlist\PlatformPlaylistDocument;

/**
 * ExternalPlaylistIdentificationHook 定义公开平台歌单链接识别的插件边界。
 *
 * 插件负责平台 URL 主机校验、网络请求、凭据和平台字段转换；核心只接收脱敏的统一文档，继续负责
 * 本地媒体授权匹配、未匹配报告、幂等键和数据库事务。实现失败必须抛出稳定的 PlaylistLinkInvalid 或
 * PlaylistLinkUnavailable，不能调用核心旧 Helper 形成静默回退。
 */
interface ExternalPlaylistIdentificationHook extends PhpResourcePlugin
{
    /**
     * 返回可识别的来源声明；识别来源可以多于可同步官方榜单来源。
     *
     * @return list<array{key:string,name:string}>
     */
    public function playlistSources(): array;

    /**
     * 识别一个平台公开歌单链接。
     *
     * 返回文档不得包含平台 URL、私有 ID、Cookie、Token、播放地址或原始响应；条目顺序是导入业务事实，
     * 时长统一为毫秒。方法没有数据库和媒体文件副作用，网络或协议失败由实现映射为稳定异常。
     *
     * @throws \app\application\Playlist\PlaylistLinkInvalid 链接不符合插件声明的平台规则。
     * @throws \app\application\Playlist\PlaylistLinkUnavailable 平台请求、Helper 或响应协议失败。
     */
    public function identifyPlaylist(string $source, string $link): PlatformPlaylistDocument;
}
