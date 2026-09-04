<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;

/**
 * PluginEventStore 隔离核心事件发布与具体 Redis 游标协议。
 *
 * 存储只承诺有界、至少尝试一次的通知投递，不属于业务事实数据库。next 必须按插件维护独立游标；ack 仅
 * 推进当前插件，fail 负责重试退避或死信后推进。实现故障允许调用方放弃通知，但绝不能回滚已经提交的
 * 核心业务，也不能降级写入 SQLite。
 */
interface PluginEventStore
{
    /** 保存带有效期的事件并返回是否成功进入共享索引。 */
    public function publish(PluginDomainEvent $event): bool;

    /** 返回当前插件游标后的第一条有效事件；队列为空、退避中或首次初始化时返回 null。 */
    public function next(string $pluginKey): ?PluginEventDelivery;

    /** 确认当前事件并原子推进该插件游标；重复确认必须幂等。 */
    public function acknowledge(PluginEventDelivery $delivery): void;

    /**
     * 记录一次插件处理失败。
     *
     * @return array{attempt:int,retry:bool,retryAfterSeconds:int} retry=false 表示已形成有限期死信并推进游标
     */
    public function fail(PluginEventDelivery $delivery): array;
}
