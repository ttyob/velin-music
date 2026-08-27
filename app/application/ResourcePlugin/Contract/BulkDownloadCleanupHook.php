<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * BulkDownloadCleanupHook 为下载插件声明批量清理终态记录能力。
 *
 * 这是 RetryableExternalDownloadHook 的可选扩展，避免一次性或只支持单条清理的插件被迫实现批量
 * 删除。实现必须自行复验管理员可管理的目标库，保留活动任务、已发布媒体、核心扫描记录和审计，
 * 并按任务所有权清理受控暂存；请求正文不允许携带筛选条件或物理路径。
 */
interface BulkDownloadCleanupHook extends RetryableExternalDownloadHook
{
    /**
     * 清理当前管理员范围内全部 succeeded/failed 下载记录。
     *
     * @param array<string,mixed> $actor 核心从当前 Cookie Session 重建的实时管理员主体
     * @return array{deletedCount:int,activeCount:int}
     */
    public function clearDownloadJobs(array $actor, string $requestId): array;
}
