<?php

declare(strict_types=1);

namespace app\process;

use app\application\Playback\PlaybackPrefetchJobService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/** 独立执行远程下一首缓存，避免目录复验、Range 下载和磁盘同步阻塞 HTTP、播放事件或扫描 Worker。 */
final class PlaybackPrefetchWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 0.5,
        private readonly PlaybackPrefetchJobService $jobs = new PlaybackPrefetchJobService(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':playback-prefetch:' . getmypid();
    }

    /** 启动后快速恢复孤儿租约；固定单进程串行下载，防止下一首预热抢占全部远端带宽。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.25, [$this, 'tick'], [], false);
        Timer::add(max(0.25, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止时不领取新任务；当前 Range 请求受远端客户端超时约束，部分文件不会成为缓存命中。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每次最多处理一首；日志只含 Worker 与异常类型，不记录用户、歌曲、播放器、路径或远端错误正文。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $this->jobs->recoverStaleLeases();
            $job = $this->jobs->claimNext($this->workerId);
            if ($job !== null && !$this->stopping) $this->jobs->execute($job, $this->workerId);
        } catch (Throwable $failure) {
            Log::error('Playback prefetch worker tick failed.', [
                'worker_id' => $this->workerId, 'exception_class' => $failure::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
