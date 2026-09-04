<?php

declare(strict_types=1);

namespace app\infrastructure\ResourcePlugin;

use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\application\ResourcePlugin\PluginEventDelivery;
use app\application\ResourcePlugin\PluginEventStore;
use JsonException;
use Redis;
use RuntimeException;
use Throwable;

/**
 * RedisPluginEventStore 使用 Redis Stream 索引和逐插件游标投递有限期领域通知。
 *
 * 每条完整事件保存在独立 SETEX key，默认 24 小时后由 Redis 自动删除；Stream 只保存 eventId 指针，并
 * 同时受 10000 条近似上限和空闲 TTL 约束。每个插件有独立 cursor、attempt、delay、dead key，全部带
 * TTL，不创建 SQLite 表。首次观察一个插件时把游标初始化到当前 Stream 尾部，避免新安装插件读取安装前
 * 事件；禁用期间游标保留一段有限时间，过期后重新启用会从新尾部开始。
 *
 * 投递是有界至少尝试一次而非永久消息队列：Redis 故障、通知到期或持续积压超过 Stream 上限都允许丢失。
 * 插件必须按 eventId 幂等，且不能把该通道用作支付、授权、文件补偿或其他不可重建事实。
 */
final class RedisPluginEventStore implements PluginEventStore
{
    private const STREAM = 'velin:plugin-events:index:v1';
    private const EVENT_PREFIX = 'velin:plugin-events:event:v1:';
    private const CURSOR_PREFIX = 'velin:plugin-events:cursor:v1:';
    private const ATTEMPT_PREFIX = 'velin:plugin-events:attempt:v1:';
    private const DELAY_PREFIX = 'velin:plugin-events:delay:v1:';
    private const DEAD_PREFIX = 'velin:plugin-events:dead:v1:';
    private const DEFAULT_TTL_SECONDS = 86400;
    private const MAX_STREAM_LENGTH = 10000;
    private const MAX_ATTEMPTS = 5;
    private const RETRY_DELAYS = [2, 5, 15, 60];
    private const PUBLISH_SCRIPT = <<<'LUA'
local stored = redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2], 'NX')
if not stored then return false end
local streamId = redis.call('XADD', KEYS[2], 'MAXLEN', '~', ARGV[3], '*', 'eventId', ARGV[4])
local now = redis.call('TIME')
local cutoff = (tonumber(now[1]) - tonumber(ARGV[2])) * 1000
redis.call('XTRIM', KEYS[2], 'MINID', '~', tostring(cutoff) .. '-0')
redis.call('EXPIRE', KEYS[2], ARGV[2])
return streamId
LUA;

    private ?Redis $connection;
    private bool $ownsConnection;
    private float $unavailableUntil = 0.0;
    private readonly int $ttlSeconds;
    private readonly int $cursorTtlSeconds;

    /**
     * 使用部署 Redis 配置构建 Store；测试可以注入隔离连接和更短 TTL。
     *
     * TTL 最短五分钟、最长七天，避免误配置形成永久记录或高频立即过期；cursor 最多保留事件 TTL 的
     * 两倍，并在活动消费时续期。注入连接不由本类关闭，生产持久连接发生协议/网络故障时主动丢弃。
     */
    public function __construct(?Redis $connection = null, ?int $ttlSeconds = null)
    {
        $configured = $ttlSeconds ?? (int) (getenv('VELIN_PLUGIN_EVENT_TTL_SECONDS') ?: self::DEFAULT_TTL_SECONDS);
        $this->ttlSeconds = max(300, min(604800, $configured));
        $this->cursorTtlSeconds = min(1209600, $this->ttlSeconds * 2);
        $this->connection = $connection;
        $this->ownsConnection = $connection === null;
    }

    /** {@inheritDoc} */
    public function publish(PluginDomainEvent $event): bool
    {
        $json = json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $result = $this->redis()->eval(self::PUBLISH_SCRIPT, [
                self::EVENT_PREFIX . $event->eventId,
                self::STREAM,
                $json,
                (string) $this->ttlSeconds,
                (string) self::MAX_STREAM_LENGTH,
                $event->eventId,
            ], 2);
            return is_string($result) && preg_match('/^\d+-\d+$/D', $result) === 1;
        } catch (Throwable $failure) {
            $this->markUnavailable();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_PUBLISH_FAILED', previous: $failure);
        }
    }

    /** {@inheritDoc} */
    public function next(string $pluginKey): ?PluginEventDelivery
    {
        $this->assertPluginKey($pluginKey);
        try {
            $redis = $this->redis();
            if ((int) $redis->exists(self::DELAY_PREFIX . $pluginKey) > 0) return null;
            $cursorKey = self::CURSOR_PREFIX . $pluginKey;
            $cursor = $redis->get($cursorKey);
            if (!is_string($cursor) || preg_match('/^\d+-\d+$/D', $cursor) !== 1) {
                $latest = $redis->xRevRange(self::STREAM, '+', '-', 1);
                $cursor = is_array($latest) && $latest !== [] ? (string) array_key_first($latest) : '0-0';
                $redis->set($cursorKey, $cursor, ['ex' => $this->cursorTtlSeconds]);
                return null;
            }
            $rows = $redis->xRange(self::STREAM, '(' . $cursor, '+', 20);
            if (!is_array($rows) || $rows === []) {
                $redis->expire($cursorKey, $this->cursorTtlSeconds);
                return null;
            }
            foreach ($rows as $streamId => $fields) {
                $eventId = is_array($fields) && is_string($fields['eventId'] ?? null)
                    ? $fields['eventId'] : '';
                $json = $eventId === '' ? false : $redis->get(self::EVENT_PREFIX . $eventId);
                if (!is_string($json)) {
                    $this->advance($redis, $pluginKey, (string) $streamId, $eventId);
                    continue;
                }
                try {
                    $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) throw new JsonException('PLUGIN_EVENT_PROTOCOL_INVALID');
                    $event = PluginDomainEvent::fromArray($decoded);
                } catch (Throwable) {
                    $this->advance($redis, $pluginKey, (string) $streamId, $eventId);
                    continue;
                }
                if ($event->eventId !== $eventId) {
                    $this->advance($redis, $pluginKey, (string) $streamId, $eventId);
                    continue;
                }
                return new PluginEventDelivery($pluginKey, (string) $streamId, $event);
            }
            return null;
        } catch (Throwable $failure) {
            $this->markUnavailable();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_READ_FAILED', previous: $failure);
        }
    }

    /** {@inheritDoc} */
    public function acknowledge(PluginEventDelivery $delivery): void
    {
        $this->assertDelivery($delivery);
        try {
            $this->advance($this->redis(), $delivery->pluginKey, $delivery->streamId, $delivery->event->eventId);
        } catch (Throwable $failure) {
            $this->markUnavailable();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_ACK_FAILED', previous: $failure);
        }
    }

    /** {@inheritDoc} */
    public function fail(PluginEventDelivery $delivery): array
    {
        $this->assertDelivery($delivery);
        try {
            $redis = $this->redis();
            $attemptKey = $this->attemptKey($delivery->pluginKey, $delivery->event->eventId);
            $attempt = (int) $redis->incr($attemptKey);
            $redis->expire($attemptKey, $this->ttlSeconds);
            if ($attempt >= self::MAX_ATTEMPTS) {
                $dead = json_encode([
                    'eventId' => $delivery->event->eventId,
                    'eventName' => $delivery->event->name,
                    'pluginKey' => $delivery->pluginKey,
                    'attempt' => $attempt,
                    'failedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $redis->set(self::DEAD_PREFIX . $delivery->pluginKey . ':' . $delivery->event->eventId,
                    $dead, ['ex' => $this->ttlSeconds]);
                $this->advance($redis, $delivery->pluginKey, $delivery->streamId, $delivery->event->eventId);
                return ['attempt' => $attempt, 'retry' => false, 'retryAfterSeconds' => 0];
            }
            $delay = self::RETRY_DELAYS[min($attempt - 1, count(self::RETRY_DELAYS) - 1)];
            $redis->set(self::DELAY_PREFIX . $delivery->pluginKey, '1', ['ex' => $delay]);
            return ['attempt' => $attempt, 'retry' => true, 'retryAfterSeconds' => $delay];
        } catch (Throwable $failure) {
            $this->markUnavailable();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_RETRY_FAILED', previous: $failure);
        }
    }

    /**
     * 推进单插件游标并清理本事件重试状态；完整事件由 SETEX 自行过期，不能因一个插件确认而提前删除。
     */
    private function advance(Redis $redis, string $pluginKey, string $streamId, string $eventId): void
    {
        if (preg_match('/^\d+-\d+$/D', $streamId) !== 1) throw new RuntimeException('PLUGIN_EVENT_STREAM_ID_INVALID');
        $redis->set(self::CURSOR_PREFIX . $pluginKey, $streamId, ['ex' => $this->cursorTtlSeconds]);
        $keys = [self::DELAY_PREFIX . $pluginKey];
        if ($eventId !== '') $keys[] = $this->attemptKey($pluginKey, $eventId);
        $redis->del(...$keys);
    }

    private function attemptKey(string $pluginKey, string $eventId): string
    {
        return self::ATTEMPT_PREFIX . $pluginKey . ':' . $eventId;
    }

    private function assertDelivery(PluginEventDelivery $delivery): void
    {
        $this->assertPluginKey($delivery->pluginKey);
        if (preg_match('/^\d+-\d+$/D', $delivery->streamId) !== 1) {
            throw new RuntimeException('PLUGIN_EVENT_DELIVERY_INVALID');
        }
    }

    private function assertPluginKey(string $pluginKey): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1) {
            throw new RuntimeException('PLUGIN_EVENT_PLUGIN_KEY_INVALID');
        }
    }

    /** 创建当前 Worker 复用的短超时持久连接；认证或选库失败不回退到未认证默认库。 */
    private function redis(): Redis
    {
        if ($this->connection instanceof Redis) return $this->connection;
        if (microtime(true) < $this->unavailableUntil || !class_exists(Redis::class)) {
            throw new RuntimeException('PLUGIN_EVENT_REDIS_UNAVAILABLE');
        }
        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 16379)));
        $timeout = max(0.05, min(0.2, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.1)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-plugin-events-' . getmypid(), 0, $timeout)) {
            throw new RuntimeException('PLUGIN_EVENT_REDIS_CONNECT_FAILED');
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            $redis->close();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_AUTH_FAILED');
        }
        if (!$redis->select(max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0))))) {
            $redis->close();
            throw new RuntimeException('PLUGIN_EVENT_REDIS_SELECT_FAILED');
        }
        $this->connection = $redis;
        $this->ownsConnection = true;
        return $redis;
    }

    /** 丢弃本实例拥有的未知状态连接并短暂退避；注入的测试连接由调用方管理。 */
    private function markUnavailable(): void
    {
        if ($this->ownsConnection && $this->connection instanceof Redis) {
            try {
                $this->connection->close();
            } catch (Throwable) {
            }
            $this->connection = null;
        }
        $this->unavailableUntil = microtime(true) + 5.0;
    }
}
