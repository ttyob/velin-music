<?php

declare(strict_types=1);

namespace app\process;

use app\application\Airplay\AirplayCompanionSupervisor;
use app\application\System\AirplaySettingsService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 让内置 AirPlay 进程组最终收敛到版本化后台开关。
 *
 * 单实例 Worker 在启动后立即恢复已启用服务，并低频检查意外退出；关闭状态会停止遗留进程。进程组不
 * 保存内存业务状态，重启恢复只读取 SQLite 设置且启动操作幂等。读取失败时不猜测启用状态，也不启动
 * 网络服务；Worker 优雅停止时逆序关闭进程，失败只记录异常类型，不阻塞其它 Workerman 进程退出。
 */
final class AirplayCompanionWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 15.0,
        private readonly AirplaySettingsService $settings = new AirplaySettingsService(),
        private readonly AirplayCompanionSupervisor $supervisor = new AirplayCompanionSupervisor(),
    ) {
    }

    /** 启动事件循环后立即恢复一次，此后按不短于五秒的周期修复 companion 意外退出。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.1, [$this, 'reconcile'], [], false);
        Timer::add(max(5.0, $this->interval), [$this, 'reconcile']);
    }

    /** 停止时不再重启 companion，并尽力逆序关闭三个子进程。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
        try {
            $this->supervisor->stop();
        } catch (Throwable $throwable) {
            Log::warning('AirPlay companion shutdown failed.', ['exception_class' => $throwable::class]);
        }
    }

    /** 执行一次幂等收敛；失败只延后到下一周期，不改变已提交设置。 */
    public function reconcile(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            if ($this->settings->get()['enabled']) {
                if (!$this->supervisor->running() && !$this->supervisor->start()) {
                    Log::warning('AirPlay companion did not become ready.');
                }
            } elseif ($this->supervisor->running()) {
                $this->supervisor->stop();
            }
        } catch (Throwable $throwable) {
            Log::warning('AirPlay companion reconciliation failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
