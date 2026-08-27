<?php

declare(strict_types=1);

namespace app\infrastructure\Dlna;

use app\application\Dlna\DlnaDeviceRouteCache;
use app\http\RequestContext;
use Redis;
use Throwable;

/**
 * 使用现有 Redis 保存两小时的 Renderer 加密路由令牌。
 *
 * Redis key 是用途分离 HMAC 后的 UDN，value 是已由 Sodium secretbox 加密且绑定设备/期限的令牌，因此
 * Redis 中没有明文设备标识、宿主 IP 或 SSDP Location。该缓存只是延迟优化：连接、认证、选库、读写或
 * 删除失败都会短暂退避并表现为未命中，绝不绕过 DlnaService 的实时 play/cast 授权、设备白名单、账号
 * 租约以及 Go helper 对私网 Location 和根 UDN 的复验。
 */
final class RedisDlnaDeviceRouteCache implements DlnaDeviceRouteCache
{
    private const PREFIX = 'velin_dlna_device_route:v1:';
    private const TTL_SECONDS = 7200;

    private static ?Redis $connection = null;
    private static float $unavailableUntil = 0.0;

    /** {@inheritDoc} */
    public function put(string $deviceId, string $routeToken): void
    {
        if (!$this->validDeviceId($deviceId) || $routeToken === '' || strlen($routeToken) > 1024) return;
        try {
            $redis = $this->connection();
            if (!$redis instanceof Redis) return;
            if (!$redis->set($this->key($deviceId), $routeToken, ['ex' => self::TTL_SECONDS])) {
                $this->markUnavailable();
            }
        } catch (Throwable) {
            $this->markUnavailable();
        }
    }

    /** {@inheritDoc} */
    public function get(string $deviceId): ?string
    {
        if (!$this->validDeviceId($deviceId)) return null;
        try {
            $redis = $this->connection();
            if (!$redis instanceof Redis) return null;
            $value = $redis->get($this->key($deviceId));
            return is_string($value) && $value !== '' && strlen($value) <= 1024 ? $value : null;
        } catch (Throwable) {
            $this->markUnavailable();
            return null;
        }
    }

    /** {@inheritDoc} */
    public function forget(string $deviceId): void
    {
        if (!$this->validDeviceId($deviceId)) return;
        try {
            $redis = $this->connection();
            if ($redis instanceof Redis) $redis->del($this->key($deviceId));
        } catch (Throwable) {
            $this->markUnavailable();
        }
    }

    /**
     * 创建每个 Worker 复用的短超时持久连接。
     *
     * 使用项目已有 Redis 默认值，不引入额外 .env；故障后五秒内直接未命中，避免一次 Redis 异常让状态
     * 轮询反复增加连接等待。认证或选库失败时丢弃连接，不能退回未认证默认数据库。
     */
    private function connection(): ?Redis
    {
        if (self::$connection instanceof Redis) return self::$connection;
        if (microtime(true) < self::$unavailableUntil || !class_exists(Redis::class)) return null;

        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 16379)));
        $timeout = max(0.05, min(0.2, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.1)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-dlna-route-' . getmypid(), 0, $timeout)) {
            $this->markUnavailable();
            return null;
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            $this->markUnavailable();
            return null;
        }
        $database = max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0)));
        if (!$redis->select($database)) {
            $this->markUnavailable();
            return null;
        }
        self::$connection = $redis;
        return $redis;
    }

    /** 丢弃状态未知的连接并短暂退避；缓存中没有不可替代业务事实，不需要补偿或重试队列。 */
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

    private function validDeviceId(string $deviceId): bool
    {
        return preg_match('/^uuid:[A-Za-z0-9._:-]{1,180}$/D', $deviceId) === 1;
    }

    /** 使用与租约不同的 HKDF 上下文派生键摘要，避免不同用途的 Redis 身份可相互关联。 */
    private function key(string $deviceId): string
    {
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-dlna-route-cache-key-v1');
        return self::PREFIX . hash_hmac('sha256', "device\0" . $deviceId, $key);
    }
}
