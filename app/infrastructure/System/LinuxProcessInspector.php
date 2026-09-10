<?php

declare(strict_types=1);

namespace app\infrastructure\System;

/**
 * 从 Linux procfs 与 cgroup v2 读取 Velin 管理进程的资源快照。
 *
 * 本探针只识别固定 Workerman 名称和镜像内受管二进制，不返回 cmdline、环境变量、打开文件或宿主其他
 * 进程。CPU 通过两个相距 100ms 的只读样本计算；内存读取 `smaps_rollup` 的 PSS，把共享页按进程
 * 比例分摊，避免多个 PHP Worker 的共享代码页在合计中重复计算。进程在采样期间退出时直接从结果
 * 移除，新启动进程的 CPU 暂记为 null；PSS 不可读时内存返回 null，不回退到虚高 RSS。探针不创建
 * 文件、不发信号，也没有回滚副作用。容器内存采用 Docker 在 cgroup v2 上的工作集口径，即从
 * `memory.current` 扣除 `memory.stat` 的 `inactive_file`；可回收文件缓存不会伪装成实际内存压力。
 */
final class LinuxProcessInspector
{
    private const CLOCK_TICKS_PER_SECOND = 100;

    public function __construct(
        private readonly string $procRoot = '/proc',
        private readonly string $cgroupRoot = '/sys/fs/cgroup',
        private readonly int $sampleIntervalMicros = 100_000,
    ) {
    }

    /**
     * 返回容器可见的系统资源和每个受管进程，不扩大到宿主 PID 命名空间。
     *
     * @return array{system:array<string,int|float|null>,processes:list<array<string,int|float|string|null>>}
     */
    public function inspect(): array
    {
        $first = $this->sample(false);
        $startedAt = hrtime(true);
        if ($this->sampleIntervalMicros > 0) {
            usleep($this->sampleIntervalMicros);
        }
        $second = $this->sample(true);
        $elapsedMicros = max(1.0, (hrtime(true) - $startedAt) / 1_000);

        $processes = [];
        foreach ($second['processes'] as $pid => $process) {
            $previousTicks = $first['processes'][$pid]['cpuTicks'] ?? null;
            $cpuPercent = is_int($previousTicks) && $process['cpuTicks'] >= $previousTicks
                ? (($process['cpuTicks'] - $previousTicks) / self::CLOCK_TICKS_PER_SECOND)
                    / ($elapsedMicros / 1_000_000) * 100
                : null;
            $processes[] = [
                'pid' => $pid,
                'serviceKey' => $process['serviceKey'],
                'state' => $this->state($process['state']),
                'cpuPercent' => $cpuPercent === null ? null : round(max(0.0, $cpuPercent), 1),
                'memoryBytes' => $process['memoryBytes'],
                'uptimeSeconds' => $process['uptimeSeconds'],
            ];
        }
        usort($processes, static fn (array $left, array $right): int =>
            [$left['serviceKey'], $left['pid']] <=> [$right['serviceKey'], $right['pid']]);

        $cpuPercent = null;
        if (is_int($first['cgroupCpuMicros']) && is_int($second['cgroupCpuMicros'])
            && $second['cgroupCpuMicros'] >= $first['cgroupCpuMicros']) {
            $cpuPercent = ($second['cgroupCpuMicros'] - $first['cgroupCpuMicros']) / $elapsedMicros * 100;
        } elseif ($this->sampleIntervalMicros > 0) {
            $cpuPercent = array_sum(array_map(
                static fn (array $process): float => is_float($process['cpuPercent']) ? $process['cpuPercent'] : 0.0,
                $processes,
            ));
        }

        return [
            'system' => [
                'cpuPercent' => $cpuPercent === null ? null : round(max(0.0, $cpuPercent), 1),
                'cpuCapacityCores' => $second['cpuCapacityCores'],
                'memoryUsedBytes' => $second['memoryUsedBytes'],
                'memoryLimitBytes' => $second['memoryLimitBytes'],
                'memoryPercent' => $this->percent($second['memoryUsedBytes'], $second['memoryLimitBytes']),
                'uptimeSeconds' => $second['containerUptimeSeconds'],
            ],
            'processes' => $processes,
        ];
    }

