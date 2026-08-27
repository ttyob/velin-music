<?php

declare(strict_types=1);

namespace app\process;

use app\application\Upload\UploadPublishWorkerService;
use app\application\Upload\UploadExpirationService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 在独立单进程中消费上传校验/发布任务，避免大文件哈希和 FFprobe 阻塞 HTTP 或刮削轮询。
 *
 * SQLite 阶段保持单消费者；任务事实可跨重启恢复。进程停止后不领取新任务，正在执行的单个文件操作
 * 由领域服务完成或补偿。异常日志只记录 Worker ID 与异常类，不包含会话、文件或路径。
 */
final class UploadWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;
    private int $nextExpirationSweepAt = 0;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly UploadPublishWorkerService $uploads = new UploadPublishWorkerService(),
        private readonly UploadExpirationService $expiration = new UploadExpirationService(),
    ) {
        $this->workerId = (gethostname() ?: 'velin') . ':upload:' . getmypid();
    }

    /** 启动一次快速消费并注册有界周期轮询。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(1.0, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 标记停止，防止关闭过程中领取下一会话。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 恢复过期租约并执行最多一个会话；重入或进程停止时均不启动文件工作。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $nowEpoch = time();
            if ($nowEpoch >= $this->nextExpirationSweepAt) {
                $result = $this->expiration->pruneExpired(gmdate('Y-m-d\TH:i:s\Z', $nowEpoch), 25);
                $this->nextExpirationSweepAt = $nowEpoch + 60;
                if ($result['expired'] > 0 || $result['failed'] > 0) {
                    Log::info('Expired upload sessions processed.', [
                        'expired_count' => $result['expired'],
                        'failed_count' => $result['failed'],
                    ]);
                }
            }
            $this->uploads->recoverStaleLeases();
            // 上传扫描请求必须先冲刷；已有活动扫描时方法无副作用，后续 tick 会继续尝试。
            $this->uploads->flushScanRequests();
            // 账号停用清理优先于新发布，避免大量已取消暂存长期占用动态目录空间。
            if ($this->uploads->processPendingCancellationCleanup()) return;
            $job = $this->uploads->claimNext($this->workerId);
            if ($job !== null && !$this->stopping) $this->uploads->execute($job);
        } catch (Throwable $throwable) {
            Log::error('Upload worker tick failed.', [
                'worker_id' => $this->workerId,
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
