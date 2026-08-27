<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalMetadataScrapePluginRegistry 隔离核心刮削 Worker 与 PHP 插件发现细节。
 *
 * Worker 只能依据脱敏插件投影选择约定的 metadata-scrape 包，并在每次调用前通过本接口重新验证活动标记、
 * manifest 能力、PHP 接口和插件数据库版本。插件升级、停用或损坏时接口必须失败关闭；歌曲元数据调用方
 * 歌曲插件不可用时只能由核心保留已经读取的本地文件元数据；专辑和艺人资料任务则将插件不可用记录为
 * 各自可重试失败。不得持有过期插件实例或从任意类名反射调用。
 */
interface ExternalMetadataScrapePluginRegistry
{
    /** @return list<array<string,mixed>> 当前插件的脱敏即时快照。 */
    public function list(): array;

    /** 返回已经通过能力、接口和生命周期校验的最终刮削钩子。 */
    public function metadataScrape(string $key): ExternalMetadataScrapeHook;

    /** 返回已经通过能力、接口和生命周期校验的专辑刮削钩子。 */
    public function metadataAlbum(string $key): ExternalAlbumScrapeHook;

    /** 返回声明并实现艺人资料图能力的同包插件钩子。 */
    public function metadataArtistArtwork(string $key): ExternalArtistArtworkHook;

    /** 返回声明并实现艺人文字资料能力的同包插件钩子。 */
    public function metadataArtistProfile(string $key): ExternalArtistProfileHook;
}
