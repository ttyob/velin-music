<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 汇总一次刮削 Provider 的最终候选、可选歌词和瞬时封面定位。
 *
 * 插件 Provider 的 `channels` 必须只包含一条已经在插件内部完成多来源取舍的插件结果；核心不得
 * 根据插件内部来源重新比较或拼接。插件不可用时只允许携带本地文件元数据和一个 unavailable 插件
 * 状态，不得携带核心固定平台明细。本对象生命周期限于一次 Worker 分析，不直接执行数据库或文件操作。
 */
final readonly class MetadataEnrichmentResult
{
    /**
     * @param list<MetadataProviderResult> $channels 包含本地来源并保持管理员配置的实际查询优先级。
     * @param ScrapeGeneratedLyrics|null $generatedLyrics 达到可靠阈值的平台歌词；仅供库内逐曲刮削发布文件。
     * @param list<ScrapeGeneratedLyrics> $generatedLyricsCandidates 按匹配分和渠道顺序排列、正文去重后的
     *        全部可靠歌词。库内同步只发布最高分首选，目录整理链路不持久化平台歌词正文。
     * @param array<string,string> $fieldSources 最终描述字段对应的真实渠道键；本地技术事实始终标为 local。
     * @param list<ScrapeGeneratedArtwork> $generatedArtworkCandidates 只供同一次 Worker 下载尝试；其中 URL
     *        不得被序列化、持久化、审计或投影，普通文件整理不会消费该列表。
     * @param list<ScrapeGeneratedArtwork> $generatedAlbumArtworkCandidates 专辑封面候选；只由核心专辑图片
     *        Provider 消费，不与歌曲封面候选混用。
     */
    public function __construct(
        public ScrapeMetadataCandidate $finalCandidate,
        public array $channels,
        public ?ScrapeGeneratedLyrics $generatedLyrics = null,
        public array $generatedLyricsCandidates = [],
        public array $fieldSources = [],
        public array $generatedArtworkCandidates = [],
        public array $generatedAlbumArtworkCandidates = [],
    ) {
    }
}
