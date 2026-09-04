<?php

declare(strict_types=1);

namespace app\process;

use app\application\Notification\NotificationRetentionService;
use app\application\Scan\ScanHistoryRetentionService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 在 HTTP 与扫描 Worker 之外执行有界的通知及实时事件保留清理。
 *
 * 单进程按低频周期唤醒，在固定批次数和墙钟预算内重复执行短事务。每批都重新选择仍过期的主键并通过
 * SQLite 写闸门提交；达到空批次、停止信号或任一预算便退出，不会为了清空历史积压长期垄断写锁。
 * 未完成部分保留在数据库中供后续 tick 重试，进程停止不丢失任何内存状态。
 */
final class NotificationCleanupWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 300.0,
        private readonly int $batchSize = 500,
        private readonly int $maxBatchesPerTick = 8,
        private readonly float $timeBudgetSeconds = 2.0,
        private readonly NotificationRetentionService $retention = new NotificationRetentionService(),
        private readonly ScanHistoryRetentionService $scanRetention = new ScanHistoryRetentionService(),
    ) {
    }

    /** Schedules one post-start cleanup and recurring low-frequency batches on the event loop. */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(30.0, [$this, 'tick'], [], false);
        Timer::add(max(60.0, $this->interval), [$this, 'tick']);
    }

    /** Prevents a new retention transaction after Workerman starts graceful shutdown. */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /**
     * 在有界预算内推进积压并始终释放进程上下文。
     *
     * 每次 prune 都是独立事务，因此中途异常只回滚当前批次，之前已提交的删除无需补偿且可安全重入。
     * 日志只输出聚合计数，不包含通知、账号或事件正文。
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) {
            return;
        }
        $this->busy = true;
        try {
            $startedAt = microtime(true);
            $notifications = 0;
            $events = 0;
            $batches = 0;
            $scanJobs = 0;
            $scanFiles = 0;
            do {
                $deleted = $this->retention->prune(gmdate('Y-m-d\TH:i:s\Z'), $this->batchSize);
                $scanResult = $this->scanRetention->pruneOne();
                $notifications += $deleted['notifications'];
                $events += $deleted['realtimeEvents'];
                $scanJobs += $scanResult['jobs'];
                $scanFiles += $scanResult['fileResults'];
                ++$batches;
                $empty = $deleted['notifications'] === 0 && $deleted['realtimeEvents'] === 0
                    && $scanResult['jobs'] === 0;
            } while (!$empty && !$this->stopping && $batches < $this->maxBatchesPerTick
                && microtime(true) - $startedAt < $this->timeBudgetSeconds);
            if ($notifications > 0 || $events > 0) {
                Log::info('Expired notification state pruned.', [
                    'notification_count' => $notifications,
                    'realtime_event_count' => $events,
                    'batch_count' => $batches,
                ]);
            }
            if ($scanJobs > 0) {
                Log::info('Expired scan file details pruned.', [
                    'job_count' => $scanJobs,
                    'file_result_count' => $scanFiles,
                ]);
            }
        } catch (Throwable $throwable) {
            Log::error('Notification retention tick failed.', [
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
