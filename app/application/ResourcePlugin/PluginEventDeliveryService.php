<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\infrastructure\ResourcePlugin\RedisPluginEventStore;
use support\Log;
use Throwable;

/**
 * PluginEventDeliveryService 为一个活动插件消费至多一条已订阅事件。
 *
 * 每轮先由注册表复验安装、启用、manifest、接口和数据库版本，再读取该插件的 Redis 游标。未订阅事件只
 * 推进当前插件游标；handle 正常返回才确认，异常由 Store 退避重试并最终死信。服务不持有 SQLite 事务，
 * 也不把插件异常正文、事件载荷或 Redis key 写入日志，因而一个插件故障不会阻塞其他插件或核心业务。
 */
final readonly class PluginEventDeliveryService
{
    private const MAX_SKIPPED_PER_TICK = 32;

    /**
     * 注入插件注册表与事件存储；构造过程不读取 SQLite 或 Redis。
     *
     * 生产默认值供固定 Worker 使用，测试可注入随机插件根和内存 Store。两个依赖必须保持相同插件 key
     * 语义，服务不会从 Redis 内容推导或加载任意 PHP 类。
     */
    public function __construct(
        private PhpResourcePluginRegistry $registry = new PhpResourcePluginRegistry(),
        private PluginEventStore $store = new RedisPluginEventStore(),
    ) {
    }

    /**
     * 消费一个插件的下一条匹配通知。
     *
     * 返回 true 只表示本轮调用过插件 handle；没有新事件、处于退避或只跳过未订阅事件均返回 false。
     * 达到死信阈值的失败同样返回 false，下一轮将从后续事件继续。
     */
    public function processNext(string $pluginKey): bool
    {
        $hook = $this->registry->eventSubscriber($pluginKey);
        $subscriptions = $hook->subscribedEvents();
        for ($skipped = 0; $skipped < self::MAX_SKIPPED_PER_TICK; ++$skipped) {
            $delivery = $this->store->next($pluginKey);
            if (!$delivery instanceof PluginEventDelivery) return false;
            if (!in_array($delivery->event->name, $subscriptions, true)) {
                $this->store->acknowledge($delivery);
                continue;
            }
            try {
                $hook->handle($delivery->event);
                $this->store->acknowledge($delivery);
                return true;
            } catch (Throwable $failure) {
                $result = $this->store->fail($delivery);
                Log::warning('Resource plugin event delivery failed.', [
                    'plugin_key' => $pluginKey,
                    'event_name' => $delivery->event->name,
                    'event_id' => $delivery->event->eventId,
                    'attempt' => $result['attempt'],
                    'retry' => $result['retry'],
                    'exception_class' => $failure::class,
                ]);
                return false;
            }
        }
        return false;
    }
}
