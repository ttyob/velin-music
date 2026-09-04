<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;

/**
 * PluginEventDelivery 绑定一个插件独立游标与只读事件。
 *
 * streamId 仅供存储实现 CAS 式推进，不能传给插件或写入业务审计；pluginKey 已由注册表校验。该值对象
 * 不执行 Redis 操作，确认、失败和过期清理由 PluginEventStore 统一处理。
 */
final readonly class PluginEventDelivery
{
    public function __construct(
        public string $pluginKey,
        public string $streamId,
        public PluginDomainEvent $event,
    ) {
    }
}
