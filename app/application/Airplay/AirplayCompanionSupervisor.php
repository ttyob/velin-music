<?php

declare(strict_types=1);

namespace app\application\Airplay;

/**
 * 串行协调 backend 容器内 OwnTone、Avahi 与 D-Bus companion 进程组。
 *
 * 进程细节封闭在镜像内固定 helper；本类不接受 HTTP 参数、可执行路径或 PID。跨 Worker 文件锁保证后台
 * 保存与周期恢复不会并发启停。helper 会在发送信号前优先核对 `/proc/<pid>/exe`；D-Bus 按系统策略
 * 降权导致 exe 不可读时，还必须同时匹配固定 messagebus UID 与 argv0，因此陈旧 PID 或 PID 复用会
 * 失败关闭而不会误杀其它进程。设置与进程无法事务化：启动失败返回 false，周期 Worker 后续重试；
 * 停止失败抛出稳定领域错误并保留现场供运维检查，不删除 OwnTone 的持久数据库。
 */
final class AirplayCompanionSupervisor
{
    /** 开启全部 companion；已经健康运行时幂等成功。 */
    public function start(): bool
    {
        return $this->execute('start');
    }

    /** 逆序停止全部 companion；全部停止或 PID 文件不存在时幂等成功。 */
    public function stop(): void
    {
        if (!$this->execute('stop')) {
            throw new AirplayUnavailable('AIRPLAY_COMPANION_STOP_FAILED', 'AirPlay companion 停止失败。');
        }
    }

    /** 仅当 D-Bus、Avahi 和 OwnTone 的 PID 身份均匹配且 OwnTone API 可达时返回 true。 */
    public function running(): bool
    {
        return $this->execute('status');
    }

    /**
     * 在固定 runtime 锁内运行无参数扩展能力的 helper 动作。
     *
     * stdout/stderr 丢弃，避免底层网络或路径信息进入 API 日志；退出码是唯一返回契约。helper 缺失、
     * 符号链接或 proc_open 失败均返回 false，锁创建失败则抛出稳定 companion 错误。
     */
    private function execute(string $action): bool
    {
        $helper = (string) (getenv('VELIN_AIRPLAY_COMPANION_PATH') ?: base_path('bin/velin-airplay-companion'));
        if (!in_array($action, ['start', 'stop', 'status'], true)
            || !is_file($helper) || is_link($helper) || !is_executable($helper)) {
            return false;
        }
        $lockPath = runtime_path('velin-airplay-companion.lock');
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new AirplayUnavailable('AIRPLAY_COMPANION_SUPERVISOR_UNAVAILABLE',
                'AirPlay companion 生命周期协调不可用。');
        }
        try {
            $pipes = [];
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
