<?php

declare(strict_types=1);

namespace app\process;

use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Coroutine\Exception\PoolException;
use Workerman\Timer;
use Workerman\Worker;

/**
 * ResourcePluginTranscodeWorker 负责下载完成后的独立媒体发布和转码消费。
 *
 * 该进程与资源下载轮询完全分离，多个进程可以同时领取不同插件的 importing/publishing 任务；实际
 * FFmpeg 数量仍由插件通过 TranscodeAdmission 按后台 system.limits.maxConcurrentTranscodes 收口。每个
 * 插件必须在 processTranscodeNext 内使用状态 CAS 领取任务，核心只负责动态发现活动包和隔离异常。
 * 任务状态、心跳和崩溃恢复属于插件自己的数据库合同，核心不会在失败时直接改写插件表。
 */
final class ResourcePluginTranscodeWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 1.0,
        private readonly PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
    ) {
        $host = gethostname();
        $this->workerId = ($host === false ? 'velin' : $host) . ':resource-plugin-transcode:' . getmypid();
    }

    /** 只登记定时器，首次数据库访问推迟到事件循环 tick，避免非协程上下文复用错误连接。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.1, [$this, 'tick'], [], false);
        Timer::add(max(0.5, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止发现新任务；已领取任务由插件心跳和恢复逻辑收口。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每轮让每个活动插件最多领取一项，异常只影响当前插件本轮。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            foreach ($this->registry->transcodeWorkerKeys() as $pluginKey) {
                if ($this->stopping) break;
                try {
                    $this->registry->transcodeWorker($pluginKey)
                        ->processTranscodeNext($this->workerId . ':' . $pluginKey);
                } catch (Throwable $throwable) {
                    Log::error('Resource plugin transcode worker tick failed.', [
                        'plugin_key' => $pluginKey,
                        'worker_id' => $this->workerId,
                        'exception_class' => $throwable::class,
                        'pool_error' => $throwable instanceof PoolException ? $throwable->getMessage() : null,
                    ]);
                }
            }
        } catch (Throwable $throwable) {
            Log::error('Resource plugin transcode discovery failed.', [
                'worker_id' => $this->workerId,
                'exception_class' => $throwable::class,
                'pool_error' => $throwable instanceof PoolException ? $throwable->getMessage() : null,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
