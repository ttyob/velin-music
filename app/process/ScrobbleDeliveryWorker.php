<?php

declare(strict_types=1);

namespace app\process;

use app\application\Scrobble\ScrobbleDeliveryWorkerService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/** 独立消费外部 Scrobble outbox，使第三方网络延迟和故障不阻塞播放事件、Subsonic 或 SQLite 写事务。 */
final class ScrobbleDeliveryWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly ScrobbleDeliveryWorkerService $deliveries = new ScrobbleDeliveryWorkerService(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':scrobble:' . getmypid();
    }

    /** Worker 启动后快速恢复孤儿租约，并以单进程有界频率消费。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1.0, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止边界不再领取新任务；正在进行的短 HTTP 请求最多受八秒总超时限制。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每个 tick 最多恢复一批租约并执行一条任务；日志不包含账号、歌曲、端点或错误正文。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $this->deliveries->recoverStaleLeases();
            $job = $this->deliveries->claimNext($this->workerId);
            if ($job !== null && !$this->stopping) $this->deliveries->execute($job, $this->workerId);
        } catch (Throwable $throwable) {
            Log::error('Scrobble delivery worker tick failed.', [
                'worker_id' => $this->workerId, 'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
