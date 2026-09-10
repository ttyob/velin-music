<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\Storage\StorageCapacityProbe;
use app\application\Storage\StorageLayout;
use app\domain\System\HealthProbe;
use app\infrastructure\Database\SqliteHealthProbe;
use app\infrastructure\System\LinuxProcessInspector;
use app\infrastructure\System\RedisSessionHealthProbe;
use Closure;
use support\Db;
use Throwable;

/**
 * 聚合仅系统管理员可见的受管进程、依赖和固定存储健康快照。
 *
 * 固定服务目录只在内部判断必需进程是否缺失，不作为重复列表返回；Linux 探针只返回白名单 PID 和
 * 资源数值，存储只读取
 * `/data`、`/storage` 两个逻辑根且响应不含物理路径。所有读取均为点时快照，不启动、停止或修复进程，
 * 不执行扫描或数据库写入；单项探测失败保留 unknown/稳定 reasonCode，避免把缺失数据伪装为正常。
 */
final class SystemHealthMonitorService
{
    private const LABELS = [
        'container-init' => '容器初始化进程',
        'workerman-master' => 'Workerman 主进程',
        'redis' => 'Redis',
        'velin-redis-companion' => 'Redis 生命周期',
        'velin-gateway' => 'Web 网关',
        'velin-http' => 'Web API',
        'velin-scan' => '音乐库扫描',
        'velin-scan-automation' => '扫描自动化',
        'velin-external-sync' => '外部歌单同步',
        'velin-derived-media' => '派生媒体任务',
        'velin-upload' => '上传处理',
        'velin-maintenance' => '运行维护',
        'velin-airplay-companion' => 'AirPlay 生命周期',
        'velin-metadata' => '元数据任务',
        'velin-playback-prefetch' => '远程播放预缓存',
        'velin-plugin-dispatch' => '插件任务调度',
        'velin-plugin-events' => '插件事件投递',
        'velin-plugin-transcode' => '插件转码',
        'velin-playlist-completion' => '歌单自动补全',
        'velin-monitor' => '源码监视',
        'owntone' => 'OwnTone',
        'avahi' => 'Avahi',
        'dbus' => 'D-Bus',
        'library-watch-helper' => '音乐库文件监听',
        'dlna-helper' => 'DLNA 投放',
    ];

    private const ON_DEMAND = ['velin-upload', 'velin-playlist-completion', 'library-watch-helper', 'dlna-helper'];
    private const CORE = ['container-init', 'workerman-master', 'redis', 'velin-redis-companion', 'velin-gateway', 'velin-http'];
    private const STATUS_RANK = ['normal' => 0, 'unknown' => 1, 'attention' => 2, 'critical' => 3];

    /** @var array<string,array<string,mixed>> */
    private array $processDefinitions;
    /** @var list<array{key:string,label:string,path:string}> */
    private array $storageRoots;
    private bool $containerized;

    /**
     * 注入参数只供内部测试和故障演练，HTTP 层不能提供 proc/cgroup 路径、服务目录或容量读取器。
     *
     * @param array<string,array<string,mixed>>|null $processDefinitions
     * @param list<array{key:string,label:string,path:string}>|null $storageRoots
     */
    public function __construct(
        private readonly LinuxProcessInspector $processInspector = new LinuxProcessInspector(),
        private readonly StorageCapacityProbe $capacityProbe = new StorageCapacityProbe(),
        private readonly HealthProbe $databaseProbe = new SqliteHealthProbe(),
        private readonly HealthProbe $sessionProbe = new RedisSessionHealthProbe(),
        private readonly ?Closure $processSnapshotReader = null,
        private readonly ?Closure $airplayEnabledReader = null,
        private readonly ?Closure $systemErrorReader = null,
        ?array $processDefinitions = null,
        ?array $storageRoots = null,
        ?bool $containerized = null,
    ) {
        $this->containerized = $containerized ?? is_file(base_path('.velin-container'));
        $configured = config('process', []);
        $this->processDefinitions = $processDefinitions
            ?? (is_array($configured) && $configured !== [] ? $configured : $this->fallbackProcessDefinitions());
        $this->storageRoots = $storageRoots ?? [
            ['key' => 'data', 'label' => '应用数据', 'path' => StorageLayout::DATA_ROOT],
            ['key' => 'storage', 'label' => '媒体存储', 'path' => StorageLayout::STORAGE_ROOT],
        ];
    }

