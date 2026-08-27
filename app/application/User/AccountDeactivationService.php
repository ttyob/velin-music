<?php

declare(strict_types=1);

namespace app\application\User;

use stdClass;
use support\Db;

/**
 * 汇总并执行账号停用产生的认证撤销与上传取消副作用（ADMIN-USER-004）。
 *
 * 调用约束：`impact()` 是只读预览，可在事务外调用；`deactivateInTransaction()` 必须由已经持有账号状态
 * 修改事务的上层服务调用。后者只写数据库，不执行目录清理、哈希、媒体探测或其他文件 I/O。尚未被
 * Worker 领取的上传直接进入 cancelled 并登记待清理；publishing 上传和 running 个人导出只写取消
 * 请求，由各自 Worker 在当前文件或发布边界后收敛，避免半发布文件集合和残留隐私产物。
 */
final class AccountDeactivationService
{
    /**
     * 返回可展示汇总和用于冻结预览的最小事实，不泄露 Session、令牌或上传对象标识。
     *
     * @param list<string> $userIds 服务端已经验证过的用户 ULID。
     * @return array{
     *   loginSessionsToRevoke:int,tokensToRevoke:int,uploadsToCancel:int,uploadsToRequestCancel:int,
     *   exportsToCancel:int,exportsToRequestCancel:int,playbackLeasesToRelease:int,snapshot:array<string,mixed>
     * }
     */
    public function impact(array $userIds): array
    {
        if ($userIds === []) return $this->emptyImpact();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        /** @var list<stdClass> $sessions */
        $sessions = Db::table('auth_sessions')->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')->where('expires_at', '>', $now)
            ->orderBy('id')->get(['id', 'user_id', 'expires_at'])->all();
        /** @var list<stdClass> $tokens */
        $tokens = Db::table('personal_access_tokens')->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->orderBy('id')->get(['id', 'user_id', 'expires_at', 'updated_at'])->all();
        /** @var list<stdClass> $uploads */
        $uploads = Db::table('upload_sessions')->whereIn('user_id', $userIds)
            ->whereIn('status', ['created', 'uploading', 'ready', 'publishing'])
            ->orderBy('id')->get([
                'id', 'user_id', 'status', 'version', 'cancel_requested_at', 'cleanup_state', 'updated_at',
            ])->all();
        /** @var list<stdClass> $leases */
        $leases = Db::table('playback_leases')->whereIn('user_id', $userIds)
            ->where('expires_at', '>', $now)->orderBy('id')->get(['id', 'user_id', 'expires_at'])->all();
        /** @var list<stdClass> $exports */
        $exports = Db::connection()->getSchemaBuilder()->hasTable('personal_data_export_jobs')
            ? Db::table('personal_data_export_jobs')->whereIn('user_id', $userIds)
                ->whereIn('status', ['queued', 'running', 'cancel_requested'])->orderBy('id')
                ->get(['id', 'user_id', 'status', 'version', 'cancel_requested_at', 'updated_at'])->all()
            : [];

        $pending = array_filter(
            $uploads,
            static fn (stdClass $row): bool => in_array((string) $row->status, ['created', 'uploading', 'ready'], true),
        );
        $running = array_filter(
            $uploads,
            static fn (stdClass $row): bool => (string) $row->status === 'publishing'
                && $row->cancel_requested_at === null,
        );
        $queuedExports = array_filter(
            $exports,
            static fn (stdClass $row): bool => (string) $row->status === 'queued',
        );
        $runningExports = array_filter(
            $exports,
            static fn (stdClass $row): bool => (string) $row->status === 'running',
        );

        return [
            'loginSessionsToRevoke' => count($sessions),
            'tokensToRevoke' => count($tokens),
            'uploadsToCancel' => count($pending),
            'uploadsToRequestCancel' => count($running),
            'exportsToCancel' => count($queuedExports),
            'exportsToRequestCancel' => count($runningExports),
            'playbackLeasesToRelease' => count($leases),
            // 冻结快照只保留服务端 ID 与状态版本并由 HMAC 令牌摘要，不会直接返回管理端浏览器。
            'snapshot' => [
                'sessions' => array_map(static fn (stdClass $row): array => [
                    (string) $row->id, (string) $row->user_id, (string) $row->expires_at,
                ], $sessions),
                'tokens' => array_map(static fn (stdClass $row): array => [
                    (string) $row->id, (string) $row->user_id, $row->expires_at, (string) $row->updated_at,
                ], $tokens),
                'uploads' => array_map(static fn (stdClass $row): array => [
                    (string) $row->id, (string) $row->user_id, (string) $row->status, (int) $row->version,
                    $row->cancel_requested_at, $row->cleanup_state, (string) $row->updated_at,
                ], $uploads),
                'exports' => array_map(static fn (stdClass $row): array => [
                    (string) $row->id, (string) $row->user_id, (string) $row->status, (int) $row->version,
                    $row->cancel_requested_at, (string) $row->updated_at,
                ], $exports),
                'playbackLeases' => array_map(static fn (stdClass $row): array => [
                    (string) $row->id, (string) $row->user_id, (string) $row->expires_at,
                ], $leases),
            ],
        ];
    }

