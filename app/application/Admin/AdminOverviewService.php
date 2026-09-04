<?php

declare(strict_types=1);

namespace app\application\Admin;

use app\application\Auth\AuthorizationDenied;
use app\application\System\SqliteBackupStatusService;
use app\application\System\SystemLimitSettingsService;
use stdClass;
use support\Db;
use Throwable;

/**
 * 构建按 capability 与音乐库管理范围裁剪的后台状态快照（ADMIN-DASH-001）。
 *
 * 可选模块仅在权威 Session 投影包含对应能力时出现，音乐库与扫描查询还会和操作者当前 manage 级库 ID
 * 求交集。系统管理员的备份模块只读取固定目录汇总；响应始终只使用逻辑名称、匿名计数和稳定原因码，
 * 物理路径、邮箱、播放曲目、秘密、文件名、摘要与原始错误均不得跨越此边界。本服务只读且不承担任务
 * 创建、文件维护、备份或恢复职责。
 */
final class AdminOverviewService
{
    /** 至少具备其中一项全局能力才可进入后台外壳；对象权限仍由各模块再次收窄。 */
    public const ADMIN_CAPABILITIES = [
        'manage_users', 'manage_library', 'manage_storage', 'manage_system',
        'view_audit', 'run_scrape', 'edit_metadata', 'view_play_privacy',
    ];

    /**
     * 注入只读备份探针以隔离文件系统测试；生产默认在真正需要该模块时才按数据库配置创建探针。
     *
     * 延迟创建保证不具备 manage_system 的操作者不会访问数据库备份目录。依赖只读，不持有请求数据，
     * 也不提供创建或恢复能力。
     */
    public function __construct(private readonly ?SqliteBackupStatusService $backupStatus = null)
    {
    }

    /**
     * 返回独立采样的权限模块，使单个探针失败不会抹去整张概览。
     *
     * 本方法只读。文件系统探针只读取元信息，不创建目录、不写文件、不读取媒体正文，也不排队任务。
     * 每个模块携带各自 UTC 采样时间，因为数据库和文件系统检查并非同一瞬间完成。探针异常被隔离为
     * unknown 和统一原因码；权限不足的模块直接省略，不能用 unknown 暗示该模块存在。
     *
     * @param array<string, mixed> $actor SessionService 已实时复验的当前用户投影
     * @return array{modules: array<string, array<string, mixed>>}
     */
    public function snapshot(array $actor): array
    {
        $capabilities = $this->capabilities($actor);
        if (array_intersect(self::ADMIN_CAPABILITIES, $capabilities) === []) {
            throw new AuthorizationDenied('No administration capability is available.');
        }

        $modules = ['system' => $this->module(fn (): array => $this->system())];
        if (in_array('manage_library', $capabilities, true)) {
            $modules['libraries'] = $this->module(fn (): array => $this->libraries($actor));
            $modules['scans'] = $this->module(fn (): array => $this->scans($actor));
        }
        if (in_array('manage_users', $capabilities, true)) {
            $modules['accounts'] = $this->module(fn (): array => $this->accounts());
        }
        if (in_array('manage_system', $capabilities, true)) {
            $modules['backups'] = $this->module(fn (): array =>
                ($this->backupStatus ?? new SqliteBackupStatusService())->status());
            // The lock-only admission mechanism has no reliable observer for active leases. Returning
            // unknown is deliberate until persistent metrics exist; zero would conceal saturation.
            $modules['transcoding'] = [
                'status' => 'unknown',
                'sampledAt' => gmdate('c'),
                'data' => [
                    'active' => null,
                    'limit' => (int) (new SystemLimitSettingsService())->get()['maxConcurrentTranscodes'],
                    'reasonCode' => 'TRANSCODE_ACTIVITY_TELEMETRY_UNAVAILABLE',
                ],
            ];
        }

        return ['modules' => $modules];
    }

    /** Returns schema readiness without exposing database location or connection settings. */
    private function system(): array
    {
        $storedSchema = Db::table('phinxlog')->max('version');
        // PDO SQLite may project the numeric Phinx key as int while another adapter returns string.
        // The API keeps it a string ID and never performs arithmetic, preserving future MySQL parity.
        $schema = (is_int($storedSchema) || (is_string($storedSchema) && $storedSchema !== ''))
            ? (string) $storedSchema
            : null;

        return [
            'status' => $schema !== null ? 'normal' : 'unknown',
            'data' => [
                'database' => $schema !== null ? 'ready' : 'unknown',
                'schemaVersion' => $schema,
                'serviceVersion' => getenv('VELIN_VERSION') ?: '0.1.0-dev',
            ],
        ];
    }

