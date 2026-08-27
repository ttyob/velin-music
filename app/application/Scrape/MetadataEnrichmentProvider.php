<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * MetadataEnrichmentProvider 定义逐曲刮削 Worker 获取一次完整补全结论的最小边界。
 *
 * 核心状态机只需要知道本地冻结证据经过一次外部补全后得到的最终结果，不应依赖具体平台、插件目录或
 * Helper 进程。实现必须在 SQLite 事务外执行所有网络或子进程工作；返回值中的歌词和封面定位仍由核心
 * 按既有安全规则发布。插件不可用时只能返回已冻结的本地文件元数据，不得由实现自行接管固定平台查询。
 * selectedSources 仅服务旧人工确认流程，插件 Provider 不得把它解释为浏览器可控的平台参数。
 */
interface MetadataEnrichmentProvider
{
    /**
     * 使用冻结的本地证据获得本轮最终补全结果。
     *
     * @param list<string> $queryKeywords 服务端生成且有界的查询关键词。
     * @param null|list<string> $selectedSources 已确认的服务端渠道键；null 表示首次自动查询。
     */
    public function enrich(
        ScrapeMetadataCandidate $candidate,
        array $queryKeywords,
        ?string $attemptIdentity = null,
        ?array $selectedSources = null,
    ): MetadataEnrichmentResult;
}
