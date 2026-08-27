<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalDownloadHook 定义外部搜索租约到受控音乐库入库任务的钩子。
 *
 * 下载命令只能消费当前 actor 的未过期服务端租约，并只能选择其可管理的 active 可写音乐库。插件不得
 * 接受浏览器提交的 URL、磁力、torrent、InfoHash、物理目录、分类或任意下载器参数。任务创建必须耐久、
 * 对同一租约幂等；插件卸载后历史投影保留，但核心不继续执行其网络或文件副作用。
 */
interface ExternalDownloadHook extends PhpResourcePlugin
{
    /** 返回下载器脱敏配置和即时状态。 */
    public function downloadStatus(): array;

    /** 返回当前版本的下载器脱敏配置。 */
    public function downloadConfiguration(): array;

    /**
     * 使用版本锁更新下载器配置，秘密字段只写不读。
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $actor
     */
    public function updateDownloadConfiguration(array $payload, array $actor, string $requestId): array;

    /** @param array<string,mixed> $actor */
    public function downloadOptions(array $actor): array;

    /**
     * 从服务端租约创建一条下载任务。
     *
     * 实现必须至少接受精确的 `leaseId|libraryId` 两字段命令，供核心统一 API 调用；插件私有后台可在自身
     * 固定白名单内增加字段，但不能要求统一 API 透传。省略的音质、入库方式等必须来自租约或服务端配置。
     * 同一租约重复提交返回同一任务，不能重复创建远端下载或文件发布副作用。
     *
     * @param array<string,mixed> $command
     * @param array<string,mixed> $actor
     */
    public function createDownload(array $command, array $actor, string $requestId): array;

    /** 返回不含资源引用、远端 hash、Cookie 和内容路径的最近任务投影。 */
    public function downloadJobs(int $limit = 50): array;
}
