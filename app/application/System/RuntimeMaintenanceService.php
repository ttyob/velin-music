<?php

declare(strict_types=1);

namespace app\application\System;

use app\infrastructure\Audit\AuditLogger;
use Throwable;

/**
 * 清理主程序明确拥有且可重建的运行时文件。
 *
 * 清理范围由代码中的封闭策略决定，HTTP 请求不能提供路径、文件名或保留期。服务只检查 runtime 下的
 * 固定一级目录，并用每个所有者的文件名契约识别对象；媒体库、上传暂存、刮削资源、插件工作区、会话、
 * 数据库、手工封面和来源预览缓存都不在策略中。目录或文件符号链接、未知文件、目录项和特殊文件始终
 * 保留。删除不可回滚，但对象均可由原始媒体或后续请求重建；每个候选在 unlink 前会复验真实路径、
 * inode、mtime 和类型，避免扫描与删除之间的替换竞态。重复执行只会得到零删除结果，保持幂等。
 */
final readonly class RuntimeMaintenanceService
{
    private const CATEGORY_ORDER = ['logs', 'cache', 'temporary'];

    /** @var array<string,array{label:string,description:string,retentionLabel:string}> */
    private const CATEGORIES = [
        'logs' => [
            'label' => '应用日志',
            'description' => '清理过期的按日期归档日志，当前进程日志始终保留。',
            'retentionLabel' => '保留最近 14 天',
        ],
        'cache' => [
            'label' => '可重建缓存',
            'description' => '清理封面缩略图、内嵌封面、DLNA 转码和远程播放缓存。',
            'retentionLabel' => '保留最近 7 天',
        ],
        'temporary' => [
            'label' => '临时文件',
            'description' => '清理已失去活动锁的转码残留和过期扫描报告。',
            'retentionLabel' => '保留最近 1 小时',
        ],
    ];

    /**
     * @var list<array{category:string,directory:string,retentionSeconds:int,matcher:string,lockRequired:bool}>
     */
    private const POLICIES = [
        ['category' => 'logs', 'directory' => 'logs', 'retentionSeconds' => 1_209_600,
            'matcher' => 'dated_log', 'lockRequired' => false],
        ['category' => 'cache', 'directory' => 'artwork-cache', 'retentionSeconds' => 604_800,
            'matcher' => 'image_cache', 'lockRequired' => false],
        ['category' => 'cache', 'directory' => 'embedded-artwork-cache', 'retentionSeconds' => 604_800,
            'matcher' => 'image_cache', 'lockRequired' => false],
        ['category' => 'cache', 'directory' => 'dlna-transcode-cache', 'retentionSeconds' => 604_800,
            'matcher' => 'audio_cache', 'lockRequired' => false],
        ['category' => 'cache', 'directory' => 'remote-playback-cache', 'retentionSeconds' => 604_800,
            'matcher' => 'audio_cache', 'lockRequired' => false],
        ['category' => 'temporary', 'directory' => 'transcode-spool', 'retentionSeconds' => 3_600,
            'matcher' => 'transcode_part', 'lockRequired' => true],
        ['category' => 'temporary', 'directory' => 'dlna-transcode-cache', 'retentionSeconds' => 3_600,
            'matcher' => 'dlna_transcode_part', 'lockRequired' => true],
        ['category' => 'temporary', 'directory' => 'job-reports', 'retentionSeconds' => 3_600,
            'matcher' => 'job_report', 'lockRequired' => false],
    ];

    private string $runtimeRoot;

    public function __construct(
        ?string $runtimeRoot = null,
        private AuditLogger $audit = new AuditLogger(),
    ) {
        $this->runtimeRoot = rtrim(
            $runtimeRoot ?? (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime')),
            DIRECTORY_SEPARATOR,
        );
    }

    /**
     * 返回三类受管对象及当前可清理量，不泄露服务器物理路径。
     *
     * 不存在的固定子目录按空目录处理，以兼容尚未使用对应功能的新部署；runtime 根本身必须是非链接、
     * 可解析的真实目录，否则失败关闭。统计只包含符合所有者命名契约的普通文件，未知对象既不计入可
     * 清理空间也不会在后续 cleanup 中被删除。
     *
     * @return array{categories:list<array<string,int|string|bool>>,scannedAt:string}
     */
    public function status(): array
    {
        $root = $this->verifiedRuntimeRoot();
        return $this->scan($root, time());
    }

    /**
     * 执行管理员明确选择的清理并写入一条脱敏审计。
     *
     * categories 必须是非空、无重复的封闭键列表。文件系统删除在数据库事务外完成，因为 unlink 无法随
     * SQLite 回滚；审计记录完成后的分类、成功/失败数量和字节数，不记录路径或文件名。单文件删除失败
     * 不终止其余候选，并通过 failedFileCount 显示部分失败；审计写入失败会让请求失败，已经完成的缓存
     * 删除仍保持有效且下次重试幂等。
     *
     * @param list<mixed> $categories
     * @return array{cleanedCategories:list<string>,deletedFileCount:int,deletedBytes:int,failedFileCount:int,categories:list<array<string,int|string|bool>>,scannedAt:string}
     */
    public function cleanup(array $categories, string $actorUserId, string $requestId): array
    {
        $selected = $this->validateCategories($categories);
        if ($actorUserId === '' || $requestId === '') {
            throw new RuntimeMaintenanceInvalid('管理员身份或请求标识无效。');
        }
        $root = $this->verifiedRuntimeRoot();
        $lock = $this->acquireCleanupLock($root);
        $deletedFiles = 0;
        $deletedBytes = 0;
        $failedFiles = 0;
        try {
            $now = time();
            foreach (self::POLICIES as $policy) {
                if (!in_array($policy['category'], $selected, true)) continue;
                foreach ($this->candidates($root, $policy, $now) as $candidate) {
                    if ($this->deleteCandidate($candidate, $policy)) {
                        $deletedFiles++;
                        $deletedBytes += $candidate['size'];
                    } else {
                        $failedFiles++;
                    }
                }
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->audit->record(
            $actorUserId,
            'system.maintenance.cleanup',
            'runtime_maintenance',
            null,
            $failedFiles === 0 ? 'success' : 'partial_failure',
            $requestId,
            [
                'categories' => implode(',', $selected),
                'deletedFileCount' => $deletedFiles,
                'deletedBytes' => $deletedBytes,
                'failedFileCount' => $failedFiles,
            ],
        );

        return [
            'cleanedCategories' => $selected,
            'deletedFileCount' => $deletedFiles,
            'deletedBytes' => $deletedBytes,
            'failedFileCount' => $failedFiles,
        ] + $this->scan($root, time());
    }

    /**
     * 供低频 Worker 复用完全相同的策略，但不为每次周期任务写管理员审计。
     *
     * 跨进程锁阻止自动任务与后台按钮同时遍历；锁忙时返回 null，下一周期自然重试。调用方只应写一条
     * 有界汇总日志。该入口仍逐文件复验，进程崩溃最多留下未清理对象，不会产生半发布业务状态。
     *
     * @return array{cleanedCategories:list<string>,deletedFileCount:int,deletedBytes:int,failedFileCount:int}|null
     */
    public function cleanupAutomatically(): ?array
    {
        $root = $this->verifiedRuntimeRoot();
        $lock = $this->acquireCleanupLock($root, false);
        if ($lock === null) return null;
        $deletedFiles = 0;
        $deletedBytes = 0;
        $failedFiles = 0;
        try {
            $now = time();
            foreach (self::POLICIES as $policy) {
                foreach ($this->candidates($root, $policy, $now) as $candidate) {
                    if ($this->deleteCandidate($candidate, $policy)) {
                        $deletedFiles++;
                        $deletedBytes += $candidate['size'];
                    } else {
                        $failedFiles++;
                    }
                }
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['cleanedCategories' => self::CATEGORY_ORDER, 'deletedFileCount' => $deletedFiles,
            'deletedBytes' => $deletedBytes, 'failedFileCount' => $failedFiles];
    }

    /** @return array{categories:list<array<string,int|string|bool>>,scannedAt:string} */
    private function scan(string $root, int $now): array
    {
        $summary = [];
        foreach (self::CATEGORY_ORDER as $key) {
            $summary[$key] = ['key' => $key] + self::CATEGORIES[$key] + [
                'managedFileCount' => 0, 'managedBytes' => 0,
                'eligibleFileCount' => 0, 'eligibleBytes' => 0,
            ];
        }
        foreach (self::POLICIES as $policy) {
            foreach ($this->recognizedFiles($root, $policy) as $file) {
                $key = $policy['category'];
                $summary[$key]['managedFileCount']++;
                $summary[$key]['managedBytes'] += $file['size'];
                if ($file['mtime'] <= $now - $policy['retentionSeconds']) {
                    $summary[$key]['eligibleFileCount']++;
                    $summary[$key]['eligibleBytes'] += $file['size'];
                }
            }
        }
        return ['categories' => array_values($summary), 'scannedAt' => gmdate('Y-m-d\TH:i:s\Z', $now)];
    }

    /** @return list<array{path:string,realPath:string,size:int,mtime:int,dev:int|string,ino:int|string}> */
    private function candidates(string $root, array $policy, int $now): array
    {
        return array_values(array_filter(
            $this->recognizedFiles($root, $policy),
            static fn (array $file): bool => $file['mtime'] <= $now - $policy['retentionSeconds'],
        ));
    }

    /**
     * @return list<array{path:string,realPath:string,size:int,mtime:int,dev:int|string,ino:int|string}>
     */
    private function recognizedFiles(string $root, array $policy): array
    {
        $directory = $root . DIRECTORY_SEPARATOR . $policy['directory'];
        if (!file_exists($directory) && !is_link($directory)) return [];
        if (is_link($directory) || !is_dir($directory)) {
            throw new RuntimeMaintenanceUnavailable('运行维护目录身份异常。');
        }
        $resolvedDirectory = realpath($directory);
        if (!is_string($resolvedDirectory) || $resolvedDirectory !== $directory
            || !str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeMaintenanceUnavailable('运行维护目录无法安全解析。');
        }
        $names = @scandir($resolvedDirectory);
        if (!is_array($names)) throw new RuntimeMaintenanceUnavailable('运行维护目录不可读取。');
        $files = [];
        foreach ($names as $name) {
            if (!$this->matches($policy['matcher'], $name)) continue;
            $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $name;
            $stat = @lstat($path);
            if (!$this->isRegularStat($stat) || is_link($path)) continue;
            $real = realpath($path);
            if (!is_string($real) || dirname($real) !== $resolvedDirectory || $real !== $path) continue;
            $files[] = ['path' => $path, 'realPath' => $real, 'size' => max(0, (int) $stat['size']),
                'mtime' => (int) $stat['mtime'], 'dev' => $stat['dev'], 'ino' => $stat['ino']];
        }
        return $files;
    }

    /**
     * 删除前复验扫描身份；需要锁的 spool 只有取得独占非阻塞锁后才允许删除。
     *
     * 先打开再锁定可与转码写入方的 flock 契约协调。打开后的 fstat 必须和扫描时 inode 完全一致，随后
     * 再 lstat 路径，确保路径没有被并发替换；unlink 成功后关闭句柄。失败仅返回 false，不删除替换后的
     * 对象，也不尝试递归或补偿操作。
     */
    private function deleteCandidate(array $candidate, array $policy): bool
    {
        clearstatcache(true, $candidate['path']);
        $stat = @lstat($candidate['path']);
        if (!$this->sameIdentity($candidate, $stat) || is_link($candidate['path'])
            || realpath($candidate['path']) !== $candidate['realPath']) return false;
        $handle = null;
        if ($policy['lockRequired']) {
            $handle = @fopen($candidate['path'], 'rb');
            $opened = is_resource($handle) ? @fstat($handle) : false;
            if (!is_resource($handle) || !$this->sameIdentity($candidate, $opened)
                || !@flock($handle, LOCK_EX | LOCK_NB)) {
                if (is_resource($handle)) fclose($handle);
                return false;
            }
        }
        clearstatcache(true, $candidate['path']);
        $latest = @lstat($candidate['path']);
        $deleted = $this->sameIdentity($candidate, $latest)
            && !is_link($candidate['path'])
            && @unlink($candidate['path']);
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        return $deleted;
    }

    private function matches(string $matcher, string $name): bool
    {
        return match ($matcher) {
            'dated_log' => preg_match('/^webman-\d{4}-\d{2}-\d{2}\.log(?:\.\d+)?$/D', $name) === 1,
            'image_cache' => preg_match('/^[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $name) === 1,
            'audio_cache' => preg_match('/^[a-f0-9]{64}\.audio$/D', $name) === 1,
            'transcode_part' => preg_match('/^[a-f0-9]{32}\.part$/D', $name) === 1,
            'dlna_transcode_part' => preg_match('/^[a-f0-9]{64}\.[a-f0-9]{32}\.part$/D', $name) === 1,
            'job_report' => preg_match('/^(?:[0-9A-HJKMNP-TV-Z]{26}\.json|\.[0-9A-HJKMNP-TV-Z]{26}\.[a-f0-9]{16}\.tmp)$/D', $name) === 1,
            default => false,
        };
    }

    /** @param array<string,mixed>|false $stat */
    private function isRegularStat(array|false $stat): bool
    {
        return is_array($stat) && (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000;
    }

    /** @param array<string,mixed>|false $stat */
    private function sameIdentity(array $candidate, array|false $stat): bool
    {
        return $this->isRegularStat($stat)
            && (string) $stat['dev'] === (string) $candidate['dev']
            && (string) $stat['ino'] === (string) $candidate['ino']
            && (int) $stat['size'] === $candidate['size']
            && (int) $stat['mtime'] === $candidate['mtime'];
    }

    /** @param list<mixed> $categories @return list<string> */
    private function validateCategories(array $categories): array
    {
        if ($categories === [] || !array_is_list($categories) || count($categories) > count(self::CATEGORY_ORDER)) {
            throw new RuntimeMaintenanceInvalid('请选择有效的清理分类。');
        }
        $selected = [];
        foreach ($categories as $category) {
            if (!is_string($category) || !in_array($category, self::CATEGORY_ORDER, true)
                || in_array($category, $selected, true)) {
                throw new RuntimeMaintenanceInvalid('清理分类无效或重复。');
            }
            $selected[] = $category;
        }
        usort($selected, static fn (string $left, string $right): int =>
            array_search($left, self::CATEGORY_ORDER, true) <=> array_search($right, self::CATEGORY_ORDER, true));
        return $selected;
    }

    /** runtime 根必须已存在、非链接并解析为调用方提供的同一路径。 */
    private function verifiedRuntimeRoot(): string
    {
        if ($this->runtimeRoot === '' || is_link($this->runtimeRoot) || !is_dir($this->runtimeRoot)) {
            throw new RuntimeMaintenanceUnavailable('运行时目录不可用。');
        }
        $resolved = realpath($this->runtimeRoot);
        if (!is_string($resolved) || $resolved !== $this->runtimeRoot) {
            throw new RuntimeMaintenanceUnavailable('运行时目录身份异常。');
        }
        return $resolved;
    }

    /** @return resource|null */
    private function acquireCleanupLock(string $root, bool $throwWhenBusy = true): mixed
    {
        $path = $root . DIRECTORY_SEPARATOR . '.runtime-maintenance.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeMaintenanceUnavailable('运行维护锁文件身份异常。');
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || is_link($path) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            if (!$throwWhenBusy) return null;
            throw new RuntimeMaintenanceUnavailable('另一个清理任务正在执行。');
        }
        @chmod($path, 0600);
        return $handle;
    }
}

/** 请求包含未知、重复或空的清理分类。 */
final class RuntimeMaintenanceInvalid extends \RuntimeException
{
}

/** runtime 根、固定子目录或跨进程锁不满足安全前提。 */
final class RuntimeMaintenanceUnavailable extends \RuntimeException
{
}