    /** @return array{processes:array<int,array<string,int|string|null>>,cgroupCpuMicros:?int,cpuCapacityCores:?float,memoryUsedBytes:?int,memoryLimitBytes:?int,containerUptimeSeconds:?int} */
    private function sample(bool $includeMemory): array
    {
        $systemUptime = $this->firstNumber($this->read($this->procRoot . '/uptime'));
        $processes = [];
        foreach (glob($this->procRoot . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $directory) {
            $pid = (int) basename($directory);
            if ($pid < 1) continue;
            $command = str_replace("\0", ' ', $this->read($directory . '/cmdline') ?? '');
            $serviceKey = $this->serviceKey(trim($command));
            if ($serviceKey === null) continue;
            $stat = $this->stat($directory . '/stat');
            if ($stat === null) continue;
            $memoryBytes = $includeMemory
                ? $this->statusInteger($directory . '/smaps_rollup', 'Pss')
                : null;
            $uptime = is_float($systemUptime)
                ? max(0, (int) floor($systemUptime - $stat['startTicks'] / self::CLOCK_TICKS_PER_SECOND))
                : null;
            $processes[$pid] = [
                'serviceKey' => $serviceKey,
                'state' => $stat['state'],
                'cpuTicks' => $stat['cpuTicks'],
                'memoryBytes' => $memoryBytes === null ? null : $memoryBytes * 1024,
                'uptimeSeconds' => $uptime,
            ];
        }

        $containerUptime = null;
        $pidOne = $this->stat($this->procRoot . '/1/stat');
        if (is_float($systemUptime) && $pidOne !== null) {
            $containerUptime = max(0, (int) floor(
                $systemUptime - $pidOne['startTicks'] / self::CLOCK_TICKS_PER_SECOND,
            ));
        }

        return [
            'processes' => $processes,
            'cgroupCpuMicros' => $this->cgroupCpuMicros(),
            'cpuCapacityCores' => $this->cpuCapacityCores(),
            'memoryUsedBytes' => $this->memoryWorkingSetBytes(),
            'memoryLimitBytes' => $this->memoryLimit(),
            'containerUptimeSeconds' => $containerUptime,
        ];
    }

    /**
     * 将 cmdline 收敛为固定服务 key；原始命令只在当前调用栈内使用，永不进入返回值或日志。
     */
    private function serviceKey(string $command): ?string
    {
        if ($command === '') return null;
        if (str_contains($command, 'WorkerMan: master process')) return 'workerman-master';
        if (preg_match('/WorkerMan: worker process\s+(velin-[a-z0-9-]+)/i', $command, $matches) === 1) {
            return strtolower($matches[1]);
        }
        foreach ([
            '/sbin/docker-init' => 'container-init',
            '/usr/bin/redis-server' => 'redis',
            '/app/bin/velin-media-gateway' => 'velin-gateway',
            '/usr/sbin/owntone' => 'owntone',
            '/usr/sbin/avahi-daemon' => 'avahi',
            '/usr/bin/dbus-daemon' => 'dbus',
            '/app/bin/velin-library-watch-helper' => 'library-watch-helper',
            '/app/bin/velin-dlna-helper' => 'dlna-helper',
        ] as $needle => $serviceKey) {
            if (str_starts_with($command, $needle . ' ') || $command === $needle) return $serviceKey;
        }
        return null;
    }

    /** @return array{state:string,cpuTicks:int,startTicks:int}|null */
    private function stat(string $path): ?array
    {
        $value = trim($this->read($path) ?? '');
        if (preg_match('/^[0-9]+\s+\(.*\)\s+([A-Z])\s+(.+)$/D', $value, $matches) !== 1) return null;
        $fields = preg_split('/\s+/', $matches[2]) ?: [];
        if (count($fields) < 19 || !ctype_digit($fields[10]) || !ctype_digit($fields[11])
            || !ctype_digit($fields[18])) return null;
        return [
            'state' => $matches[1],
            'cpuTicks' => (int) $fields[10] + (int) $fields[11],
            'startTicks' => (int) $fields[18],
        ];
    }

    private function state(string $state): string
    {
        return match ($state) {
            'R' => 'running',
            'Z', 'X' => 'zombie',
            'S', 'D', 'I', 'T', 't' => 'sleeping',
            default => 'unknown',
        };
    }

    private function cgroupCpuMicros(): ?int
    {
        $value = $this->read($this->cgroupRoot . '/cpu.stat');
        return is_string($value) && preg_match('/^usage_usec\s+([0-9]+)$/m', $value, $matches) === 1
            ? (int) $matches[1] : null;
    }

    private function cpuCapacityCores(): ?float
    {
        $cpuMax = preg_split('/\s+/', trim($this->read($this->cgroupRoot . '/cpu.max') ?? '')) ?: [];
        if (count($cpuMax) === 2 && $cpuMax[0] !== 'max' && ctype_digit($cpuMax[0])
            && ctype_digit($cpuMax[1]) && (int) $cpuMax[1] > 0) {
            return round((int) $cpuMax[0] / (int) $cpuMax[1], 2);
        }
        $stat = $this->read($this->procRoot . '/stat');
        if (!is_string($stat)) return null;
        preg_match_all('/^cpu[0-9]+\s/m', $stat, $matches);
        return count($matches[0]) > 0 ? (float) count($matches[0]) : null;
    }

    private function memoryLimit(): ?int
    {
        $value = trim($this->read($this->cgroupRoot . '/memory.max') ?? '');
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * 返回容器当前不可立即回收的内存工作集。
     *
     * cgroup 的 `memory.current` 包含大量 inactive file cache，直接展示会在媒体扫描或构建后长期虚高；
     * Docker CLI 在 cgroup v2 上同样扣除 `inactive_file`。两个文件任一不可读时返回 null，避免退回到
     * 含义不同的原始计数。只读取内核记账文件，不触发缓存回收或其他系统副作用。
     */
    private function memoryWorkingSetBytes(): ?int
    {
        $current = $this->integerFile($this->cgroupRoot . '/memory.current');
        $stat = $this->read($this->cgroupRoot . '/memory.stat');
        if ($current === null || !is_string($stat)
            || preg_match('/^inactive_file\s+([0-9]+)$/m', $stat, $matches) !== 1) {
            return null;
        }

        return max(0, $current - (int) $matches[1]);
    }

    private function integerFile(string $path): ?int
    {
        $value = trim($this->read($path) ?? '');
        return ctype_digit($value) ? (int) $value : null;
    }

    private function statusInteger(string $path, string $field): ?int
    {
        $value = $this->read($path);
        return is_string($value) && preg_match('/^' . preg_quote($field, '/') . ':\s+([0-9]+)\s+kB$/m', $value, $matches) === 1
            ? (int) $matches[1] : null;
    }

    private function firstNumber(?string $value): ?float
    {
        if (!is_string($value) || preg_match('/^([0-9]+(?:\.[0-9]+)?)/', trim($value), $matches) !== 1) return null;
        return (float) $matches[1];
    }

    private function percent(?int $used, ?int $limit): ?float
    {
        return is_int($used) && is_int($limit) && $limit > 0
            ? round(min(100.0, max(0.0, $used / $limit * 100)), 1) : null;
    }

    private function read(string $path): ?string
    {
        $value = @file_get_contents($path);
        return is_string($value) ? $value : null;
    }
}
