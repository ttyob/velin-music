<?php

declare(strict_types=1);

namespace app\process;

use Workerman\Worker;

/**
 * 在一个低频 Workerman 进程内调度通知保留和可重建运行时文件回收。
 *
 * 两类工作不处理用户请求、媒体扫描或第三方网络调用，并继续由原有任务类维护各自的删除边界：通知清理
 * 保留批次数与墙钟预算，运行维护保留固定目录和跨进程文件锁。共享的只是 Workerman 进程与事件循环，
 * 不共享任务状态或启停开关；任一子功能关闭时不会注册它的 Timer，也不会影响另一项。
 */
final class MaintenanceWorker
{
    private readonly NotificationCleanupWorker $notificationCleanup;
    private readonly RuntimeMaintenanceWorker $runtimeMaintenance;

    public function __construct(
        private readonly bool $notificationCleanupEnabled = true,
        float $notificationCleanupInterval = 300.0,
        int $notificationCleanupBatchSize = 500,
        int $notificationCleanupMaxBatchesPerTick = 8,
        float $notificationCleanupTimeBudgetSeconds = 2.0,
        private readonly bool $runtimeMaintenanceEnabled = true,
        float $runtimeMaintenanceInterval = 21_600.0,
    ) {
        $this->notificationCleanup = new NotificationCleanupWorker(
            $notificationCleanupInterval,
            $notificationCleanupBatchSize,
            $notificationCleanupMaxBatchesPerTick,
            $notificationCleanupTimeBudgetSeconds,
        );
        $this->runtimeMaintenance = new RuntimeMaintenanceWorker($runtimeMaintenanceInterval);
    }

    /** 按各自原有首次延迟与周期注册低频任务，启动阶段不会同时争抢 SQLite 写锁。 */
    public function onWorkerStart(Worker $worker): void
    {
        if ($this->notificationCleanupEnabled) {
            $this->notificationCleanup->onWorkerStart($worker);
        }
        if ($this->runtimeMaintenanceEnabled) {
            $this->runtimeMaintenance->onWorkerStart($worker);
        }
    }

    /** 将优雅停止边界传播给已注册的任务，防止共享进程停止时领取新的清理批次。 */
    public function onWorkerStop(Worker $worker): void
    {
        if ($this->notificationCleanupEnabled) {
            $this->notificationCleanup->onWorkerStop($worker);
        }
        if ($this->runtimeMaintenanceEnabled) {
            $this->runtimeMaintenance->onWorkerStop($worker);
        }
    }
}
