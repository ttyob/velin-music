<?php

declare(strict_types=1);

namespace app\infrastructure\Observability;

use Throwable;

/**
 * 以有界批次聚合多个 Webman HTTP Worker 的请求计数与延迟直方图（ND-307）。
 *
 * 每个 Worker 先在进程内累加固定低基数标签，达到 50 个请求或间隔 5 秒后才通过 `flock` 合并到
 * runtime 中的单一 JSON 状态文件，避免每个请求访问 SQLite/Redis 或争用文件锁。状态仅包含方法、固定
 * 路由组、状态码类别和整数计数，不保存 URL 参数、用户、IP、请求头或请求体。落盘失败不得影响业务
 * 响应；未刷新的少量样本允许在进程异常退出时丢失，这是监控数据而非审计事实。
 *
 * 文件可能位于持久 volume，但不属于业务备份或恢复输入。读取和写入都拒绝符号链接、限制 JSON 大小
 * 并持有共享/独占锁；损坏内容按空状态处理，绝不能因为指标损坏阻断播放、管理或恢复流程。
 */
final class HttpMetricStore
{
    /** @var list<int> */
    private const BUCKETS_US = [5_000, 10_000, 25_000, 50_000, 100_000, 250_000, 500_000, 1_000_000, 2_500_000, 5_000_000, 10_000_000];
    private const FLUSH_EVENTS = 50;
    private const FLUSH_SECONDS = 5.0;
    private const MAX_STATE_BYTES = 1_048_576;

    /** @var array<string,int> */
    private static array $pending = [];
    private static int $pendingEvents = 0;
    private static float $lastFlushAt = 0.0;

    /**
     * 记录一个已完成或异常终止的同步 HTTP 调度。
     *
     * durationUs 使用单调时钟计算并限制在 0 至 1 小时；长音频响应这里只覆盖鉴权/计划生成，不把已
     * 移交事件循环的完整流生命周期误算成同步 Controller 延迟。任何内部文件错误均被吞掉，调用方
     * 无需也不得据此更改 HTTP 状态。
     */
    public function record(string $method, string $path, int $status, int $durationUs): void
    {
        try {
            $method = $this->method($method);
            $group = $this->routeGroup($path);
            $statusClass = $status >= 100 && $status <= 599 ? intdiv($status, 100) . 'xx' : 'unknown';
            $durationUs = max(0, min(3_600_000_000, $durationUs));
            $prefix = $method . '|' . $group . '|' . $statusClass;
            $this->increment('http_requests_total|' . $prefix, 1);
            $this->increment('http_duration_count|' . $prefix, 1);
            $this->increment('http_duration_sum_us|' . $prefix, $durationUs);
            foreach (self::BUCKETS_US as $bucket) {
                if ($durationUs <= $bucket) $this->increment('http_duration_bucket|' . $prefix . '|' . $bucket, 1);
            }
            ++self::$pendingEvents;
            $now = microtime(true);
            if (self::$lastFlushAt === 0.0) self::$lastFlushAt = $now;
            if (self::$pendingEvents >= self::FLUSH_EVENTS || $now - self::$lastFlushAt >= self::FLUSH_SECONDS) {
                $this->flush();
            }
        } catch (Throwable) {
            // 指标是旁路诊断，任何失败都不能改变真实业务响应或形成递归错误日志。
        }
    }