    /**
     * 在调用方账号状态事务内撤销认证事实并冻结上传取消，不做文件系统清理。
     *
     * @param list<string> $userIds 本次实际由 active 变为 disabled 的账号。
     * @return array{sessionsRevoked:int,tokensRevoked:int,uploadsCancelled:int,uploadsCancelRequested:int,exportsCancelled:int,exportsCancelRequested:int,playbackLeasesReleased:int}
     */
    public function deactivateInTransaction(array $userIds, string $now): array
    {
        if ($userIds === []) {
            return [
                'sessionsRevoked' => 0,
                'tokensRevoked' => 0,
                'uploadsCancelled' => 0,
                'uploadsCancelRequested' => 0,
                'exportsCancelled' => 0,
                'exportsCancelRequested' => 0,
                'playbackLeasesReleased' => 0,
            ];
        }

        $sessions = Db::table('auth_sessions')->whereIn('user_id', $userIds)->whereNull('revoked_at')->update([
            'revoked_at' => $now,
            'revoked_reason' => 'account_disabled',
        ]);
        $tokens = Db::table('personal_access_tokens')->whereIn('user_id', $userIds)->whereNull('revoked_at')->update([
            'revoked_at' => $now,
            'revoked_reason' => 'account_disabled',
            'updated_at' => $now,
        ]);

        /** @var list<string> $pendingIds */
        $pendingIds = Db::table('upload_sessions')->whereIn('user_id', $userIds)
            ->whereIn('status', ['created', 'uploading', 'ready'])->pluck('id')->map(
                static fn (mixed $id): string => (string) $id,
            )->all();
        $cancelled = 0;
        if ($pendingIds !== []) {
            $cancelled = Db::table('upload_sessions')->whereIn('id', $pendingIds)
                ->whereIn('status', ['created', 'uploading', 'ready'])->update([
                    'status' => 'cancelled',
                    'cancel_requested_at' => $now,
                    'cancel_reason' => 'account_disabled',
                    'cancelled_at' => $now,
                    'finished_at' => $now,
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'error_code' => 'UPLOAD_ACCOUNT_DISABLED',
                    'cleanup_state' => 'pending',
                    'cleanup_error_code' => null,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            Db::table('upload_files')->whereIn('session_id', $pendingIds)
                ->whereNotIn('status', ['published', 'cancelled'])->update([
                    'status' => 'cancelled',
                    'error_code' => 'UPLOAD_ACCOUNT_DISABLED',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
        }

        $requested = Db::table('upload_sessions')->whereIn('user_id', $userIds)
            ->where('status', 'publishing')->whereNull('cancel_requested_at')->update([
                'cancel_requested_at' => $now,
                'cancel_reason' => 'account_disabled',
                'version' => Db::raw('version + 1'),
                'updated_at' => $now,
            ]);
        $exportsCancelled = 0;
        $exportsRequested = 0;
        if (Db::connection()->getSchemaBuilder()->hasTable('personal_data_export_jobs')) {
            // 只修改任务事实；导出临时文件和已发布产物由导出 Worker 在事务外按固定 Job 文件名清理。
            $exportsCancelled = Db::table('personal_data_export_jobs')->whereIn('user_id', $userIds)
                ->where('status', 'queued')->update([
                    'status' => 'cancelled', 'phase' => 'cancelled', 'cancel_requested_at' => $now,
                    'finished_at' => $now, 'error_code' => 'PERSONAL_EXPORT_ACCOUNT_DISABLED',
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            $exportsRequested = Db::table('personal_data_export_jobs')->whereIn('user_id', $userIds)
                ->where('status', 'running')->update([
                    'status' => 'cancel_requested', 'cancel_requested_at' => $now,
                    'error_code' => 'PERSONAL_EXPORT_ACCOUNT_DISABLED',
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
        }
        $leases = Db::table('playback_leases')->whereIn('user_id', $userIds)->delete();

        return [
            'sessionsRevoked' => $sessions,
            'tokensRevoked' => $tokens,
            'uploadsCancelled' => $cancelled,
            'uploadsCancelRequested' => $requested,
            'exportsCancelled' => $exportsCancelled,
            'exportsCancelRequested' => $exportsRequested,
            'playbackLeasesReleased' => $leases,
        ];
    }

    /** @return array{loginSessionsToRevoke:int,tokensToRevoke:int,uploadsToCancel:int,uploadsToRequestCancel:int,exportsToCancel:int,exportsToRequestCancel:int,playbackLeasesToRelease:int,snapshot:array<string,mixed>} */
    private function emptyImpact(): array
    {
        return [
            'loginSessionsToRevoke' => 0,
            'tokensToRevoke' => 0,
            'uploadsToCancel' => 0,
            'uploadsToRequestCancel' => 0,
            'exportsToCancel' => 0,
            'exportsToRequestCancel' => 0,
            'playbackLeasesToRelease' => 0,
            'snapshot' => ['sessions' => [], 'tokens' => [], 'uploads' => [], 'exports' => [], 'playbackLeases' => []],
        ];
    }
}