    /**
     * 生成一次完整监控快照；返回数据只适合后台展示，不替代 Prometheus 长期趋势或告警系统。
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $linux = $this->processSnapshotReader instanceof Closure
            ? ($this->processSnapshotReader)()
            : $this->processInspector->inspect();
        if (!is_array($linux) || !is_array($linux['system'] ?? null)
            || !is_array($linux['processes'] ?? null)) {
            throw new \UnexpectedValueException('Invalid internal process snapshot.');
        }
        $processes = $this->decorateProcesses($linux['processes']);
        $services = $this->services($processes);
        $dependencies = [
            $this->dependency('sqlite', 'SQLite', $this->databaseProbe),
            $this->dependency('redis', 'Session Redis', $this->sessionProbe),
        ];
        $storage = array_map(fn (array $root): array => $this->storage($root), $this->storageRoots);
        $errors = $this->systemErrors();
        $resources = $this->resources($linux['system']);
        $alerts = $this->alerts($services, $dependencies, $storage, $resources, $errors);

        $status = 'normal';
        foreach ($alerts as $alert) {
            $status = $this->worst($status, $alert['severity']);
        }
        if ($alerts === [] && ($resources['status'] === 'unknown'
            || in_array('unknown', array_column($dependencies, 'status'), true))) {
            $status = 'unknown';
        }

        return [
            'status' => $status,
            'containerized' => $this->containerized,
            'sampledAt' => gmdate('c'),
            'refreshAfterSeconds' => 5,
            'summary' => [
                'runningProcesses' => count(array_filter($processes, static fn (array $process): bool =>
                    in_array($process['state'], ['running', 'sleeping'], true))),
                'abnormalProcesses' => count(array_filter($processes, static fn (array $process): bool =>
                    in_array($process['state'], ['zombie', 'unknown'], true))),
                'managedProcesses' => count($processes),
                'managedProcessMemoryBytes' => array_sum(array_map(
                    static fn (array $process): int => is_int($process['memoryBytes']) ? $process['memoryBytes'] : 0,
                    $processes,
                )),
                'alertCount' => count($alerts),
            ],
            'resources' => $resources,
            'dependencies' => $dependencies,
            'storage' => $storage,
            'processes' => $processes,
            'systemErrors' => $errors,
            'alerts' => $alerts,
        ];
    }

    /** @param list<array<string,int|float|string|null>> $processes @return list<array<string,int|float|string|null>> */
    private function decorateProcesses(array $processes): array
    {
        return array_map(static function (array $process): array {
            $serviceKey = (string) $process['serviceKey'];
            return ['name' => self::LABELS[$serviceKey] ?? $serviceKey] + $process;
        }, $processes);
    }

