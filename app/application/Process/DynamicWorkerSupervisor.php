<?php

declare(strict_types=1);

namespace app\application\Process;

use RuntimeException;

/**
 * 按需启动不占用 Webman 主进程拓扑的长期任务 Worker。
 *
 * Workerman 的 Worker 列表只在 master 启动阶段构建，HTTP 子进程不能直接追加实例；本类因此使用
 * 固定的本机 CLI 入口启动单个任务循环。worker 类型是代码白名单，不能来自请求拼接命令；运行目录中的
 * 锁和 PID 文件保证多个 HTTP Worker 并发触发时只保留一个实例。子进程继承当前 HTTP Worker 的父关系，
 * CLI 循环会在父进程消失后退出，容器停止时不会留下继续访问 SQLite 的孤儿任务进程。
 *
 * 启动失败只返回 false，不回滚已经提交的业务任务；任务仍持久化在数据库中，后续同类触发会重试拉起。
 * PID 文件只用于诊断和幂等判断，不能作为权限或任务所有权凭据。
 */
final class DynamicWorkerSupervisor
{
    /** @var array<string, resource> */
    private static array $processes = [];

    /** @var array<string, mixed> */
    private const WORKERS = [
        'upload' => ['name' => 'velin-upload', 'command' => 'upload'],
        'playlist-completion' => ['name' => 'velin-playlist-completion', 'command' => 'playlist-completion'],
    ];

    /**
     * 确保一个固定类型的长期 Worker 已经运行。
     *
     * 调用方应在写入任务/开关的事务提交后调用；本方法不接收用户路径或命令参数，也不会等待 Worker
     * 执行任务。已有健康 PID 直接幂等成功，陈旧 PID 会在锁内清理后重新启动；无法创建进程时返回 false。
     */
    public function ensureStarted(string $worker): bool
    {
        $definition = self::WORKERS[$worker] ?? null;
        if (!is_array($definition)) return false;

        $lock = $this->lock($worker);
        try {
            $pidPath = $this->pidPath($worker);
            $pid = $this->readPid($pidPath);
            if ($pid !== null && $this->alive($pid)) return true;
            if (is_file($pidPath) && !is_link($pidPath)) @unlink($pidPath);

            $command = [PHP_BINARY, base_path('bin/velin'), 'worker:run', '--worker=' . $definition['command']];
            $pipes = [];
            $process = @proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
                base_path(),
            );
            if (!is_resource($process)) return false;
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            $status = proc_get_status($process);
            $childPid = is_array($status) && is_int($status['pid'] ?? null) ? $status['pid'] : null;
            if ($childPid === null || $childPid < 1) {
                @proc_close($process);
                return false;
            }
            try {
                $this->writePid($pidPath, $childPid);
            } catch (RuntimeException $failure) {
                if (function_exists('posix_kill')) @posix_kill($childPid, SIGTERM);
                throw $failure;
            }
            // 保留句柄避免 PHP 请求清理阶段同步等待长期子进程；子进程自行通过父 PID 变化退出。
            self::$processes[$worker] = $process;
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 停止一个已经按需启动的长期 Worker。
     *
     * 关闭功能时只发送 SIGTERM 并移除本地 PID 事实，不等待网络/文件任务在 HTTP 请求内完成；当前 tick 会
     * 安全收口后退出，数据库中的任务仍保留可重试状态。重复停止、陈旧 PID 和已经退出的进程均幂等成功。
     */
    public function stop(string $worker): void
    {
        if (!isset(self::WORKERS[$worker])) return;
        $lock = $this->lock($worker);
        try {
            $pidPath = $this->pidPath($worker);
            $pid = $this->readPid($pidPath);
            if ($pid !== null && $this->alive($pid) && function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            }
            if (is_file($pidPath) && !is_link($pidPath)) @unlink($pidPath);
            // 保留句柄直到 HTTP Worker 结束，避免本次请求的资源回收同步等待子进程退出。
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 返回固定进程名，供状态页和日志使用。 */
    public function name(string $worker): ?string
    {
        $definition = self::WORKERS[$worker] ?? null;
        return is_array($definition) && is_string($definition['name'] ?? null) ? $definition['name'] : null;
    }

    /** @return resource */
    private function lock(string $worker)
    {
        $path = runtime_path('dynamic-worker-' . $worker . '.lock');
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('动态 Worker 生命周期协调不可用。');
        }
        return $handle;
    }

    private function pidPath(string $worker): string
    {
        return runtime_path('dynamic-worker-' . $worker . '.pid');
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
            throw new RuntimeException('动态 Worker PID 文件不可写。');
        }
        @chmod($path, 0600);
    }

    private function alive(int $pid): bool
    {
        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }
}
