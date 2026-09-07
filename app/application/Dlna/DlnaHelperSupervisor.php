<?php

declare(strict_types=1);

namespace app\application\Dlna;

/**
 * 管理按后台开关动态启停的单个 DLNA helper daemon。
 *
 * daemon 仅在管理员开启 DLNA 后启动，所有 Webman Worker 通过 0600 Unix Socket 复用同一设备连接、
 * GENA 订阅和按设备锁。启动/停止使用运行目录锁和 PID 文件，避免多个 HTTP Worker 并发拉起 daemon；
 * PID、Socket 和日志都位于受控 runtime 目录，不接受请求参数。daemon 不可用时调用方仍可安全回退一次性
 * helper，但启停失败不会改变已经提交的系统设置，也不会把外部网络地址写入日志。
 */
final class DlnaHelperSupervisor
{
    private static mixed $process = null;

    /** 后台启用后启动 daemon；已健康运行时幂等返回。 */
    public function start(): bool
    {
        $path = $this->binaryPath();
        $socket = $this->socketPath();
        $pidFile = $this->pidPath();
        $lock = $this->lock();
        try {
            $pid = $this->readPid($pidFile);
            if ($pid !== null && $this->alive($pid) && $this->socketReady($socket)) return true;
            if ($pid !== null && $this->alive($pid) && function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
            $this->cleanupStale($socket, $pidFile);
            if (!is_file($path) || !is_executable($path) || is_link($path)) return false;
            $pipes = [];
            $process = @proc_open(
                [$path, '--socket=' . $socket],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
            );
            if (!is_resource($process)) return false;
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            self::$process = $process;
            $status = proc_get_status($process);
            $pid = is_array($status) && is_int($status['pid'] ?? null) ? $status['pid'] : null;
            if ($pid === null || $pid < 1) return false;
            $this->writePid($pidFile, $pid);
            $deadline = microtime(true) + 1.5;
            while (microtime(true) < $deadline) {
                if ($this->socketReady($socket)) return true;
                usleep(25_000);
            }
            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 后台关闭后停止 daemon；重复停止和陈旧 PID/Sock 文件均视为幂等成功。 */
    public function stop(): void
    {
        $pidFile = $this->pidPath();
        $socket = $this->socketPath();
        $lock = $this->lock();
        try {
            $pid = $this->readPid($pidFile);
            if ($pid !== null && $this->alive($pid) && function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
                $deadline = microtime(true) + 1.2;
                while (microtime(true) < $deadline && $this->alive($pid)) usleep(25_000);
                if ($this->alive($pid)) @posix_kill($pid, SIGKILL);
            }
            $this->cleanupStale($socket, $pidFile);
            self::$process = null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function lock()
    {
        $path = runtime_path('velin-dlna-helper.lock');
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new DlnaUnavailable('DLNA_HELPER_SUPERVISOR_UNAVAILABLE', 'DLNA helper 生命周期协调不可用。');
        }
        return $handle;
    }

    private function binaryPath(): string
    {
        return (string) (getenv('VELIN_DLNA_HELPER_PATH') ?: base_path('bin/velin-dlna-helper'));
    }

    private function socketPath(): string
    {
        return runtime_path('velin-dlna-helper.sock');
    }

    private function pidPath(): string
    {
        return runtime_path('velin-dlna-helper.pid');
    }

    private function readPid(string $path): ?int
    {
        if (!is_file($path) || is_link($path)) return null;
        $value = trim((string) @file_get_contents($path));
        return preg_match('/^[1-9][0-9]{0,8}$/D', $value) === 1 ? (int) $value : null;
    }

    private function writePid(string $path, int $pid): void
    {
        $temporary = $path . '.tmp-' . getmypid();
        if (@file_put_contents($temporary, $pid . "\n", LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new DlnaUnavailable('DLNA_HELPER_SUPERVISOR_UNAVAILABLE', 'DLNA helper 生命周期协调不可用。');
        }
        @chmod($path, 0600);
    }

    private function alive(int $pid): bool
    {
        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    private function socketReady(string $path): bool
    {
        if (!$this->isSocket($path)) return false;
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client('unix://' . $path, $errorCode, $errorMessage, 0.05);
        if (!is_resource($socket)) return false;
        fclose($socket);
        return true;
    }

    private function cleanupStale(string $socket, string $pidFile): void
    {
        if (is_file($pidFile) && !is_link($pidFile)) @unlink($pidFile);
        if ($this->isSocket($socket) && !is_link($socket)) @unlink($socket);
    }

    /** 仅允许 Unix Socket 类型路径，拒绝普通文件、目录和符号链接。 */
    private function isSocket(string $path): bool
    {
        if (!file_exists($path) || is_link($path)) return false;
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0140000;
    }
}