    /** @param list<array<string,mixed>> $processes @return list<array<string,mixed>> */
    private function services(array $processes): array
    {
        $catalog = [];
        if ($this->containerized) {
            $catalog['container-init'] = ['enabled' => true, 'count' => 1];
            $catalog['redis'] = ['enabled' => true, 'count' => 1];
        }
        $catalog['workerman-master'] = ['enabled' => true, 'count' => 1];
        foreach ($this->processDefinitions as $key => $definition) {
            if (!is_string($key) || !str_starts_with($key, 'velin-') || !is_array($definition)) continue;
            $catalog[$key] = [
                'enabled' => ($definition['enable'] ?? true) !== false,
                'count' => max(1, (int) ($definition['count'] ?? 1)),
            ];
        }

        $airplayEnabled = $this->airplayEnabled();
        foreach (['dbus', 'avahi', 'owntone'] as $key) {
            $catalog[$key] = ['enabled' => $airplayEnabled, 'count' => 1];
        }
        foreach (['library-watch-helper', 'dlna-helper'] as $key) {
            $catalog[$key] = ['enabled' => false, 'count' => 1];
        }
        foreach ($processes as $process) {
            $key = (string) $process['serviceKey'];
            $catalog[$key] ??= ['enabled' => null, 'count' => 1];
        }

        $grouped = [];
        foreach ($processes as $process) {
            $grouped[(string) $process['serviceKey']][] = $process;
        }
        $services = [];
        foreach ($catalog as $key => $definition) {
            $members = $grouped[$key] ?? [];
            $healthy = count(array_filter($members, static fn (array $process): bool => $process['state'] !== 'zombie'));
            $zombies = count($members) - $healthy;
            $expected = $definition['enabled'] === true ? (int) $definition['count'] : 0;
            $onDemand = in_array($key, self::ON_DEMAND, true);
            [$status, $reasonCode] = $this->serviceStatus(
                $definition['enabled'], $expected, $healthy, $zombies, $onDemand,
            );
            $cpu = array_sum(array_map(
                static fn (array $process): float => is_float($process['cpuPercent']) ? $process['cpuPercent'] : 0.0,
                $members,
            ));
            $memory = array_sum(array_map(
                static fn (array $process): int => is_int($process['memoryBytes']) ? $process['memoryBytes'] : 0,
                $members,
            ));
            $uptimes = array_values(array_filter(array_column($members, 'uptimeSeconds'), 'is_int'));
            $services[] = [
                'key' => $key,
                'name' => self::LABELS[$key] ?? $key,
                'kind' => in_array($key, self::CORE, true) ? 'core'
                    : ($onDemand || in_array($key, ['owntone', 'avahi', 'dbus'], true) ? 'optional' : 'worker'),
                'status' => $status,
                'reasonCode' => $reasonCode,
                'expectedInstances' => $definition['enabled'] === null ? null : $expected,
                'runningInstances' => $healthy,
                'cpuPercent' => $members === [] ? null : round($cpu, 1),
                'memoryBytes' => $members === [] ? null : $memory,
                'uptimeSeconds' => $uptimes === [] ? null : min($uptimes),
            ];
        }
        usort($services, static fn (array $left, array $right): int =>
            [array_search($left['kind'], ['core', 'worker', 'optional'], true), $left['name']]
            <=> [array_search($right['kind'], ['core', 'worker', 'optional'], true), $right['name']]);
        return $services;
    }

    /** @return array{0:string,1:string} */
    private function serviceStatus(mixed $enabled, int $expected, int $running, int $zombies, bool $onDemand): array
    {
        if ($zombies > 0) return ['degraded', 'ZOMBIE_PROCESS'];
        if ($enabled === null) return $running > 0 ? ['running', 'READY'] : ['unknown', 'DESIRED_STATE_UNKNOWN'];
        if ($enabled === false) {
            if ($running === 0) return ['disabled', $onDemand ? 'ON_DEMAND_IDLE' : 'DISABLED'];
            return $onDemand ? ['running', 'ON_DEMAND_ACTIVE'] : ['degraded', 'UNEXPECTED_PROCESS'];
        }
        if ($running === 0) return ['stopped', 'PROCESS_MISSING'];
        if ($running !== $expected) return ['degraded', 'INSTANCE_COUNT_MISMATCH'];
        return ['running', 'READY'];
    }

    /** @return array{key:string,name:string,status:string,reasonCode:string} */
    private function dependency(string $key, string $name, HealthProbe $probe): array
    {
        try {
            $sample = $probe->inspect();
            $ready = in_array($sample['status'] ?? null, ['ready', 'not_required'], true);
            return ['key' => $key, 'name' => $name, 'status' => $ready ? 'ready' : 'unavailable',
                'reasonCode' => $ready ? 'READY' : strtoupper($key) . '_MISCONFIGURED'];
        } catch (Throwable) {
            return ['key' => $key, 'name' => $name, 'status' => 'unavailable',
                'reasonCode' => strtoupper($key) . '_UNAVAILABLE'];
        }
    }

