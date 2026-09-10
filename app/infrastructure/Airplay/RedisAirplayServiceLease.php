<?php

declare(strict_types=1);

namespace app\infrastructure\Airplay;

use app\application\Airplay\AirplayServiceLease;
use app\application\Airplay\AirplayUnavailable;
use app\http\RequestContext;
use Redis;
use RuntimeException;
use Throwable;

/**
 * 使用现有 Redis 为 OwnTone 单队列建立 90 秒账号租约。
 *
 * 固定 key 与用途分离 HMAC owner 都不保存账号 ID；Lua 原子完成创建、比较和续期。Redis 故障失败关闭，
 * 因为绕过协调会让另一账号的歌曲、输出和进度被当前请求覆盖。每个 Worker 复用短超时连接，连接异常后
 * 主动丢弃，下一次请求重新建立，不使用 `.env` 新增任何 AirPlay 配置。
 */
final class RedisAirplayServiceLease implements AirplayServiceLease
{
    private const KEY = 'velin_airplay_service_lease:v1';
    private const TTL_SECONDS = 90;

    private static ?Redis $connection = null;

    /** {@inheritDoc} */
    public function acquire(array $actor, bool $refresh = true): bool
    {
        $owner = $this->owner($actor);
        try {
            $result = $this->connection()->eval(<<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
  redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
  return 1
end
if current ~= ARGV[1] then return 0 end
if ARGV[3] == '1' then redis.call('EXPIRE', KEYS[1], ARGV[2]) end
return 2
LUA, [self::KEY, $owner, self::TTL_SECONDS, $refresh ? '1' : '0'], 1);
        } catch (Throwable $failure) {
            $this->disconnect();
            throw new AirplayUnavailable('AIRPLAY_COORDINATION_UNAVAILABLE', 'AirPlay 占用协调不可用。', $failure);
        }
        if (!is_int($result) || !in_array($result, [0, 1, 2], true)) {
            $this->disconnect();
            throw new AirplayUnavailable('AIRPLAY_COORDINATION_UNAVAILABLE', 'AirPlay 占用协调响应无效。');
        }
        if ($result === 0) {
            throw new AirplayUnavailable('AIRPLAY_DEVICE_BUSY', 'AirPlay 服务正由其他账号控制。');
        }
        return $result === 1;
    }

    /** {@inheritDoc} */
    public function refresh(array $actor): void
    {
        $this->acquire($actor, true);
    }

    /** {@inheritDoc} */
    public function release(array $actor): void
    {
        $owner = $this->owner($actor);
        try {
            $this->connection()->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA, [self::KEY, $owner], 1);
        } catch (Throwable $failure) {
            $this->disconnect();
            throw new AirplayUnavailable('AIRPLAY_COORDINATION_UNAVAILABLE', 'AirPlay 占用协调不可用。', $failure);
        }
    }

    /** 从实时认证投影派生不可反查的租约 owner。 */
    private function owner(array $actor): string
    {
        $userId = $actor['id'] ?? null;
        if (!is_string($userId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $userId) !== 1) {
            throw new AirplayUnavailable('AIRPLAY_PERMISSION_DENIED', 'AirPlay 租约身份无效。');
        }
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-airplay-lease-key-v1');
        return hash_hmac('sha256', "account\0" . $userId, $key);
    }

    /** 创建当前 Worker 的持久 Redis 连接；认证或选库失败不降级为未认证连接。 */
    private function connection(): Redis
    {
        if (self::$connection instanceof Redis) return self::$connection;
        if (!class_exists(Redis::class)) throw new RuntimeException('REDIS_EXTENSION_UNAVAILABLE');
        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 27379)));
        $timeout = max(0.05, min(0.5, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.2)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-airplay-lease-' . getmypid(), 0, $timeout)) {
            throw new RuntimeException('AIRPLAY_LEASE_CONNECT_FAILED');
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('AIRPLAY_LEASE_AUTH_FAILED');
        }
        if (!$redis->select(max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0))))) {
            throw new RuntimeException('AIRPLAY_LEASE_SELECT_FAILED');
        }
        self::$connection = $redis;
        return $redis;
    }

    /** 丢弃故障连接，不尝试复用状态未知的 socket。 */
    private function disconnect(): void
    {
        if (self::$connection instanceof Redis) {
            try {
                self::$connection->close();
            } catch (Throwable) {
            }
        }
        self::$connection = null;
    }
}
