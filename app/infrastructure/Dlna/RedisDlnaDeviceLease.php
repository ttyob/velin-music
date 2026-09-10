<?php

declare(strict_types=1);

namespace app\infrastructure\Dlna;

use app\application\Dlna\DlnaDeviceLease;
use app\application\Dlna\DlnaUnavailable;
use app\http\RequestContext;
use Redis;
use RuntimeException;
use support\Log;
use Throwable;

/**
 * 使用现有 Redis 实现按账号占用 Renderer 的 90 秒可续租租约。
 *
 * Redis 键和值分别是用途分离 HMAC 后的设备 UDN 与账号 ID，不保存明文设备、用户、网络地址或票据。
 * Lua 把比较、创建和续期合为单个原子操作，多个 Web worker 同时投放时只有一个账号成功；同账号返回
 * 可复用。Redis 是共享设备互斥的安全边界，故障时失败关闭为协调不可用，不能降级成静默抢占。
 */
final class RedisDlnaDeviceLease implements DlnaDeviceLease
{
    private const PREFIX = 'velin_dlna_device_lease:v1:';
    private const TTL_SECONDS = 90;

    private static ?Redis $connection = null;

    /** {@inheritDoc} */
    public function acquire(array $actor, string $deviceId, bool $refresh = true): bool
    {
        [$key, $owner] = $this->identity($actor, $deviceId);
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
LUA, [$key, $owner, self::TTL_SECONDS, $refresh ? '1' : '0'], 1);
        } catch (DlnaUnavailable $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            self::logCoordinationFailure('acquire', $failure);
            $this->disconnect();
            throw new DlnaUnavailable('DLNA_COORDINATION_UNAVAILABLE', 'DLNA 设备占用协调暂时不可用。', $failure);
        }
        if (!is_int($result) || !in_array($result, [0, 1, 2], true)) {
            Log::warning('DLNA lease coordination returned an invalid result.', [
                'phase' => 'acquire',
                'result_type' => get_debug_type($result),
            ]);
            $this->disconnect();
            throw new DlnaUnavailable('DLNA_COORDINATION_UNAVAILABLE', 'DLNA 设备占用协调响应无效。');
        }
        if ($result === 0) {
            throw new DlnaUnavailable('DLNA_DEVICE_BUSY', 'DLNA 设备正由其他账号控制。');
        }
        return $result === 1;
    }

    /** {@inheritDoc} */
    public function refresh(array $actor, string $deviceId): void
    {
        $this->acquire($actor, $deviceId, true);
    }

    /** {@inheritDoc} */
    public function release(array $actor, string $deviceId): void
    {
        [$key, $owner] = $this->identity($actor, $deviceId);
        try {
            $this->connection()->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA, [$key, $owner], 1);
        } catch (Throwable $failure) {
            self::logCoordinationFailure('release', $failure);
            $this->disconnect();
            throw new DlnaUnavailable('DLNA_COORDINATION_UNAVAILABLE', 'DLNA 设备占用协调暂时不可用。', $failure);
        }
    }

    /**
     * 生成不泄露账号或 UDN 的稳定 Redis 身份。
     *
     * @param array<string,mixed> $actor
     * @return array{string,string}
     */
    private function identity(array $actor, string $deviceId): array
    {
        $userId = $actor['id'] ?? null;
        if (!is_string($userId) || $userId === ''
            || preg_match('/^uuid:[A-Za-z0-9._:-]{1,180}$/D', $deviceId) !== 1) {
            throw new DlnaUnavailable('DLNA_PERMISSION_DENIED', 'DLNA 租约身份无效。');
        }
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-dlna-lease-key-v1');
        $device = hash_hmac('sha256', "device\0" . $deviceId, $key);
        $owner = hash_hmac('sha256', "account\0" . $userId, $key);
        return [self::PREFIX . $device, $owner];
    }

    /** 创建每个 Worker 复用的短超时持久连接，认证或选库失败不会回退到未认证 Redis。 */
    private function connection(): Redis
    {
        if (self::$connection instanceof Redis) return self::$connection;
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('REDIS_EXTENSION_UNAVAILABLE');
        }
        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 27379)));
        $timeout = max(0.05, min(0.5, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.2)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-dlna-lease-' . getmypid(), 0, $timeout)) {
            throw new RuntimeException('DLNA_LEASE_CONNECT_FAILED');
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('DLNA_LEASE_AUTH_FAILED');
        }
        $database = max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0)));
        if (!$redis->select($database)) throw new RuntimeException('DLNA_LEASE_SELECT_FAILED');
        self::$connection = $redis;
        return $redis;
    }

    /** 丢弃本 Worker 的故障连接；下一次请求重新建立，不能复用未知状态 socket。 */
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

    /**
     * 记录不会泄露账号、设备、Redis 地址或脚本参数的协调失败摘要。
     *
     * 日志只用于区分 acquire/release 阶段和底层异常类型；不得加入异常 message、连接配置、Redis key、
     * owner 或设备 UDN。记录失败不改变原异常和失败关闭语义，也不触发重试或补偿。
     */
    private static function logCoordinationFailure(string $phase, Throwable $failure): void
    {
        Log::warning('DLNA lease coordination failed.', [
            'phase' => $phase,
            'exception_class' => $failure::class,
            'exception_code' => (string) $failure->getCode(),
        ]);
    }
}
