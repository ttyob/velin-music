<?php

declare(strict_types=1);

namespace app\application\Storage;

use app\application\Auth\AuthorizationDenied;

/**
 * Inspects only Velin's registered container roots and fixed worker binaries (ADMIN-STORAGE-001).
 *
 * No request value can select a path or command. Directory probes use metadata, capacity, and mount
 * tables only; they never enumerate media files, create missing directories, or open content. Binary
 * probes execute a fixed `-version` argument with a two-second ceiling and discard output. The result
 * may expose registered paths because callers must hold `manage_storage`; overview consumers should
 * use `summary()` to omit paths and device identifiers.
 */
final class StorageHealthService
{
    private const ATTENTION_FREE_RATIO = 0.10;
    private const CRITICAL_FREE_RATIO = 0.05;

    /** @var list<array{key: string, path: string, required: bool, writeRequired: bool}> */
    private array $roots;

    /** @var array<string, string> */
    private array $binaries;

    /**
     * Production uses the fixed registry; tests may inject temporary roots without changing API input.
     *
     * @param list<array{key: string, path: string, required: bool, writeRequired: bool}>|null $roots
     * @param array<string, string>|null $binaries
     */
    public function __construct(?array $roots = null, ?array $binaries = null)
    {
        $databasePath = (string) (getenv('VELIN_DB_PATH') ?: base_path('database/velin.sqlite'));
        $runtimePath = rtrim((string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime')), DIRECTORY_SEPARATOR);
        $scrapeCachePath = rtrim((string) (getenv('VELIN_SCRAPE_CACHE_PATH') ?: '/media/cache/scrape'), DIRECTORY_SEPARATOR);
        $this->roots = $roots ?? [
            ['key' => 'library', 'path' => '/media/library', 'required' => true, 'writeRequired' => false],
            ['key' => 'scrapeCache', 'path' => $scrapeCachePath, 'required' => true, 'writeRequired' => true],
            ['key' => 'transcodeCache', 'path' => $runtimePath . '/transcode-spool', 'required' => false, 'writeRequired' => true],
            ['key' => 'uploadStaging', 'path' => $runtimePath . '/uploads', 'required' => false, 'writeRequired' => true],
            ['key' => 'database', 'path' => dirname($databasePath), 'required' => true, 'writeRequired' => true],
        ];
        $this->binaries = $binaries ?? [
            'ffmpeg' => (string) (getenv('VELIN_FFMPEG_PATH') ?: base_path('bin/ffmpeg')),
            'ffprobe' => (string) (getenv('VELIN_FFPROBE_PATH') ?: base_path('bin/ffprobe')),
            'scraper' => base_path('bin/velin-scraper'),
        ];
    }

    /**
     * Returns detailed path-visible diagnostics for a storage administrator.
     *
     * `$governance` can only be supplied by StorageGovernanceService from persisted server state;
     * request data never reaches this parameter. Keeping it optional preserves a deterministic,
     * database-free probe for migration checks and isolated tests.
     *
     * @param array{policy?:array<string,mixed>,baselines?:array<string,array<string,mixed>>}|null $governance
     * @return array{status: string, sampledAt: string, thresholds: array<string, mixed>, roots: list<array<string, mixed>>, topology: array<string, mixed>, binaries: list<array<string, mixed>>}
     */
    public function inspect(array $actor, ?array $governance = null): array
    {
        $this->requireManager($actor);
        $policy = is_array($governance['policy'] ?? null) ? $governance['policy'] : [
            'attentionFreePercent' => (int) (self::ATTENTION_FREE_RATIO * 100),
            'criticalFreePercent' => (int) (self::CRITICAL_FREE_RATIO * 100),
            'safetyReserveBytes' => StorageGovernanceService::DEFAULT_SAFETY_RESERVE_BYTES,
            'version' => 0,
            'updatedAt' => null,
        ];
        $baselines = is_array($governance['baselines'] ?? null) ? $governance['baselines'] : [];
        $mounts = $this->mounts();
        $roots = array_map(fn (array $root): array => $this->inspectRoot($root, $mounts, $policy, $baselines), $this->roots);
        $status = 'normal';
        foreach ($roots as $root) {
            $status = $this->worst($status, (string) $root['status']);
        }
        $binaries = [];
        foreach ($this->binaries as $key => $path) {
            $binaries[] = $this->inspectBinary($key, $path);
        }
        $requiredBinaryFailures = array_filter($binaries, static fn (array $binary): bool => in_array($binary['key'], ['ffmpeg', 'ffprobe'], true) && $binary['status'] !== 'normal');
        if ($requiredBinaryFailures !== []) {
            $status = 'critical';
        }

        $topology = $this->topology($roots);
        $status = $this->worst($status, (string) $topology['status']);

        return [
            'status' => $status,
            'sampledAt' => gmdate('c'),
            'thresholds' => $policy,
            'roots' => $roots,
            'topology' => $topology,
            'binaries' => $binaries,
        ];
    }

    /** Returns path-free root state for the overview while preserving true severity and capacity. */
    public function summary(array $actor): array
    {
        $report = $this->inspect($actor);

        return [
            'status' => $report['status'],
            'roots' => array_map(static fn (array $root): array => [
                'key' => $root['key'],
                'status' => $root['status'],
                'readable' => $root['readable'],
                'writable' => $root['writable'],
                'writeRequired' => $root['writeRequired'],
                'totalBytes' => $root['totalBytes'],
                'freeBytes' => $root['freeBytes'],
            ], array_values(array_filter(
                $report['roots'],
                static fn (array $root): bool => in_array($root['key'], ['library', 'scrapeCache'], true),
            ))),
        ];
    }

    /** Inspects one fixed root, preserving separate reason codes rather than one generic offline state. */
    private function inspectRoot(array $root, array $mounts, array $policy, array $baselines): array
    {
        $exists = is_dir($root['path']);
        $readable = $exists && is_readable($root['path']);
        $writable = $exists && is_writable($root['path']);
        $realPath = $exists ? realpath($root['path']) : false;
        $stat = $exists ? @stat($root['path']) : false;
        $total = $readable ? @disk_total_space($root['path']) : false;
        $free = $readable ? @disk_free_space($root['path']) : false;
        $freeRatio = is_float($total) && $total > 0 && is_float($free) ? $free / $total : null;
        $inode = $readable ? $this->inodeCapacity($root['path']) : null;
        $inodeRatio = is_array($inode) && $inode['total'] > 0 ? $inode['free'] / $inode['total'] : null;
        $mount = is_string($realPath) ? $this->mountFor($realPath, $mounts) : null;

        $reason = 'READY';
        $status = 'normal';
        if (!$exists) {
            $reason = $root['required'] ? 'DIRECTORY_MISSING' : 'NOT_INITIALIZED';
            $status = $root['required'] ? 'critical' : 'unknown';
        } elseif (!$readable) {
            $reason = 'DIRECTORY_NOT_TRAVERSABLE';
            $status = 'critical';
        } elseif ($root['writeRequired'] && !$writable) {
            $reason = 'DIRECTORY_READ_ONLY';
            $status = 'critical';
        } elseif ($freeRatio !== null && $freeRatio * 100 < (int) $policy['criticalFreePercent']) {
            $reason = 'SPACE_CRITICAL';
            $status = 'critical';
        } elseif (is_float($free) && $free <= (int) $policy['safetyReserveBytes']) {
            $reason = 'SAFETY_RESERVE_REACHED';
            $status = 'critical';
        } elseif ($inodeRatio !== null && $inodeRatio * 100 < (int) $policy['criticalFreePercent']) {
            $reason = 'INODES_CRITICAL';
            $status = 'critical';
        } elseif ($freeRatio !== null && $freeRatio * 100 < (int) $policy['attentionFreePercent']) {
            $reason = 'SPACE_LOW';
            $status = 'attention';
        } elseif ($inodeRatio !== null && $inodeRatio * 100 < (int) $policy['attentionFreePercent']) {
            $reason = 'INODES_LOW';
            $status = 'attention';
        } elseif ($mount === null || $stat === false) {
            $reason = 'MOUNT_IDENTITY_UNKNOWN';
            $status = 'unknown';
        }

        $identity = $this->identity($root['key'], is_string($realPath) ? $realPath : null,
            is_array($stat) ? (string) $stat['dev'] : null, $mount, $baselines);
        if ($identity['status'] === 'changed') {
            $reason = 'MOUNT_IDENTITY_CHANGED';
            $status = 'critical';
        }

        return [
            'key' => $root['key'],
            'path' => $root['path'],
            'status' => $status,
            'reasonCode' => $reason,
            'required' => $root['required'],
            'writeRequired' => $root['writeRequired'],
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'isSymlink' => is_link($root['path']),
            'resolvedPath' => is_string($realPath) ? $realPath : null,
            'deviceId' => is_array($stat) ? (string) $stat['dev'] : null,
            'mountPoint' => $mount['mountPoint'] ?? null,
            'filesystemType' => $mount['filesystemType'] ?? null,
            'totalBytes' => is_float($total) ? (int) $total : null,
            'freeBytes' => is_float($free) ? (int) $free : null,
            'freePercent' => $freeRatio === null ? null : round($freeRatio * 100, 1),
            'totalInodes' => $inode['total'] ?? null,
            'freeInodes' => $inode['free'] ?? null,
            'freeInodePercent' => $inodeRatio === null ? null : round($inodeRatio * 100, 1),
            'identity' => $identity,
        ];
    }

    /**
     * Compares the current fixed-root identity with an explicitly confirmed baseline.
     *
     * A missing baseline is unknown rather than failed. Once confirmed, any resolved path, device,
     * mount point, or filesystem change becomes critical until an administrator confirms the fresh
     * sample. This detects both a different disk and a path silently falling back to another mount.
     */
    private function identity(string $key, ?string $resolvedPath, ?string $deviceId, ?array $mount, array $baselines): array
    {
        $baseline = $baselines[$key] ?? null;
        if (!is_array($baseline)) return ['status' => 'untracked', 'confirmedAt' => null];
        $matches = is_string($resolvedPath) && is_string($deviceId) && is_array($mount)
            && hash_equals((string) $baseline['resolvedPath'], $resolvedPath)
            && hash_equals((string) $baseline['deviceId'], $deviceId)
            && hash_equals((string) $baseline['mountPoint'], (string) ($mount['mountPoint'] ?? ''))
            && hash_equals((string) $baseline['filesystemType'], (string) ($mount['filesystemType'] ?? ''));
        return [
            'status' => $matches ? 'matched' : 'changed',
            'confirmedAt' => is_string($baseline['confirmedAt'] ?? null) ? $baseline['confirmedAt'] : null,
        ];
    }

    /**
     * 计算新媒体库/缓存边界。
     *
     * 新模型只要求媒体根与缓存根真实路径不相等且互不包含，设备可以不同。遗留 work 目录不再进入
     * 固定根注册表，因此缺失、只读或挂载变化都不会降低新刮削模型的健康状态。
     */
    private function topology(array $roots): array
    {
        $byKey = [];
        foreach ($roots as $root) {
            $byKey[$root['key']] = $root;
        }
        $library = $byKey['library'] ?? [];
        $cache = $byKey['scrapeCache'] ?? [];
        $libraryCacheSafe = $this->distinctNonOverlapping(
            $library['resolvedPath'] ?? null,
            $cache['resolvedPath'] ?? null,
        );
        $baseline = $this->baselineSummary($roots);

        return [
            'status' => !$libraryCacheSafe || $baseline === 'changed'
                ? 'critical'
                : ($baseline === 'matched' ? 'normal' : 'unknown'),
            'libraryCacheDistinctAndNonOverlapping' => $libraryCacheSafe,
            'mountIdentityBaseline' => $baseline,
        ];
    }

    /** Aggregates root identity without claiming health when only part of the registry is tracked. */
    private function baselineSummary(array $roots): string
    {
        $statuses = array_column(array_column($roots, 'identity'), 'status');
        if (in_array('changed', $statuses, true)) return 'changed';
        $matched = count(array_filter($statuses, static fn (string $status): bool => $status === 'matched'));
        if ($matched === 0) return 'untracked';
        return $matched === count($statuses) ? 'matched' : 'partial';
    }

    /** Returns false for absent, identical, or ancestor/descendant real paths. */
    private function distinctNonOverlapping(mixed $left, mixed $right): bool
    {
        if (!is_string($left) || !is_string($right)) {
            return false;
        }
        $left = rtrim($left, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $right = rtrim($right, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $left !== $right && !str_starts_with($left, $right) && !str_starts_with($right, $left);
    }

    /** Reads inode capacity through fixed argv `df`; no shell or untrusted argument is involved. */
    private function inodeCapacity(string $path): ?array
    {
        if (!function_exists('proc_open') || !is_executable('/bin/df')) {
            return null;
        }
        $pipes = [];
        $process = @proc_open(['/bin/df', '-Pi', $path], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($output)) {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
        $fields = preg_split('/\s+/', (string) end($lines)) ?: [];
        if (count($fields) < 6 || !ctype_digit($fields[1]) || !ctype_digit($fields[3])) {
            return null;
        }

        return ['total' => (int) $fields[1], 'free' => (int) $fields[3]];
    }

    /** Parses Linux mountinfo and returns only mount point/filesystem identity for longest-prefix match. */
    private function mounts(): array
    {
        $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $mounts = [];
        foreach ($lines as $line) {
            [$before, $after] = array_pad(explode(' - ', $line, 2), 2, '');
            $fields = preg_split('/\s+/', $before) ?: [];
            $filesystem = preg_split('/\s+/', $after) ?: [];
            if (!isset($fields[4], $filesystem[0])) {
                continue;
            }
            $mounts[] = [
                'mountPoint' => $this->unescapeMount((string) $fields[4]),
                'filesystemType' => (string) $filesystem[0],
            ];
        }
        usort($mounts, static fn (array $left, array $right): int => strlen($right['mountPoint']) <=> strlen($left['mountPoint']));

        return $mounts;
    }

    /** Returns the most specific mount containing an already resolved absolute path. */
    private function mountFor(string $path, array $mounts): ?array
    {
        foreach ($mounts as $mount) {
            $prefix = rtrim((string) $mount['mountPoint'], DIRECTORY_SEPARATOR);
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . DIRECTORY_SEPARATOR)) {
                return $mount;
            }
        }

        return null;
    }

    /** Decodes Linux mountinfo octal escapes without interpreting arbitrary backslash syntax. */
    private function unescapeMount(string $value): string
    {
        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $value);
    }

    /** Executes one fixed binary/version command with a hard timeout and no retained output. */
    private function inspectBinary(string $key, string $path): array
    {
        if (!is_file($path) || !is_executable($path)) {
            return [
                'key' => $key,
                'path' => $path,
                'status' => $key === 'scraper' ? 'unknown' : 'critical',
                'reasonCode' => 'BINARY_NOT_EXECUTABLE',
            ];
        }
        if (!function_exists('proc_open')) {
            return ['key' => $key, 'path' => $path, 'status' => 'unknown', 'reasonCode' => 'PROCESS_PROBE_UNAVAILABLE'];
        }
        $pipes = [];
        $process = @proc_open([$path, '-version'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return ['key' => $key, 'path' => $path, 'status' => 'critical', 'reasonCode' => 'BINARY_START_FAILED'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 2.0;
        $exitCode = null;
        do {
            $state = proc_get_status($process);
            if (!$state['running']) {
                $exitCode = (int) $state['exitcode'];
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        if ($exitCode === null) {
            proc_terminate($process, 9);
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
            'key' => $key,
            'path' => $path,
            'status' => $exitCode === 0 ? 'normal' : 'critical',
            'reasonCode' => $exitCode === 0 ? 'READY' : ($exitCode === null ? 'BINARY_TIMEOUT' : 'BINARY_EXIT_FAILED'),
        ];
    }

    /** Repeats storage authorization at the application boundary. */
    private function requireManager(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('manage_storage', $capabilities, true)) {
            throw new AuthorizationDenied('Storage diagnostics require manage_storage.');
        }
    }

    /** Returns the more severe stable status; unknown outranks attention to avoid false health. */
    private function worst(string $left, string $right): string
    {
        $rank = ['normal' => 0, 'attention' => 1, 'unknown' => 2, 'critical' => 3];

        return ($rank[$right] ?? 2) > ($rank[$left] ?? 2) ? $right : $left;
    }
}
