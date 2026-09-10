<?php

declare(strict_types=1);

namespace app\infrastructure\Metadata;

use app\application\Metadata\MetadataWorkerWakeSignal;
use Redis;
use Throwable;

/**
 * 使用现有 Redis 提供可合并、可丢失的 Metadata Worker 唤醒标记。
 *
 * 固定 key 不包含用户或业务对象，value 也只是一位常量；十秒 TTL 防止消费者停机时留下永久状态。
 * Lua 原子完成 GET+DEL，避免消费与新通知之间丢信号。Redis 不是任务事实：连接、认证、选库、读写失败
 * 都进入五秒本地退避并返回无信号，原有两秒 SQLite 轮询会继续收口，不写日志或暴露连接参数。
 */
final class RedisMetadataWorkerWakeSignal implements MetadataWorkerWakeSignal
{
    private const KEY = 'velin:worker-wake:metadata-batch:v1';
    private const CONSUME_SCRIPT = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then return 0 end
redis.call('DEL', KEYS[1])
return 1
LUA;
    private static ?Redis $connection = null;
    private static float $unavailableUntil = 0.0;

    public function notify(): void
    {
        try {
            $redis = $this->connection();
            if ($redis instanceof Redis && $redis->set(self::KEY, '1', ['ex' => 10]) !== true) {
                $this->markUnavailable();
            }
        } catch (Throwable) {
            $this->markUnavailable();
        }
    }

    public function consume(): bool
    {
        try {
            $redis = $this->connection();
            if (!$redis instanceof Redis) return false;
            return (int) $redis->eval(self::CONSUME_SCRIPT, [self::KEY], 1) === 1;
        } catch (Throwable) {
            $this->markUnavailable();
            return false;
        }
    }

    /**
     * 创建当前 Worker 复用的短超时持久连接；认证或选库失败不能回退到未认证默认数据库。
     */
    private function connection(): ?Redis
    {
        if (self::$connection instanceof Redis) return self::$connection;
        if (microtime(true) < self::$unavailableUntil || !class_exists(Redis::class)) return null;
        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 27379)));
        $timeout = max(0.05, min(0.2, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.1)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-metadata-wake-' . getmypid(), 0, $timeout)) {
            $this->markUnavailable();
            return null;
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            $this->markUnavailable();
            return null;
        }
        if (!$redis->select(max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0))))) {
            $this->markUnavailable();
            return null;
        }
        self::$connection = $redis;
        return $redis;
    }

    /** 丢弃状态未知的连接并短暂退避；标记可丢失，因此不需要补偿任务。 */
    private function markUnavailable(): void
    {
        if (self::$connection instanceof Redis) {
            try {
                self::$connection->close();
            } catch (Throwable) {
            }
        }
        self::$connection = null;
        self::$unavailableUntil = microtime(true) + 5.0;
    }
}
