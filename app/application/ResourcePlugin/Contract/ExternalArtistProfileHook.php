<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalArtistProfileHook 定义艺人文字资料的完整第三方插件边界。
 *
 * 插件独占 MusicBrainz、Wikidata 和 Wikipedia 的网络访问、速率限制、身份确认及字段解析；核心只负责
 * 传入已授权艺人实体并将最终结果写入业务库。插件不得修改核心数据库、媒体文件或任务状态，也不得把
 * 原始响应、候选、请求地址、凭据和平台内部诊断返回给核心。
 */
interface ExternalArtistProfileHook extends PhpResourcePlugin
{
    /** 返回插件已完成身份复验和资料聚合的一条最终结论。 */
    public function scrapeArtistProfile(ArtistProfileScrapeRequest $request): ArtistProfileScrapeResult;
}
