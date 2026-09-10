<?php

declare(strict_types=1);

namespace app\infrastructure\Dlna;

use Redis;
use Throwable;

/**
 * 在 Redis 中保存 DLNA 媒体响应已实际写入 socket 的短期字节进度。
 *
 * 键只包含数据库票据 ULID，不包含明文票据、用户、歌曲、设备地址或媒体路径；值最多保留两小时，与
 * 播放票据上限一致。多个 Web worker 或多个 Range 响应使用 Lua 原子取最大值，迟到的旧连接不能让进度
 * 倒退。Redis 只是 UI 的可丢失观测数据，连接、认证、写入或读取失败都返回空结果且绝不阻断音频。
 */
final class DlnaDeliveryProgressStore
{
    private const PREFIX = 'velin_dlna_delivery:v1:';
    private const TTL_SECONDS = 7200;

    private static ?Redis $connection = null;
    private static float $unavailableUntil = 0.0;

    /**
     * 记录一个响应已经送达内核 socket 的最远媒体字节。
     *
     * deliveredBytes 与 totalBytes 都是非负字节数，前者在写入前限制到后者。Range 请求以响应 offset 加
     * 实际 body 写出量形成媒体位置；Redis 中只前进不后退，因此并发或重试是幂等的。失败无异常外泄。
     */
    public function record(string $ticketId, int $deliveredBytes, int $totalBytes): void
    {
        if (!$this->validTicketId($ticketId) || $totalBytes <= 0) return;
        $deliveredBytes = max(0, min($totalBytes, $deliveredBytes));
        try {
            $redis = $this->connection();
            if (!$redis instanceof Redis) return;
            $redis->eval(<<<'LUA'
local current = tonumber(redis.call('HGET', KEYS[1], 'delivered') or '0')
local incoming = tonumber(ARGV[1])
local total = tonumber(ARGV[2])
if incoming > current then current = incoming end
if current > total then current = total end
redis.call('HSET', KEYS[1], 'delivered', current, 'total', total)
redis.call('EXPIRE', KEYS[1], ARGV[3])
return current
LUA, [$this->key($ticketId), $deliveredBytes, $totalBytes, self::TTL_SECONDS], 1);
        } catch (Throwable) {
            $this->markUnavailable();
        }
    }

    /**
     * 读取可丢失的投递快照；不存在、过期、损坏或 Redis 暂时不可用均返回 null。
     *
     * @return array{deliveredBytes:int,totalBytes:int}|null
     */
    public function read(string $ticketId): ?array
    {
        if (!$this->validTicketId($ticketId)) return null;
        try {
            $redis = $this->connection();
            if (!$redis instanceof Redis) return null;
            $values = $redis->hMGet($this->key($ticketId), ['delivered', 'total']);
            $delivered = $values['delivered'] ?? false;
            $total = $values['total'] ?? false;
            if ((!is_string($delivered) && !is_int($delivered)) || (!is_string($total) && !is_int($total))) {
                return null;
            }
            $deliveredBytes = (int) $delivered;
            $totalBytes = (int) $total;
            if ($totalBytes <= 0 || $deliveredBytes < 0 || $deliveredBytes > $totalBytes) return null;
            return ['deliveredBytes' => $deliveredBytes, 'totalBytes' => $totalBytes];
        } catch (Throwable) {
            $this->markUnavailable();
            return null;
        }
    }

    /** 每个 Worker 复用一个短超时持久连接，故障后五秒内不反复阻塞播放状态轮询。 */
    private function connection(): ?Redis
    {
        if (self::$connection instanceof Redis) return self::$connection;
        if (microtime(true) < self::$unavailableUntil || !class_exists(Redis::class)) return null;

        $host = (string) (getenv('VELIN_REDIS_HOST') ?: '127.0.0.1');
        $port = max(1, min(65535, (int) (getenv('VELIN_REDIS_PORT') ?: 27379)));
        $timeout = max(0.05, min(0.2, (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 0.1)));
        $redis = new Redis();
        if (!$redis->pconnect($host, $port, $timeout, 'velin-dlna-progress-' . getmypid(), 0, $timeout)) {
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

    /** 丢弃故障连接，短时间退避；唯一业务事实仍由票据表和实时授权持有。 */
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

    private function validTicketId(string $ticketId): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $ticketId) === 1;
    }

    private function key(string $ticketId): string
    {
        return self::PREFIX . $ticketId;
    }
}