    /** @param array{key:string,label:string,path:string} $root @return array<string,mixed> */
    private function storage(array $root): array
    {
        $path = $root['path'];
        $capacity = is_dir($path) && !is_link($path) && is_readable($path)
            ? $this->capacityProbe->bytes($path) : null;
        if ($capacity === null) {
            return ['key' => $root['key'], 'name' => $root['label'], 'status' => 'unknown',
                'reasonCode' => 'CAPACITY_UNAVAILABLE', 'totalBytes' => null, 'usedBytes' => null,
                'freeBytes' => null, 'usedPercent' => null];
        }
        $used = $capacity['total'] - $capacity['free'];
        $usedPercent = round($used / $capacity['total'] * 100, 1);
        $status = $usedPercent >= 95 ? 'critical' : ($usedPercent >= 90 ? 'attention' : 'normal');
        return ['key' => $root['key'], 'name' => $root['label'], 'status' => $status,
            'reasonCode' => $status === 'critical' ? 'SPACE_CRITICAL' : ($status === 'attention' ? 'SPACE_LOW' : 'READY'),
            'totalBytes' => $capacity['total'], 'usedBytes' => $used, 'freeBytes' => $capacity['free'],
            'usedPercent' => $usedPercent];
    }

    /** @param array<string,int|float|null> $system @return array<string,int|float|string|null> */
    private function resources(array $system): array
    {
        $cpuCapacity = is_float($system['cpuCapacityCores']) ? $system['cpuCapacityCores'] : null;
        $cpuPercent = is_float($system['cpuPercent']) ? $system['cpuPercent'] : null;
        $memoryPercent = is_float($system['memoryPercent']) ? $system['memoryPercent'] : null;
        $status = ($cpuPercent === null && $system['memoryUsedBytes'] === null) ? 'unknown' : 'normal';
        if (($cpuCapacity !== null && $cpuPercent !== null && $cpuPercent >= $cpuCapacity * 95)
            || ($memoryPercent !== null && $memoryPercent >= 95)) {
            $status = 'critical';
        } elseif (($cpuCapacity !== null && $cpuPercent !== null && $cpuPercent >= $cpuCapacity * 80)
            || ($memoryPercent !== null && $memoryPercent >= 85)) {
            $status = 'attention';
        }
        return ['status' => $status] + $system;
    }

    /** @return array{status:string,open:int|null,critical:int|null} */
    private function systemErrors(): array
    {
        try {
            if ($this->systemErrorReader instanceof Closure) {
                $counts = ($this->systemErrorReader)();
            } elseif (!Db::connection()->getSchemaBuilder()->hasTable('system_error_events')) {
                return ['status' => 'unknown', 'open' => null, 'critical' => null];
            } else {
                $counts = [
                    'open' => Db::table('system_error_events')->where('status', 'open')->count(),
                    'critical' => Db::table('system_error_events')->where('status', 'open')
                        ->where('severity', 'critical')->count(),
                ];
            }
            if (!is_array($counts) || !is_int($counts['open'] ?? null) || !is_int($counts['critical'] ?? null)) {
                return ['status' => 'unknown', 'open' => null, 'critical' => null];
            }
            return ['status' => $counts['open'] > 0 ? 'attention' : 'normal',
                'open' => max(0, $counts['open']), 'critical' => max(0, $counts['critical'])];
        } catch (Throwable) {
            return ['status' => 'unknown', 'open' => null, 'critical' => null];
        }
    }

