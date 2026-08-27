<?php

declare(strict_types=1);

namespace app\application\Transcode;

use RuntimeException;

/**
 * Enforces a bounded FFmpeg concurrency limit across all Webman HTTP worker processes.
 *
 * Slot files are fixed server-generated names inside a non-symlink private runtime directory. A
 * non-blocking flock makes admission fail fast instead of tying up a request waiting for capacity.
 * This local mechanism is crash-safe and works without Redis availability; a future multi-host
 * deployment must replace this boundary with a distributed lease service.
 */
final class TranscodeAdmission
{
    /**
     * Acquires one lease or fails without starting FFmpeg.
     *
     * configuredLimit 必须来自已校验的 `system.limits` 快照，并在此再次收敛到 1-16，防止损坏状态耗尽
     * 主机。创建私有锁目录是唯一文件系统副作用；槽位全局共享，不包含用户标识或用户级第二层锁。
     *
     * @throws TranscodeCapacityExceeded All slots are currently held.
     * @throws RuntimeException The private lock directory cannot be safely used.
     */
    public function acquire(int $configuredLimit): TranscodeLease
    {
        $limit = max(1, min(16, $configuredLimit));
        $directory = $this->directory();

        for ($slot = 0; $slot < $limit; ++$slot) {
            $handle = $this->lock($directory . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock');
            if (is_resource($handle)) {
                return new TranscodeLease([$handle], $slot);
            }
        }

        throw new TranscodeCapacityExceeded('TRANSCODE_CAPACITY_EXCEEDED');
    }

    /** 探测全局槽位真实占用；短暂获得的空闲锁立即释放，不改变运行中的 FFmpeg。 */
    public function usage(int $configuredLimit): int
    {
        $directory = $this->directory();
        $held = 0;
        for ($slot = 0; $slot < max(1, min(16, $configuredLimit)); ++$slot) {
            $handle = $this->lock($directory . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock');
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            } else {
                ++$held;
            }
        }
        return $held;
    }

    /** 建立并复验仅服务端可写的私有锁目录。 */
    private function directory(): string
    {
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $directory = rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'transcode-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('TRANSCODE_LOCK_DIRECTORY_CREATE_FAILED');
        }
        if (is_link($directory) || !is_writable($directory)) throw new RuntimeException('TRANSCODE_LOCK_DIRECTORY_UNSAFE');
        @chmod($directory, 0700);
        return $directory;
    }

    /** @return resource|null 非阻塞获取固定锁文件；已占用返回 NULL。 */
    private function lock(string $path): mixed
    {
        $handle = @fopen($path, 'c');
        if (!is_resource($handle)) throw new RuntimeException('TRANSCODE_LOCK_OPEN_FAILED');
        if (@flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        fclose($handle);
        return null;
    }
}
