<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 串行协调 backend 容器内的固定 Redis Server 进程。
 *
 * 仅容器入口和单实例恢复 Worker 使用本类；裸机部署继续连接部署者管理的 Redis。本类不接受请求参数、
 * 端口、路径或 Redis 命令，跨 Worker 文件锁避免恢复与关闭并发。helper 在发送信号前核对 PID 对应的
 * `/proc/<pid>/exe`，失败时保留现场且不操作未知进程；启动和状态失败返回 false，停止失败抛出稳定异常。
 */
final class RedisCompanionSupervisor
{
    /**
     * 幂等启动固定 Redis 进程并等待回环 PONG。
     *
     * 调用者必须位于 backend 容器；裸机部署不应创建本类。helper 不可用、PID 身份冲突、数据目录异常
     * 或 Redis 未在期限内就绪时返回 false，且不会接管未知进程。成功会创建 runtime PID 文件并继续使用
     * 已挂载数据卷；重复调用不会启动第二个实例，也不会修改 AOF/RDB 内容。
     */
    public function start(): bool
    {
        return $this->execute('start');
    }

    /**
     * 检查 PID 身份与固定回环端口是否同时健康。
     *
     * 本检查只读且可重复调用；PID 文件缺失、损坏、指向其他可执行文件，或 Redis 未返回 PONG 时均返回
     * false。它不尝试修复状态，恢复决策由唯一生命周期 Worker 执行。
     */
    public function running(): bool
    {
        return $this->execute('status');
    }

    /**
     * 对身份匹配的 Redis 发送 SIGTERM 并等待持久化关停。
     *
     * 已停止时幂等成功；PID 身份不明或超时会抛出稳定异常并保留 PID/AOF 现场，不升级为 SIGKILL。调用
     * 可能触发 Redis AOF/RDB 刷盘，因此只允许容器生命周期所有者执行。
     *
     * @throws RedisCompanionUnavailable 无法确认或完成安全关停
     */
    public function stop(): void
    {
        if (!$this->execute('stop')) {
            throw new RedisCompanionUnavailable('Redis companion 停止失败。');
        }
    }

    /**
     * 在固定 runtime 锁内执行无参数扩展能力的 helper 动作。
     *
     * 标准流丢弃，防止内部路径或 Redis 诊断进入请求日志；helper 缺失、链接或不可执行时返回 false。
     * 锁无法创建表示生命周期所有权不可确认，必须抛错而不能绕过串行边界。
     */
    private function execute(string $action): bool
    {
        $helper = base_path('bin/velin-redis-companion');
        if (!in_array($action, ['start', 'stop', 'status'], true)
            || !is_file($helper) || is_link($helper) || !is_executable($helper)) {
            return false;
        }
        $lock = @fopen(runtime_path('velin-redis-companion.lock'), 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RedisCompanionUnavailable('Redis companion 生命周期协调不可用。');
        }
        try {
            $process = @proc_open(
                [$helper, $action],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
                $pipes,
            );
            if (!is_resource($process)) return false;
            return proc_close($process) === 0;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** 表示内置 Redis 生命周期无法安全协调。 */
final class RedisCompanionUnavailable extends \RuntimeException
{
}