    /**
     * 把当前进程的待写增量合并到共享状态。
     *
     * 成功持久化后才清空进程内增量；获取锁、目录身份、编码或写入失败时保留增量供下一批重试。文件
     * 更新使用锁内截断重写，因为读者也持有同一锁；状态不要求 fsync 级耐久性，不占用业务事务。
     */
    public function flush(): void
    {
        if (self::$pending === []) return;
        $directory = $this->directory();
        if ((!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
            || is_link($directory) || !is_writable($directory)) return;
        @chmod($directory, 0700);
        $path = $this->path();
        if (is_link($path)) return;
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) return;
        try {
            @chmod($path, 0600);
            if (!flock($handle, LOCK_EX)) return;
            $state = $this->readLocked($handle);
            foreach (self::$pending as $key => $delta) {
                $state[$key] = max(0, (int) ($state[$key] ?? 0) + $delta);
            }
            $encoded = json_encode(['version' => 1, 'counters' => $state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($encoded) > self::MAX_STATE_BYTES) return;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) return;
            self::$pending = [];
            self::$pendingEvents = 0;
            self::$lastFlushAt = microtime(true);
        } catch (Throwable) {
            // 保留待写增量，下一次请求或 Prometheus 抓取会再次尝试。
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 返回共享累计计数的只读快照，并先尽力刷新当前 Worker 的增量。
     *
     * 其他 Worker 最多存在 49 个或约 5 秒未刷新的样本；这一明确最终一致窗口换取热路径无逐请求锁。
     * 返回键和值经过白名单/整数校验，不把损坏 JSON 或任意文件内容交给 Prometheus 渲染器。
     *
     * @return array<string,int>
     */
    public function snapshot(): array
    {
        $this->flush();
        $path = $this->path();
        if (!is_file($path) || is_link($path)) return [];
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) return [];
        try {
            if (!flock($handle, LOCK_SH)) return [];
            return $this->readLocked($handle);
        } catch (Throwable) {
            return [];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 把实际 URL 收敛为固定路由组，防止歌曲/任务 ULID 和搜索参数形成高基数标签或泄露使用轨迹。
     */
    public function routeGroup(string $path): string
    {
        $path = '/' . ltrim(explode('?', $path, 2)[0], '/');
        return match (true) {
            $path === '/metrics' => 'metrics',
            $path === '/api/v1/health' => 'health',
            str_starts_with($path, '/api/v1/auth'), $path === '/api/v1/me', $path === '/api/v1/setup' => 'auth',
            str_starts_with($path, '/api/v1/admin') => 'admin',
            str_starts_with($path, '/api/v1/streams') => 'stream',
            str_starts_with($path, '/api/v1') => 'api',
            str_starts_with($path, '/rest') => 'subsonic',
            default => 'frontend',
        };
    }

    /** @return list<int> 返回渲染器使用的固定累计桶上界，单位为微秒。 */
    public static function bucketsUs(): array
    {
        return self::BUCKETS_US;
    }

    /** 累加一个由本类构造的固定键；PHP 64 位整数足以容纳长期请求和微秒总和。 */
    private function increment(string $key, int $delta): void
    {
        self::$pending[$key] = (self::$pending[$key] ?? 0) + $delta;
    }

    /** 只保留常见 HTTP 方法，未知方法统一聚合以限制标签集合。 */
    private function method(string $method): string
    {
        $method = strtoupper($method);
        return in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            ? $method : 'OTHER';
    }

    /** 在已持有文件锁时读取最多 1 MiB 状态，并逐项过滤格式和值域。 */
    private function readLocked(mixed $handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle, self::MAX_STATE_BYTES + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_STATE_BYTES) return [];
        $decoded = json_decode($raw, true);
        $counters = is_array($decoded) && ($decoded['version'] ?? null) === 1
            && is_array($decoded['counters'] ?? null) ? $decoded['counters'] : [];
        $result = [];
        foreach ($counters as $key => $value) {
            if (is_string($key) && strlen($key) <= 160
                && preg_match('/^[a-z0-9_|]+$/i', $key) === 1
                && is_int($value) && $value >= 0) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** 指标目录只从部署 runtime 根派生，不接受请求参数或媒体路径。 */
    private function directory(): string
    {
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        return rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'metrics';
    }

    /** 返回共享指标文件固定位置；调用前仍必须复验目录和符号链接身份。 */
    private function path(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . 'http-counters-v1.json';
    }
}
