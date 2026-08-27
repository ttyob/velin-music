<?php

declare(strict_types=1);

namespace app\process;

use app\application\Playlist\PlaylistAutoCompletionService;
use support\Log;
use Symfony\Component\Uid\Ulid;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 系统歌单自动补全单消费者。
 *
 * HTTP 请求只提交开关和耐久目标；本进程负责有界搜索、插件下载状态轮询和扫描后的歌曲回填。SQLite
 * 阶段每轮最多领取一条任务，停止时不取消插件已经提交的下载；任务状态和三次候选上限都由服务层
 * 复验，进程重启后可以继续而不会把同一个租约重复提交。
 */
final class PlaylistAutoCompletionWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 5.0,
        private readonly PlaylistAutoCompletionService $completion = new PlaylistAutoCompletionService(),
    ) {
        $this->workerId = 'playlist-auto-completion-' . (string) new Ulid();
    }

    /** 首次延迟执行，确保迁移完成；后续定时器不允许同一 Worker 重入。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(5.0, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 停止后不领取新目标，已经提交的下载由插件自己的 Worker 收口。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 消费一条歌单补全目标；异常只记录类型，不能把第三方响应或租约写入日志。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            if (!$this->stopping) $this->completion->processNext($this->workerId);
        } catch (Throwable $throwable) {
            Log::error('Playlist auto completion worker tick failed.', [
                'worker_id' => $this->workerId,
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
