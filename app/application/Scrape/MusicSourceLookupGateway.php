<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 定义 Velin Music 刮削 Worker 调用内置平台查询执行器的最小端口。
 *
 * 实现只接受无路径歌曲证据和固定平台键；不得接收浏览器 URL、认证材料或媒体文件位置。网络请求必须
 * 位于 SQLite 事务之外，单个平台失败应形成可审核状态而不是终止其他平台。
 */
interface MusicSourceLookupGateway
{
    /**
     * @param array{title:string,artists:list<string>,album:?string,albumId?:?string,durationMs:?int,isrc:?string,locale:string,region:string,filenameEvidence?:bool,filenameArtistRequired?:bool} $query
     * @param list<string> $keywords
     * @param list<string> $sources
     * @return list<array<string,mixed>> 每个平台一条有界结果，保持输入顺序。
     */
    public function lookup(array $query, array $keywords, array $sources): array;
}
