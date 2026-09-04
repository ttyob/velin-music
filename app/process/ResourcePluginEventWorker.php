<?php

declare(strict_types=1);

namespace app\process;

use app\application\ResourcePlugin\PhpResourcePluginRegistry;
use app\application\ResourcePlugin\PluginEventDeliveryService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Coroutine\Exception\PoolException;
use Workerman\Timer;
use Workerman\Worker;

/**
 * ResourcePluginEventWorker 为活动用户插件异步投递 Redis 有限期领域事件。
 *
 * 进程固定单消费者，每轮重新发现启用且数据库版本匹配的 `event_subscriber` 插件，并让每个插件最多处理
 * 一条匹配事件。Redis 读写和插件 handle 均不处于核心业务 SQLite 事务内；一个插件异常只影响自己的
 * 游标与重试状态。关闭进程不会阻断主业务，未过 TTL 的事件在重新启用后继续，过期通知不补写数据库。
 */
final class ResourcePluginEventWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    /**
     * 构建固定单消费者调度器；真正的注册表和 Redis 访问延迟到首次 tick。
     *
     * pollInterval 最低由 onWorkerStart 收敛为 0.5 秒；测试可注入隔离注册表与投递服务。构造过程不领取
     * 事件、不执行插件代码，也不创建新的 Workerman 进程。
     */
    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
        private readonly PluginEventDeliveryService $delivery = new PluginEventDeliveryService(),
    ) {
    }

    /** 只注册非协程定时器，首次插件账本和 Redis 访问推迟到 tick。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.1, [$this, 'tick'], [], false);
        Timer::add(max(0.5, $this->pollInterval), [$this, 'tick']);
    }

    /** 停机后不再发现新插件或读取事件；正在执行的单次 handle 仍按插件幂等合同收口。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 每轮公平地给每个活动订阅插件一次消费机会，异常日志不包含载荷、Redis key 或插件错误正文。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            foreach ($this->registry->eventSubscriberKeys() as $pluginKey) {
                if ($this->stopping) break;
                try {
                    $this->delivery->processNext($pluginKey);
                } catch (Throwable $failure) {
                    Log::warning('Resource plugin event worker tick failed.', [
                        'plugin_key' => $pluginKey,
                        'exception_class' => $failure::class,
                        'pool_error' => $failure instanceof PoolException ? $failure->getMessage() : null,
                    ]);
                }
            }
        } catch (Throwable $failure) {
            Log::warning('Resource plugin event subscriber discovery failed.', [
                'exception_class' => $failure::class,
                'pool_error' => $failure instanceof PoolException ? $failure->getMessage() : null,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