    /** Returns managed library totals and state counts after the actor's object scope is reapplied. */
    private function libraries(array $actor): array
    {
        $query = $this->managedLibraryQuery($actor);
        $rows = $query->get([
            'libraries.id', 'libraries.status', 'libraries.scan_status', 'libraries.song_count',
            'libraries.album_count', 'libraries.artist_count', 'libraries.last_scanned_at',
        ]);
        $data = [
            'count' => $rows->count(),
            'songs' => $rows->sum(static fn (stdClass $row): int => (int) $row->song_count),
            'albums' => $rows->sum(static fn (stdClass $row): int => (int) $row->album_count),
            'artists' => $rows->sum(static fn (stdClass $row): int => (int) $row->artist_count),
            'ready' => $rows->filter(static fn (stdClass $row): bool => $row->scan_status === 'ready')->count(),
            'attention' => $rows->filter(static fn (stdClass $row): bool => in_array($row->scan_status, ['never_scanned', 'queued', 'scanning'], true))->count(),
            'errors' => $rows->filter(static fn (stdClass $row): bool => $row->scan_status === 'error')->count(),
            'latestScanAt' => $rows->max('last_scanned_at'),
        ];
        $status = $data['errors'] > 0 ? 'critical' : ($data['attention'] > 0 || $data['count'] === 0 ? 'attention' : 'normal');

        return ['status' => $status, 'data' => $data];
    }

    /** Returns current scan backlog, recent failures, and five path-free task rows in managed scope. */
    private function scans(array $actor): array
    {
        $query = Db::table('library_scan_jobs as jobs')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id');
        $this->scopeManagedLibraries($query, $actor);
        $queued = (clone $query)->where('jobs.status', 'queued')->count();
        $running = (clone $query)->whereIn('jobs.status', ['running', 'cancel_requested'])->count();
        $failed = (clone $query)->where('jobs.status', 'failed')
            ->where('jobs.created_at', '>=', gmdate('Y-m-d\TH:i:s\Z', time() - 86400))->count();
        /** @var list<stdClass> $rows */
        $rows = (clone $query)->orderByDesc('jobs.created_at')->limit(5)->get([
            'jobs.id', 'jobs.library_id', 'libraries.name as library_name', 'jobs.scan_type',
            'jobs.status', 'jobs.phase', 'jobs.processed_entries', 'jobs.discovered_files',
            'jobs.failed_entries', 'jobs.created_at', 'jobs.finished_at',
        ])->all();
        $recent = array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'libraryId' => (string) $row->library_id,
            'libraryName' => (string) $row->library_name,
            'scanType' => (string) $row->scan_type,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'processedEntries' => (int) $row->processed_entries,
            'discoveredFiles' => (int) $row->discovered_files,
            'failedEntries' => (int) $row->failed_entries,
            'createdAt' => (string) $row->created_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
        ], $rows);
        $status = $failed > 0 ? 'critical' : ($queued + $running > 0 ? 'attention' : 'normal');

        return ['status' => $status, 'data' => [
            'queued' => $queued,
            'running' => $running,
            'failedLast24Hours' => $failed,
            'recent' => $recent,
        ]];
    }

    /** 返回账号状态和基于播放器心跳租约的匿名并发；即使具备隐私能力也不返回身份、播放器或歌曲。 */
    private function accounts(): array
    {
        $active = Db::table('users')->where('status', 'active')->whereNull('deleted_at')->count();
        $disabled = Db::table('users')->where('status', 'disabled')->whereNull('deleted_at')->count();
        $leases = Db::table('playback_leases')->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
        $online = (clone $leases)->distinct()->count('user_id');
        $streams = (clone $leases)->count();

        return ['status' => 'normal', 'data' => [
            'active' => $active,
            'disabled' => $disabled,
            'online' => $online,
            'activeStreams' => $streams,
        ]];
    }

    /** Adds sample time and converts a failed module to explicit unknown state with no exception text. */
    private function module(callable $probe): array
    {
        try {
            $result = $probe();
            $result['sampledAt'] = gmdate('c');

            return $result;
        } catch (Throwable) {
            return [
                'status' => 'unknown',
                'sampledAt' => gmdate('c'),
                'data' => ['reasonCode' => 'ADMIN_OVERVIEW_MODULE_UNAVAILABLE'],
            ];
        }
    }

    /** Returns an actor-scoped library query that selects only manage-level objects. */
    private function managedLibraryQuery(array $actor)
    {
        $query = Db::table('music_libraries as libraries');
        $this->scopeManagedLibraries($query, $actor);

        return $query;
    }

    /** Applies super-admin or explicit current manage-grant scope to a query containing libraries. */
    private function scopeManagedLibraries($query, array $actor): void
    {
        if ($actor['isSuperAdmin'] ?? false) {
            return;
        }
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage' && is_string($library['id'] ?? null)) {
                $ids[] = $library['id'];
            }
        }
        $query->whereIn('libraries.id', array_values(array_unique($ids)));
    }

    /** @return list<string> Returns only string capabilities from the trusted Session projection. */
    private function capabilities(array $actor): array
    {
        return array_values(array_filter(
            is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [],
            static fn (mixed $item): bool => is_string($item),
        ));
    }

    /** Chooses the more severe stable status without allowing unknown to appear normal. */
    private function worst(string $left, string $right): string
    {
        $rank = ['normal' => 0, 'attention' => 1, 'unknown' => 2, 'critical' => 3];

        return ($rank[$right] ?? 2) > ($rank[$left] ?? 2) ? $right : $left;
    }
}
