<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 表示一个元数据 Provider 对一次查询的结果，供编排器持久化和审核界面展示。
 *
 * 插件 Provider 必须先在包内完成多来源取舍；本地 Provider 只表示扫描阶段已经读取的音频文件事实。
 * 只返回一条最终候选。`matched` 必须携带该 Provider 的候选；`unmatched` 表示正常响应但没有满足
 * 保守评分的结果；`unavailable` 合并网络、配置和适配器故障，避免第三方异常或秘密进入数据库/API。
 * `hasLyrics` 只表示同一候选附带的歌词已通过匹配分、UTF-8、大小和结构解析校验；`hasArtwork` 只表示
 * 候选有通过来源、身份和定位校验的封面，尚不代表图片已下载或入库。该值对象不包含物理路径、歌词正文、
 * 封面 URL 和原始响应，能够跨 Worker 与管理查询边界安全序列化。
 */
final readonly class MetadataProviderResult
{
    /**
     * @param non-empty-string $channelKey 稳定的 Provider 键；插件结果使用插件 key，不使用内部平台键。
     * @param non-empty-string $displayName 管理界面可显示的 Provider 名称。
     * @param 'matched'|'unmatched'|'unavailable' $status Provider 的最终状态。
     * @param ScrapeProviderDiagnostics|null $diagnostics 无原始响应、可持久化的本次查询执行摘要。
     * @param bool $hasLyrics 该渠道是否提供通过全部准入校验的歌词；非匹配渠道必须为 false。
     * @param bool $hasArtwork 该渠道是否提供通过准入校验的封面定位；不承诺后续下载成功。
     */
    public function __construct(
        public string $channelKey,
        public string $displayName,
        public string $status,
        public ?ScrapeMetadataCandidate $candidate,
        public ?ScrapeProviderDiagnostics $diagnostics = null,
        public bool $hasLyrics = false,
        public bool $hasArtwork = false,
    ) {
    }
}
