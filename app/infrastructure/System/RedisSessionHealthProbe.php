<?php

declare(strict_types=1);

namespace app\infrastructure\System;

use app\domain\System\HealthProbe;
use Redis;
use RuntimeException;
use Throwable;

/**
 * 检查当前 Session 驱动依赖的 Redis，而不暴露地址、密码、库号或键（NFR-OPS-003）。
 *
 * 文件 Session 部署返回 `not_required`，不会为了健康检查强行引入 Redis。普通 Redis 使用每个 Webman
 * Worker 复用的持久连接并执行 PING，连接与读取超时上限为 500 ms；失败会清除本进程连接并抛出稳定
 * 异常，由 HealthService 转换为公开 degraded。探测不读写 Session 键，也不执行 KEYS/SCAN。
 */
final class RedisSessionHealthProbe implements HealthProbe
{
    private static ?Redis $connection = null;

    /**
     * 返回 Session 依赖状态；Docker 镜像通过固定标记选择 Redis，与 config/session.php 使用同一默认
     * 规则，避免实际 Session 已依赖 Redis 而健康接口误报 not_required。redis_cluster 当前失败关闭，
     * 避免只探测一个节点后虚假声明整个集群就绪；本方法只读取标记和执行 PING，不修改配置或 Session。
     *
     * @return array{status:string,driver:string}
     */
    public function inspect(): array
    {
        $defaultDriver = is_file(base_path('.velin-container')) ? 'redis' : 'file';
        $driver = (string) (getenv('VELIN_SESSION_DRIVER') ?: $defaultDriver);
        if ($driver === 'file') return ['status' => 'not_required', 'driver' => 'file'];
        if ($driver !== 'redis') throw new RuntimeException('SESSION_STORE_UNSUPPORTED');
        try {
            $redis = self::$connection ?? $this->connect();
            $pong = $redis->ping();
            if ($pong !== true && strtoupper((string) $pong) !== '+PONG' && strtoupper((string) $pong) !== 'PONG') {
                throw new RuntimeException('SESSION_STORE_UNAVAILABLE');
            }
            self::$connection = $redis;
            return ['status' => 'ready', 'driver' => 'redis'];
        } catch (Throwable $throwable) {
            self::$connection = null;
            throw new RuntimeException('SESSION_STORE_UNAVAILABLE', previous: $throwable);
        }
    }

    /** 创建经部署配置约束的持久连接；认证失败、选库失败或扩展缺失均不会降级为无认证 Redis。 */
    private function connect(): Redis
    {
        if (!class_exists(Redis::class)) throw new RuntimeException('REDIS_EXTENSION_UNAVAILABLE');
        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 27379)));
        $timeout = max(0.05, min(0.5, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.5)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-health-' . getmypid(), 0, $timeout)) {
            throw new RuntimeException('SESSION_STORE_UNAVAILABLE');
        }
        $password = getenv('VELIN_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$redis->auth($password)) {
            throw new RuntimeException('SESSION_STORE_AUTH_FAILED');
        }
        $database = max(0, min(15, (int) (getenv('VELIN_REDIS_DATABASE') ?: 0)));
        if (!$redis->select($database)) throw new RuntimeException('SESSION_STORE_SELECT_FAILED');
        return $redis;
    }
}
