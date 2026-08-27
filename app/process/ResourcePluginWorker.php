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
 * ResourcePluginWorker 为所有已安装资源插件提供一个核心常驻的单消费者调度进程。
 *
 * 插件安装后不再依赖 Webman 重新扫描插件自己的 process 配置；每个 tick 都从活动目录和数据库账本
 * 重新发现带 `worker` 能力的插件，因此首次安装会在下一轮自动生效，开始卸载后也会立即停止领取新
 * 任务。SQLite 阶段固定单进程、每个插件每轮最多执行一个耐久任务，插件必须自行保证领取幂等和租约
 * 恢复；一个插件失败只记录脱敏诊断，不阻断其他插件。
 */
final class ResourcePluginWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
    ) {
        $host = gethostname();
        $this->workerId = ($host === false ? 'velin' : $host) . ':resource-plugin:' . getmypid();
    }

    /**
     * 只登记定时器，不在 onWorkerStart 的 Fiber 中访问插件账本或构建数据库服务。
     *
     * Workerman Select 的 timer 回调运行在非协程事件循环；把首次数据库访问推迟到 tick，可使插件
     * 注册表和后续业务服务始终使用同一连接池路径，避免 Fiber 连接在非协程回调中触发 PoolException。
     */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.1, [$this, 'tick'], [], false);
        Timer::add(max(1.0, $this->pollInterval), [$this, 'tick']);
    }

    /** 停机后不再发现或领取新任务；已领取任务仍由插件自身的检查点和租约合同收口。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /**
     * 动态发现活动 Worker 钩子并各执行一次有界消费。
     *
     * 注册表只返回安装标记存在、未待卸载且数据库版本匹配的插件。每轮重新发现是热安装/停用边界，
     * 不缓存插件对象或业务数据库服务；finally 无论成功失败都销毁应用 Context。日志只包含插件 key、
     * Worker ID、异常类和框架固定连接池消息，不包含插件配置、资源引用、URL、凭据或文件路径。
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) {
            return;
        }
        $this->busy = true;
        try {
            foreach ($this->registry->workerKeys() as $pluginKey) {
                if ($this->stopping) {
                    break;
                }
                try {
                    $this->registry->worker($pluginKey)->processNext($this->workerId . ':' . $pluginKey);
                } catch (Throwable $throwable) {
                    Log::error('Resource plugin worker tick failed.', [
                        'plugin_key' => $pluginKey,
                        'worker_id' => $this->workerId,
                        'exception_class' => $throwable::class,
                        'pool_error' => $throwable instanceof PoolException ? $throwable->getMessage() : null,
                    ]);
                }
            }
        } catch (Throwable $throwable) {
            Log::error('Resource plugin discovery failed.', [
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
