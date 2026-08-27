<?php

declare(strict_types=1);

namespace app\process;

use app\application\System\RuntimeMaintenanceService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 低频回收主程序拥有的过期运行时文件。
 *
 * Worker 与后台手动按钮复用 RuntimeMaintenanceService 的封闭目录和命名策略，不接受路径配置，也不访问
 * 媒体库、插件工作区或业务数据库。进程内 busy 防止计时器重入，服务内 flock 防止跨进程并发；锁忙时
 * 本轮跳过并等待下次周期。删除对象均可重建，失败只保留文件并写一次脱敏汇总，不影响 HTTP 或媒体任务。
 */
final class RuntimeMaintenanceWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 21_600.0,
        private readonly RuntimeMaintenanceService $maintenance = new RuntimeMaintenanceService(),
    ) {
    }

    /** 启动一分钟后执行首次清理，之后按不短于一小时的低频周期运行。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(60.0, [$this, 'tick'], [], false);
        Timer::add(max(3_600.0, $this->interval), [$this, 'tick']);
    }

    /** 优雅退出开始后不再领取新一轮文件清理。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 执行一次有界策略扫描，并只记录数量和字节数。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $result = $this->maintenance->cleanupAutomatically();
            if ($result !== null && ($result['deletedFileCount'] > 0 || $result['failedFileCount'] > 0)) {
                Log::info('Expired runtime files cleaned.', [
                    'deleted_file_count' => $result['deletedFileCount'],
                    'deleted_bytes' => $result['deletedBytes'],
                    'failed_file_count' => $result['failedFileCount'],
                ]);
            }
        } catch (Throwable $throwable) {
            Log::error('Runtime maintenance tick failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
