<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * RetryableExternalDownloadHook 为具有可恢复任务状态机的下载插件声明人工重试能力。
 *
 * 该接口是 `ExternalDownloadHook` 的可选扩展，避免强迫只支持一次性提交的插件伪造重试。核心负责实时
 * Session、`manage_system`、CSRF 和插件数据库版本校验；插件仍必须复验任务状态、目标音乐库权限及
 * 自有暂存所有权。重试只能复用服务端保存的引用，不能接受浏览器补交 URL、Header、路径或远端 ID。
 * 已成功任务、不可恢复的权限/安全错误和失去完整引用的任务必须失败关闭，不能创建重复入库副作用。
 */
interface RetryableExternalDownloadHook extends ExternalDownloadHook
{
    /**
     * 把一个允许恢复的失败任务重新排队并返回最新脱敏投影。
     *
     * @param array<string,mixed> $actor 核心从当前 Cookie Session 重建的实时管理员主体
     */
    public function retryDownload(string $jobId, array $actor, string $requestId): array;

    /**
     * 返回插件最近的脱敏下载诊断日志。
     *
     * 日志只能包含稳定事件码、阶段、状态码、内容类型、响应大小、结构摘要，以及已经删除 URL、凭证和
     * 长资源标识的固定上游错误标量；实现不得返回 Header 值、原始响应、任意 data 值、平台资源 ID、
     * musicInfo、密钥、Cookie 或物理路径。核心固定限制最大查询数量，页面不能借此接口扩大数据范围。
     */
    public function downloadDiagnosticLogs(int $limit = 100): array;

    /**
     * 清空插件自有下载诊断日志。
     *
     * 核心已验证实时管理员、CSRF 和插件数据库版本；插件必须在短事务内删除且写核心审计，清理日志不
     * 得改变下载任务、搜索租约、媒体文件或扫描历史。重复清理应返回删除数量 0，而不是制造错误。
     *
     * @param array<string,mixed> $actor 核心从当前 Cookie Session 重建的实时管理员主体
     */
    public function clearDownloadDiagnosticLogs(array $actor, string $requestId): array;

    /**
     * 删除一条精确的插件诊断日志。
     *
     * logId 只能是插件返回的不透明 ID；实现必须按主键短事务删除并写核心审计，不得接受表名、条件表达式
     * 或任务/文件操作参数。不存在记录应表现为冲突或不存在，不能误删其他日志。
     *
     * @param array<string,mixed> $actor 核心从当前 Cookie Session 重建的实时管理员主体
     */
    public function clearDownloadDiagnosticLog(string $logId, array $actor, string $requestId): array;

    /**
     * 删除一个明确处于终态的插件下载记录。
     *
     * 浏览器只能提交 opaque 任务 ID。实现必须实时复验目标库管理权限，只允许 succeeded 或 failed，
     * 并按任务所有权清理受控暂存后使用状态 CAS 删除数据库行；已发布媒体和核心扫描记录永远不在清理
     * 范围。活动任务、权限变化、未知暂存内容或并发状态变化必须失败关闭。
     *
     * @param array<string,mixed> $actor 核心从当前 Cookie Session 重建的实时管理员主体
     */
    public function clearDownloadJob(string $jobId, array $actor, string $requestId): array;
}
