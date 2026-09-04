<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\infrastructure\ResourcePlugin\RedisPluginEventStore;
use Closure;
use support\Log;
use Throwable;

/**
 * PluginEventPublisher 在核心业务提交后把脱敏领域通知写入 Redis。
 *
 * Redis 是带 TTL 的扩展通知通道，不是任务或业务事实；协议错误、扩展缺失、连接失败和 Redis 拒写都只
 * 产生不含载荷的稳定告警并返回 null。调用方不得因此回滚扫描、刮削、播放或用户写入。PHPUnit 默认不
 * 连接部署 Redis，合同测试可显式注入内存 Store 验证发布内容。
 */
final class PluginEventPublisher
{
    private static float $nextWarningAt = 0.0;
    private readonly ?PluginEventStore $store;
    private readonly Closure $warningLogger;

    /**
     * 构建提交后发布器；生产默认使用 Redis，PHPUnit 默认禁用外部连接。
     *
     * 测试显式注入 Store 时仍执行完整事件校验，便于验证业务载荷；构造过程本身不连接 Redis，第一次
     * publish 才建立短超时连接，因此无事件请求不会增加网络开销。warningLogger 仅用于合同测试模拟
     * 日志基础设施故障；生产默认仍写 Webman 日志，但日志异常也必须被本发布器吞掉。
     *
     * @param (Closure(string, array<string, string|null>): void)|null $warningLogger
     */
    public function __construct(?PluginEventStore $store = null, ?Closure $warningLogger = null)
    {
        $testing = (string) (getenv('VELIN_TESTING') ?: '') === '1';
        $this->store = $store ?? ($testing ? null : new RedisPluginEventStore());
        $this->warningLogger = $warningLogger
            ?? static fn (string $message, array $context): mixed => Log::warning($message, $context);
    }

    /**
     * 创建并发布一条事件；返回 eventId 表示 Redis 已接受，null 表示通知被安全丢弃。
     *
     * @param array<string,mixed> $payload 由调用业务构造的脱敏、有界摘要
     */
    public function publish(
        string $name,
        string $subjectType,
        string $subjectId,
        string $actorType = 'system',
        ?string $actorId = null,
        array $payload = [],
    ): ?string {
        if (!$this->store instanceof PluginEventStore) return null;
        try {
            $event = PluginDomainEvent::create($name, $subjectType, $subjectId, $actorType, $actorId, $payload);
            if (!$this->store->publish($event)) {
                $this->warn('Resource plugin event was not accepted by Redis.', $name, null);
                return null;
            }
            return $event->eventId;
        } catch (Throwable $failure) {
            $this->warn('Resource plugin event publish failed.', $name, $failure::class);
            return null;
        }
    }

    /**
     * Redis 故障期间每分钟最多记录一次无载荷告警，避免大批扫描逐曲发布时填满日志磁盘。
     *
     * 限流只存在当前 PHP Worker 内存中，不写 Redis 或 SQLite；恢复后的成功发布无需重置，下一次独立故障
     * 最迟一分钟后仍会被观察。日志不得加入 subjectId、actorId、payload、连接配置或异常 message。日志
     * 基础设施本身也可能因磁盘、格式化器或错误处理器抛出 Throwable；此处必须再次隔离，确保 Redis 这条
     * 可丢失通知永远不能改写调用方已经提交的业务终态。
     */
    private function warn(string $message, string $eventName, ?string $exceptionClass): void
    {
        $now = microtime(true);
        if ($now < self::$nextWarningAt) return;
        self::$nextWarningAt = $now + 60.0;
        try {
            ($this->warningLogger)($message, [
                'event_name' => $eventName,
                'exception_class' => $exceptionClass,
            ]);
        } catch (Throwable) {
            // Redis 通知与其告警都不是业务事实；双重故障只能静默丢弃，不能递归记录或向核心流程抛出。
        }
    }
}
