<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 承载一次元数据同步查询中可尝试下载的歌曲封面定位信息。
 *
 * 该对象只能存在于刮削 Worker 的内存中：URL 来自受控固定渠道或在包内完成优选的 `metadata-scrape`
 * 插件，后续仍须经过固定 CDN、公开 DNS、HTTPS（HTTP 来源地址会在该边界升级）、响应大小和图片签名校验。对象不得序列化到任务、候选摘要、审计或日志；下载成功后
 * 只允许保存规范化图片、平台键和不可逆资源摘要。分数是准入排序依据而非概率，低于 85 的候选不能
 * 构造本对象。构造失败没有数据库或文件副作用。
 */
final readonly class ScrapeGeneratedArtwork
{
    private const SOURCES = ['netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music', 'musicbrainz', 'metadata-scrape'];

    public function __construct(
        public string $source,
        public string $url,
        public int $score,
    ) {
        if (!in_array($source, self::SOURCES, true) || trim($url) === '' || strlen($url) > 2_000
            || $score < ScrapeApprovalPolicy::MINIMUM_SCORE || $score > 100) {
            throw new ScrapeMetadataInvalid('平台封面候选无效。');
        }
    }
}