    /** @return list<array{severity:string,code:string,sourceType:string,sourceKey:string,message:string}> */
    private function alerts(array $services, array $dependencies, array $storage, array $resources, array $errors): array
    {
        $alerts = [];
        foreach ($services as $service) {
            if (!in_array($service['status'], ['degraded', 'stopped', 'unknown'], true)) continue;
            $severity = $service['status'] === 'stopped' ? 'critical' : ($service['status'] === 'degraded' ? 'attention' : 'unknown');
            $alerts[] = ['severity' => $severity, 'code' => $service['reasonCode'], 'sourceType' => 'service',
                'sourceKey' => $service['key'], 'message' => $service['name'] . ($service['status'] === 'stopped' ? '未运行。' : '状态需要检查。')];
        }
        foreach ($dependencies as $dependency) {
            if ($dependency['status'] === 'ready') continue;
            $alerts[] = ['severity' => 'critical', 'code' => $dependency['reasonCode'], 'sourceType' => 'dependency',
                'sourceKey' => $dependency['key'], 'message' => $dependency['name'] . '不可用。'];
        }
        foreach ($storage as $disk) {
            if ($disk['status'] === 'normal') continue;
            $severity = in_array($disk['status'], ['critical', 'attention'], true) ? $disk['status'] : 'unknown';
            $alerts[] = ['severity' => $severity, 'code' => $disk['reasonCode'], 'sourceType' => 'storage',
                'sourceKey' => $disk['key'], 'message' => $disk['name'] . ($disk['status'] === 'unknown' ? '容量暂时无法读取。' : '剩余空间不足。')];
        }
        if ($resources['status'] === 'critical' || $resources['status'] === 'attention') {
            $alerts[] = ['severity' => $resources['status'], 'code' => 'RESOURCE_PRESSURE', 'sourceType' => 'system',
                'sourceKey' => 'resources', 'message' => '容器 CPU 或内存占用较高。'];
        }
        if (is_int($errors['open']) && $errors['open'] > 0) {
            $alerts[] = ['severity' => $errors['critical'] > 0 ? 'critical' : 'attention', 'code' => 'OPEN_SYSTEM_ERRORS',
                'sourceType' => 'system_error', 'sourceKey' => 'errors',
                'message' => '存在 ' . $errors['open'] . ' 条待处理系统异常。'];
        }
        return $alerts;
    }

    private function airplayEnabled(): ?bool
    {
        try {
            $value = $this->airplayEnabledReader instanceof Closure
                ? ($this->airplayEnabledReader)() : (new AirplaySettingsService())->get()['enabled'];
            return is_bool($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,array<string,int|bool>> */
    private function fallbackProcessDefinitions(): array
    {
        return [
            'velin-redis-companion' => ['enable' => $this->containerized, 'count' => 1],
            'velin-gateway' => ['enable' => true, 'count' => 1],
            'velin-http' => ['enable' => true, 'count' => max(1, (int) (getenv('VELIN_WEB_WORKERS') ?: 2))],
            'velin-scan' => ['enable' => $this->boolEnv('VELIN_SCAN_WORKER_ENABLED', true), 'count' => 1],
            'velin-scan-automation' => ['enable' => $this->boolEnv('VELIN_SCAN_AUTOMATION_ENABLED', true), 'count' => 1],
            'velin-external-sync' => ['enable' => $this->boolEnv('VELIN_LASTFM_SYNC_WORKER_ENABLED', false), 'count' => 1],
            'velin-derived-media' => ['enable' => true, 'count' => 1],
            'velin-upload' => ['enable' => false, 'count' => 1],
            'velin-maintenance' => ['enable' => true, 'count' => 1],
            'velin-airplay-companion' => ['enable' => $this->boolEnv('VELIN_AIRPLAY_WORKER_ENABLED', true), 'count' => 1],
            'velin-metadata' => ['enable' => $this->boolEnv('VELIN_METADATA_BATCH_WORKER_ENABLED', true), 'count' => 1],
            'velin-playback-prefetch' => ['enable' => true, 'count' => 1],
            'velin-plugin-dispatch' => ['enable' => $this->boolEnv('VELIN_RESOURCE_PLUGIN_WORKER_ENABLED', true), 'count' => 1],
            'velin-plugin-events' => ['enable' => $this->boolEnv('VELIN_PLUGIN_EVENT_WORKER_ENABLED', false), 'count' => 1],
            'velin-plugin-transcode' => ['enable' => $this->boolEnv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKER_ENABLED', true),
                'count' => max(1, min(16, (int) (getenv('VELIN_RESOURCE_PLUGIN_TRANSCODE_WORKERS') ?: 2)))],
            'velin-playlist-completion' => ['enable' => false, 'count' => 1],
            'velin-monitor' => ['enable' => $this->boolEnv('VELIN_MONITOR_ENABLED', false), 'count' => 1],
        ];
    }

    private function boolEnv(string $key, bool $default): bool
    {
        return filter_var(getenv($key) ?: ($default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    private function worst(string $current, string $candidate): string
    {
        return (self::STATUS_RANK[$candidate] ?? 1) > (self::STATUS_RANK[$current] ?? 1) ? $candidate : $current;
    }
}
