<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalArtistArtworkHook 定义插件提供艺人资料图候选的最小边界。
 *
 * 插件只接收艺人名称和已经由核心冻结的代表歌曲署名证据；平台 ID、路径、URL 和原始响应必须留在
 * 插件内部。核心仍负责歌曲与艺人关系复验、图片下载/签名校验、许可归属、候选持久化和最终选择。
 */
interface ExternalArtistArtworkHook extends PhpResourcePlugin
{
    /** 返回插件内部完成来源选择的一条艺人资料图结论。 */
    public function scrapeArtistArtwork(ArtistArtworkScrapeRequest $request): ArtistArtworkScrapeResult;
}
