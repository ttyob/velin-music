<?php

declare(strict_types=1);

namespace app\application\Upload;

use stdClass;
use support\Db;
use Throwable;

/**
 * 领取并安全回收过期上传会话的暂存文件（UPLOAD-003/009）。
 *
 * 回收分成三个可恢复阶段：短事务把活动会话条件更新为 expired/pending，事务外严格验证并删除它拥有
 * 的暂存节点，再以条件更新提交 expired 终态。若进程在文件删除后退出，下一轮通过幂等清理继续；若
 * 目录身份异常，则保留现场并标记 failed，不扩大到音乐库根的其他节点。publishing 会话不参与
 * TTL 回收，因为发布 Worker 正在执行完整校验或原子公布，其租约由独立校准逻辑负责。
 */
final readonly class UploadExpirationService
{
    private const PENDING_CODE = 'UPLOAD_EXPIRATION_CLEANUP_PENDING';

    public function __construct(private UploadStorageService $storage = new UploadStorageService())
    {
    }

    /**
     * 处理一个有界批次，避免积压清理长期占用 Worker 或 SQLite。
     *
     * @return array{expired:int,failed:int,pending:int}
     */
    public function pruneExpired(string $now, int $batchSize = 25): array
    {
        $batchSize = max(1, min(100, $batchSize));
        $jobs = $this->pendingJobs($batchSize);
        $remaining = $batchSize - count($jobs);
        if ($remaining > 0) {
            /** @var list<stdClass> $candidates */
            $candidates = Db::table('upload_sessions')->whereIn('status', ['created', 'uploading', 'ready'])
                ->where('expires_at', '<=', $now)->orderBy('expires_at')->limit($remaining)
                ->get(['id'])->all();
            foreach ($candidates as $candidate) {
                $claimed = $this->claim((string) $candidate->id, $now);
                if ($claimed !== null) $jobs[] = $claimed;
            }
        }

        $result = ['expired' => 0, 'failed' => 0, 'pending' => count($jobs)];
        foreach ($jobs as $job) {
            try {
                $this->storage->cleanupSessionIfPresent($job['watchRoot'], $job['id']);
                $changed = Db::table('upload_sessions')->where('id', $job['id'])
                    ->where('status', 'expired')->where('error_code', self::PENDING_CODE)->update([
                        'error_code' => 'UPLOAD_SESSION_EXPIRED',
                        'version' => Db::raw('version + 1'),
                        'updated_at' => $now,
                    ]);
                if ($changed === 1) ++$result['expired'];
            } catch (Throwable) {
                Db::transaction(function () use ($job, $now): void {
                    $changed = Db::table('upload_sessions')->where('id', $job['id'])
                        ->where('status', 'expired')->where('error_code', self::PENDING_CODE)->update([
                            'status' => 'failed',
                            'error_code' => 'UPLOAD_EXPIRATION_CLEANUP_FAILED',
                            'version' => Db::raw('version + 1'),
                            'updated_at' => $now,
                        ]);
                    if ($changed === 1) Db::table('upload_files')->where('session_id', $job['id'])
                        ->whereNotIn('status', ['published'])->update([
                            'status' => 'failed',
                            'error_code' => 'UPLOAD_EXPIRATION_CLEANUP_FAILED',
                            'version' => Db::raw('version + 1'),
                            'updated_at' => $now,
                        ]);
                });
                ++$result['failed'];
            }
        }
        $result['pending'] -= $result['expired'] + $result['failed'];
        return $result;
    }

    /** @return list<array{id:string,watchRoot:string}> 返回上次崩溃留下的可续清理事实。 */
    private function pendingJobs(int $limit): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->where('sessions.status', 'expired')->where('sessions.error_code', self::PENDING_CODE)
            ->orderBy('sessions.updated_at')->limit($limit)
            ->get([
                'sessions.id',
                'libraries.resolved_root_path as upload_root_path',
            ])->all();
        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'watchRoot' => (string) $row->upload_root_path,
        ], $rows);
    }

    /**
     * 在短事务内封闭会话和文件写入，并返回服务端登记的当前音乐库根。
     *
     * @return array{id:string,watchRoot:string}|null 状态或到期时间已变化时返回 null。
     */
    private function claim(string $sessionId, string $now): ?array
    {
        return Db::transaction(function () use ($now, $sessionId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('upload_sessions as sessions')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
                ->where('sessions.id', $sessionId)
                ->whereIn('sessions.status', ['created', 'uploading', 'ready'])
                ->where('sessions.expires_at', '<=', $now)
                ->first([
                    'sessions.version',
                    'libraries.resolved_root_path as upload_root_path',
                ]);
            if (!$row instanceof stdClass) return null;
            $changed = Db::table('upload_sessions')->where('id', $sessionId)
                ->where('version', (int) $row->version)
                ->whereIn('status', ['created', 'uploading', 'ready'])
                ->where('expires_at', '<=', $now)->update([
                    'status' => 'expired',
                    'error_code' => self::PENDING_CODE,
                    'finished_at' => $now,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            Db::table('upload_files')->where('session_id', $sessionId)
                ->whereNotIn('status', ['published'])->update([
                    'status' => 'cancelled',
                    'error_code' => 'UPLOAD_SESSION_EXPIRED',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            return ['id' => $sessionId, 'watchRoot' => (string) $row->upload_root_path];
        });
    }
}
