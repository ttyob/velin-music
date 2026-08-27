<?php

declare(strict_types=1);

namespace app\application\Recommendation;

/**
 * 查询无需登录的第三方热门歌单目录。
 *
 * 实现必须在受控插件边界内完成平台请求和详情解析，只能返回稳定来源摘要、展示文本以及本地匹配
 * 证据。不得把 Cookie、平台歌曲 ID、公开链接、播放地址或原始响应交给推荐领域服务；上游失败应抛出
 * PublicPlaylistCatalogUnavailable，使调用方保留上次成功结果。
 */
interface PublicPlaylistCatalogGateway
{
    /**
     * @param string $provider 由插件 `playlistProviders()` 声明的稳定来源 key。
     * @return list<array{key:string,title:string,description:string,entries:list<array{title:string,artists:list<string>,album:?string,durationMs:?int}>}>
     */
    public function catalog(string $provider): array;
}
