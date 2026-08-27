<?php

declare(strict_types=1);

namespace app\process;

use app\application\Export\PersonalDataExportWorkerService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/** 独立消费个人导出任务，避免分页隐私读取、JSON 写入和 SHA-256 阻塞 HTTP 或媒体 Worker。 */
final class PersonalDataExportWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 3.0,
        private readonly PersonalDataExportWorkerService $exports = new PersonalDataExportWorkerService(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':personal-export:' . getmypid();
    }

    /** 启动快速消费并注册单进程有界轮询。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1.0, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止阶段不再领取新任务，当前文件写入会完成或由下次租约恢复。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每次最多清理一个过期产物并执行一个任务；进程停止后不再读取个人数据。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $this->exports->recoverStaleLeases();
            $this->exports->purgeOneExpired();
            $job = $this->exports->claimNext($this->workerId);
            if ($job !== null && !$this->stopping) $this->exports->execute($job);
        } catch (Throwable $throwable) {
            Log::error('Personal data export worker tick failed.', [
                'worker_id' => $this->workerId, 'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
