<?php

declare(strict_types=1);

namespace app\process;

use app\application\System\RedisCompanionSupervisor;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 维持 backend 容器内必需 Redis 进程，并在 Workerman 优雅退出时完成 AOF 刷盘。
 *
 * 入口已在迁移前启动 Redis，本 Worker 只负责运行期恢复与退出协调。单实例和 helper 文件锁保证不会
 * 并发启动；异常退出最多延迟一个检查周期。停止失败只记录异常类型并让 Docker 停止边界继续处理，
 * 不发送 SIGKILL、不删除 AOF，也不影响裸机部署者自行管理的 Redis。
 */
final class RedisCompanionWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 5.0,
        private readonly RedisCompanionSupervisor $supervisor = new RedisCompanionSupervisor(),
    ) {
    }

    /**
     * 注册启动后首次检查和固定周期恢复任务。
     *
     * 前置条件是入口已经在迁移前启动 Redis；本方法不阻塞 Workerman 启动，最早在事件循环开始后检查。
     * 周期下限为两秒，避免错误状态下高频派生进程；重复回调仍由 busy 标记与 helper 文件锁串行化。
     */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.1, [$this, 'reconcile'], [], false);
        Timer::add(max(2.0, $this->interval), [$this, 'reconcile']);
    }

    /**
     * 停止后续恢复并尽力完成 Redis 持久化关停。
     *
     * Workerman 退出期间只允许 SIGTERM 路径；失败记录异常类型但不抛回 master，也不发送 SIGKILL 或删除
     * 数据文件。stopping 标记确保定时器即使已排队也不会在关闭窗口重新启动 Redis。
     */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
        try {
            $this->supervisor->stop();
        } catch (Throwable $throwable) {
            Log::warning('Redis companion shutdown failed.', ['exception_class' => $throwable::class]);
        }
    }

    /**
     * 执行一次幂等健康收敛，使内部 Redis 最终回到运行状态。
     *
     * 同一 Worker 内并发调用直接返回；状态检查或启动失败只写不含路径和 Redis 数据的稳定日志，等待下个
     * 周期重试。无论成功失败都销毁当前 Webman Context，避免长生命周期 Timer 保留请求级状态。
     */
    public function reconcile(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            if (!$this->supervisor->running() && !$this->supervisor->start()) {
                Log::error('Redis companion did not become ready.');
            }
        } catch (Throwable $throwable) {
            Log::error('Redis companion reconciliation failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
