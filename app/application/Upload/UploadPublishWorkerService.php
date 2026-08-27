<?php

declare(strict_types=1);

namespace app\application\Upload;

use app\application\Lyrics\LyricsParseFailed;
use app\application\Lyrics\LyricsParser;
use app\application\Media\MediaProbe;
use app\application\Media\MediaProbeFailed;
use app\application\Playlist\M3uParser;
use app\application\Playlist\PlaylistImportInvalid;
use app\infrastructure\Media\FfprobeMediaProbe;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 在独立 Worker 中校验完整后台上传并原子公布到登记音乐库根（UPLOAD-002/004/006/008）。
 *
 * HTTP 只把完整接收的会话置为 ready。本服务以 SQLite 租约领取任务，随后在任何写事务之外执行大文件
 * SHA-256、FFprobe、图片/歌词/播放列表解析与文件发布。最终文件集合和会话终态在 publishBatch 的
 * 回调短事务内提交并登记持久扫描请求；文件失败会保留暂存供管理员诊断或取消，不自动覆盖目标或
 * 递归清理目录。所有会话都只面向当前音乐库根，不存在目录对兼容分支。
 */
final readonly class UploadPublishWorkerService
{
    private const LEASE_SECONDS = 600;

    public function __construct(
        private UploadStorageService $storage = new UploadStorageService(),
        private MediaProbe $probe = new FfprobeMediaProbe(),
        private LyricsParser $lyrics = new LyricsParser(),
        private M3uParser $playlists = new M3uParser(),
    ) {
    }

    /**
     * 原子领取最早 ready 会话并冻结其文件为 validating。
     *
     * 状态条件更新防止多个 Worker 重复执行；任务开始前重新校验账号仍有效，并满足以下任一边界：
     * 除超级管理员外，上传者必须仍通过角色拥有 manage_storage 且持有目标库 manage 授权。返回值包含
     * 内部 canonical 媒体根与固定文件事实，只能留在 Worker 进程，禁止日志或 API 序列化。
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('upload_sessions as sessions')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
                ->join('users', 'users.id', '=', 'sessions.user_id')
                ->where('sessions.status', 'ready')
                ->whereNull('sessions.cancel_requested_at')
                ->where('sessions.expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
                ->where('users.status', 'active')
                ->whereNull('users.deleted_at')
                ->where('libraries.status', 'active')
                ->where('libraries.source_type', 'local')
                ->whereRaw(<<<'SQL'
(
    users.is_super_admin = 1
    OR (
        EXISTS (
            SELECT 1 FROM user_roles ur
            JOIN role_capabilities rc ON rc.role_id = ur.role_id
            WHERE ur.user_id = users.id AND rc.capability_key = 'manage_storage'
        )
        AND EXISTS (
            SELECT 1 FROM library_user_grants lug
            WHERE lug.user_id = users.id
              AND lug.library_id = sessions.library_id
              AND lug.access_level = 'manage'
        )
    )
)
SQL)
                ->orderBy('sessions.created_at')->first([
                    'sessions.id', 'sessions.library_id', 'sessions.total_files',
                    'sessions.total_bytes', 'sessions.attempt', 'libraries.resolved_root_path',
                ]);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('upload_sessions')->where('id', (string) $row->id)
                ->where('status', 'ready')->whereNull('cancel_requested_at')->update([
                    'status' => 'publishing', 'attempt' => Db::raw('attempt + 1'), 'worker_id' => $workerId,
                    'heartbeat_at' => $now, 'started_at' => $now, 'error_code' => null,
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            Db::table('upload_files')->where('session_id', (string) $row->id)->where('status', 'received')->update([
                'status' => 'validating', 'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
            /** @var list<stdClass> $files */
            $files = Db::table('upload_files')->where('session_id', (string) $row->id)->orderBy('id')->get([
                'id', 'relative_path', 'extension', 'media_kind', 'byte_size', 'expected_sha256', 'status',
            ])->all();
            if (count($files) !== (int) $row->total_files
                || array_filter($files, static fn (stdClass $file): bool => (string) $file->status !== 'validating') !== []) {
                throw new UploadStorageFailed('上传文件集合与会话事实不一致。');
            }
            return [
                'id' => (string) $row->id, 'libraryId' => (string) $row->library_id,
                'watchRoot' => (string) $row->resolved_root_path,
                'files' => array_map(static fn (stdClass $file): array => [
                    'id' => (string) $file->id, 'relativePath' => (string) $file->relative_path,
                    'extension' => (string) $file->extension, 'mediaKind' => (string) $file->media_kind,
                    'byteSize' => (int) $file->byte_size,
                    'expectedSha256' => $file->expected_sha256 === null ? null : (string) $file->expected_sha256,
                ], $files),
            ];
        });
    }

    /**
     * 执行一个已领取会话的内容校验、无覆盖发布和终态提交。
     *
     * 每个文件先捕获哈希与 inode 身份，再按类型调用固定解析器。全部通过后一次预检目标集合并发布；
     * 数据库回调仍验证会话由本次 publishing 状态拥有。失败只保存稳定错误码，不记录第三方/FFprobe
     * 正文或路径。已经安全补偿的文件保留在暂存；无法补偿时错误码要求管理员校准。
     *
     * @param array<string,mixed> $job claimNext 返回的内部任务，不接受 HTTP 构造值。
     */
    public function execute(array $job): void
    {
        try {
            if ($this->cancellationRequested((string) $job['id'])) {
                $this->finalizeCancellation((string) $job['id']);
                return;
            }
            $evidence = [];
            foreach ($job['files'] as $file) {
                $inspected = $this->storage->inspectCompleteFile(
                    (string) $job['watchRoot'], (string) $job['id'], (string) $file['id'],
                    (int) $file['byteSize'], $file['expectedSha256'],
                );
                $this->validateMedia((string) $file['mediaKind'], (string) $file['extension'],
                    $inspected['path']);
                $evidence[] = [
                    'id' => (string) $file['id'], 'relativePath' => (string) $file['relativePath'],
                    'sha256' => $inspected['sha256'], 'device' => $inspected['device'],
                    'inode' => $inspected['inode'], 'size' => $inspected['size'],
                    'modifiedAt' => $inspected['modifiedAt'],
                ];
                // 文件校验是耗时的事务外步骤；每完成一个文件就响应停用请求，且尚未公布任何目标文件。
                if ($this->cancellationRequested((string) $job['id'])) {
                    $this->finalizeCancellation((string) $job['id']);
                    return;
                }
            }
            if ($this->cancellationRequested((string) $job['id'])) {
                $this->finalizeCancellation((string) $job['id']);
                return;
            }
            $stagingCleaned = $this->storage->publishBatch(
                (string) $job['watchRoot'],
                (string) $job['id'],
                $evidence,
                function () use ($evidence, $job): void {
                    Db::transaction(function () use ($evidence, $job): void {
                        $now = gmdate('Y-m-d\TH:i:s\Z');
                        $session = Db::table('upload_sessions as sessions')
                            ->join('users', 'users.id', '=', 'sessions.user_id')
                            ->where('sessions.id', (string) $job['id'])
                            ->where('sessions.status', 'publishing')
                            ->whereNull('sessions.cancel_requested_at')
                            ->where('users.status', 'active')->whereNull('users.deleted_at')
                            ->first(['sessions.id', 'sessions.user_id', 'sessions.library_id']);
                        if (!$session instanceof stdClass) throw new UploadConflict('上传发布所有权已变化。');
                        // 文件已经写入临时目标但尚未提交；此处失权会抛错并由 publishBatch 精确补偿，
                        // 从而保证撤权后的账号不能借用已领取任务完成最终发布。
                        if (!$this->uploaderAuthorizationExists(
                            (string) $session->user_id,
                            (string) $session->library_id,
                        )) throw new UploadConflict('上传发布权限已变化。');
                        foreach ($evidence as $file) {
                            $changed = Db::table('upload_files')->where('id', $file['id'])
                                ->where('session_id', (string) $job['id'])->where('status', 'validating')->update([
                                    'status' => 'published', 'content_sha256' => $file['sha256'],
                                    'staging_device' => $file['device'], 'staging_inode' => $file['inode'],
                                    'staging_size' => $file['size'], 'staging_modified_at' => $file['modifiedAt'],
                                    'error_code' => null, 'completed_at' => $now, 'published_at' => $now,
                                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                                ]);
                            if ($changed !== 1) throw new UploadConflict('上传文件终态已变化。');
                        }
                        $changed = Db::table('upload_sessions')->where('id', (string) $job['id'])
                            ->where('status', 'publishing')->update([
                                'status' => 'completed', 'completed_files' => count($evidence),
                                'published_files' => count($evidence), 'worker_id' => null, 'heartbeat_at' => null,
                                'error_code' => null, 'completed_at' => $now, 'finished_at' => $now,
                                'scan_status' => 'pending', 'scan_job_id' => null,
                                'version' => Db::raw('version + 1'), 'updated_at' => $now,
                            ]);
                        if ($changed !== 1) throw new UploadConflict('上传会话终态已变化。');
                    });
                },
            );
            if (!$stagingCleaned) {
                // 业务终态已与公布文件一致；这里只报告一个不含会话、路径或文件名的可回收运维事件。
                Log::warning('Upload staging directory cleanup is pending after successful publication.');
            }
        } catch (Throwable $throwable) {
            // publishBatch 会先精确补偿已公布目录项；补偿返回后才把账号停用请求收敛为取消终态。
            if ($this->cancellationRequested((string) $job['id'])) {
                $this->finalizeCancellation((string) $job['id']);
                return;
            }
            $this->fail((string) $job['id'], $this->errorCode($throwable));
        }
    }

    /**
     * 按当前数据库事实复验一个上传者与目标音乐库的组合授权。
     *
     * 超级管理员只需账号和本地库有效；其他管理员必须通过角色实时拥有 manage_storage，并持有目标库
     * manage 授权。方法只读数据库，不接受 Controller 能力快照；返回 false 会让发布事务失败，并由
     * 存储层补偿尚未提交的目标文件。已经撤销的历史用户上传能力不能继续推动旧会话发布。
     */
    private function uploaderAuthorizationExists(string $userId, string $libraryId): bool
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)->where('status', 'active')
            ->whereNull('deleted_at')->first(['id', 'is_super_admin']);
        $libraryExists = Db::table('music_libraries')->where('id', $libraryId)
            ->where('status', 'active')->where('source_type', 'local')->exists();
        if (!$user instanceof stdClass || !$libraryExists) return false;
        if ((int) $user->is_super_admin === 1) return true;

        $grant = Db::table('library_user_grants')->where('user_id', $userId)
            ->where('library_id', $libraryId)->value('access_level');
        if ($grant !== 'manage') return false;

        $managesStorage = Db::table('user_roles')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('role_capabilities.capability_key', 'manage_storage')->exists();
        return $managesStorage;
    }

    /**
     * 处理一个账号停用留下的上传暂存清理任务。
     *
     * 状态领取在短事务中完成，目录所有权复验与删除在事务外执行。进程在删除前后退出时，十分钟后的
     * running 租约可再次领取；最多尝试五次，失败只保留稳定错误码，不记录 watch root 或文件名。
     */
    public function processPendingCancellationCleanup(): bool
    {
        $job = $this->claimCancellationCleanup();
        if ($job === null) return false;
        try {
            $this->storage->cleanupSessionIfPresent($job['watchRoot'], $job['id']);
            Db::table('upload_sessions')->where('id', $job['id'])->where('cleanup_state', 'running')
                ->where('cleanup_attempt', $job['attempt'])->update([
                    'cleanup_state' => 'succeeded',
                    'cleanup_error_code' => null,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
        } catch (Throwable) {
            Db::table('upload_sessions')->where('id', $job['id'])->where('cleanup_state', 'running')
                ->where('cleanup_attempt', $job['attempt'])->update([
                    'cleanup_state' => $job['attempt'] >= 5 ? 'failed' : 'pending',
                    'cleanup_error_code' => 'UPLOAD_ACCOUNT_DISABLED_CLEANUP_FAILED',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
        }
        return true;
    }

    /**
     * 把发布成功的持久扫描请求合并为普通增量扫描任务。
     *
     * 同一音乐库只创建一个活动扫描；已有扫描时 pending 会话保持不变，待其终结后再排队，避免上传在
     * 扫描尾部公布却被错误标记为已覆盖。创建任务、库状态和全部待处理会话的 scan_job_id 在同一短事务
     * 提交。该系统任务不冒充某个管理员请求，也不绕过扫描 Worker 的真实文件发现与错误处理。
     */
    public function flushScanRequests(): void
    {
        /** @var list<stdClass> $libraries */
        $libraries = Db::table('upload_sessions')->where('status', 'completed')->where('scan_status', 'pending')
            ->select(['library_id'])->distinct()->orderBy('library_id')->get()->all();
        foreach ($libraries as $pendingLibrary) {
            Db::transaction(function () use ($pendingLibrary): void {
                $libraryId = (string) $pendingLibrary->library_id;
                $pending = Db::table('upload_sessions')->where('library_id', $libraryId)
                    ->where('status', 'completed')
                    ->where('scan_status', 'pending')->exists();
                if (!$pending || Db::table('library_scan_jobs')->where('library_id', $libraryId)
                    ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) return;
                $libraryActive = Db::table('music_libraries')->where('id', $libraryId)
                    ->where('status', 'active')->exists();
                if (!$libraryActive) return;
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $scanJobId = (string) new Ulid();
                Db::table('library_scan_jobs')->insert([
                    'id' => $scanJobId, 'library_id' => $libraryId, 'requested_by' => null,
                    'scan_type' => 'incremental', 'status' => 'queued', 'phase' => 'queued',
                    'processed_entries' => 0, 'discovered_files' => 0, 'added_files' => 0,
                    'missing_files' => 0, 'ignored_entries' => 0, 'failed_entries' => 0, 'attempt' => 0,
                    'worker_id' => null, 'heartbeat_at' => null, 'cancel_requested_at' => null,
                    'started_at' => null, 'finished_at' => null, 'error_code' => null, 'error_message' => null,
                    'request_id' => 'upload:' . $scanJobId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                Db::table('music_libraries')->where('id', $libraryId)->update([
                    'scan_status' => 'queued', 'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
                Db::table('upload_sessions')->where('library_id', $libraryId)
                    ->where('status', 'completed')
                    ->where('scan_status', 'pending')->update([
                        'scan_status' => 'enqueued', 'scan_job_id' => $scanJobId,
                        'version' => Db::raw('version + 1'), 'updated_at' => $now,
                    ]);
            });
        }
    }

    /**
     * 把超过十分钟未更新的 publishing 租约标记为需要校准的失败。
     *
     * Worker 崩溃可能发生在文件公布与数据库提交之间，因此这里不自动重试、移动或删除文件；管理员
     * 后续需按会话/文件身份检查。条件更新确保新 Worker 心跳不会被旧扫描覆盖。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('upload_sessions as sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('sessions.status', 'publishing')->where('sessions.heartbeat_at', '<', $threshold)
            ->get([
                'sessions.id', 'sessions.heartbeat_at', 'sessions.cancel_requested_at',
                'users.status as user_status', 'users.deleted_at as user_deleted_at',
            ])->all();
        foreach ($rows as $row) {
            if ($row->cancel_requested_at !== null || (string) $row->user_status !== 'active'
                || $row->user_deleted_at !== null) {
                $this->finalizeCancellation((string) $row->id);
                continue;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('upload_sessions')->where('id', (string) $row->id)
                ->where('status', 'publishing')->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                    'status' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
                    'error_code' => 'UPLOAD_LEASE_EXPIRED_REQUIRES_RECONCILIATION', 'finished_at' => $now,
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed === 1) Db::table('upload_files')->where('session_id', (string) $row->id)
                ->where('status', 'validating')->update([
                    'status' => 'failed', 'error_code' => 'UPLOAD_RECONCILIATION_REQUIRED',
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
        }
    }

    /** 当前 publishing 会话已收到取消请求，或其账号已失效时返回 true。 */
    private function cancellationRequested(string $sessionId): bool
    {
        /** @var stdClass|null $row */
        $row = Db::table('upload_sessions as sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('sessions.id', $sessionId)->first([
                'sessions.status', 'sessions.cancel_requested_at', 'users.status as user_status',
                'users.deleted_at as user_deleted_at',
            ]);
        if (!$row instanceof stdClass || (string) $row->status !== 'publishing') return false;
        return $row->cancel_requested_at !== null
            || (string) $row->user_status !== 'active'
            || $row->user_deleted_at !== null;
    }

    /** 把 publishing 取消请求提交为终态并登记事务外清理；不在这里访问文件系统。 */
    private function finalizeCancellation(string $sessionId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($now, $sessionId): void {
            $changed = Db::table('upload_sessions')->where('id', $sessionId)
                ->where('status', 'publishing')->update([
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
            if ($changed !== 1) return;
            Db::table('upload_files')->where('session_id', $sessionId)
                ->whereNotIn('status', ['published', 'cancelled'])->update([
                    'status' => 'cancelled',
                    'error_code' => 'UPLOAD_ACCOUNT_DISABLED',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
        });
    }

    /** @return array{id:string,watchRoot:string,attempt:int}|null */
    private function claimCancellationCleanup(): ?array
    {
        return Db::transaction(function (): ?array {
            $stale = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
            /** @var stdClass|null $row */
            $row = Db::table('upload_sessions as sessions')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
                ->where('sessions.status', 'cancelled')->where('sessions.cleanup_attempt', '<', 5)
                ->where(static function ($query) use ($stale): void {
                    $query->where('sessions.cleanup_state', 'pending')
                        ->orWhere(static function ($running) use ($stale): void {
                            $running->where('sessions.cleanup_state', 'running')
                                ->where('sessions.updated_at', '<=', $stale);
                        });
                })
                ->orderBy('sessions.updated_at')->first([
                    'sessions.id', 'sessions.cleanup_state', 'sessions.cleanup_attempt', 'sessions.updated_at',
                    'libraries.resolved_root_path as upload_root_path',
                ]);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('upload_sessions')->where('id', (string) $row->id)
                ->where('status', 'cancelled')->where('cleanup_state', (string) $row->cleanup_state)
                ->where('cleanup_attempt', (int) $row->cleanup_attempt)
                ->where('updated_at', (string) $row->updated_at)->update([
                    'cleanup_state' => 'running',
                    'cleanup_attempt' => Db::raw('cleanup_attempt + 1'),
                    'cleanup_error_code' => null,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            return [
                'id' => (string) $row->id,
                'watchRoot' => (string) $row->upload_root_path,
                'attempt' => (int) $row->cleanup_attempt + 1,
            ];
        });
    }

    /** 根据固定文件类别执行内容级校验，不信任扩展名或浏览器 MIME。 */
    private function validateMedia(string $kind, string $extension, string $path): void
    {
        if ($kind === 'audio') {
            $this->probe->probe($path, pathinfo($path, PATHINFO_FILENAME));
            return;
        }
        if ($kind === 'image') {
            $info = @getimagesize($path);
            $allowed = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG,
                'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
            if (!is_array($info) || (int) ($info[2] ?? 0) !== ($allowed[$extension] ?? -1)) {
                throw new UploadStorageFailed('上传图片内容与扩展名不匹配。');
            }
            return;
        }
        $maximum = 1_048_576;
        $bytes = @file_get_contents($path, false, null, 0, $maximum + 1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $maximum) {
            throw new UploadStorageFailed('上传文本附属文件大小无效。');
        }
        if ($kind === 'lyrics') {
            $this->lyrics->parse($bytes);
            return;
        }
        if ($kind === 'playlist') {
            $this->playlists->parse($bytes);
            return;
        }
        if ($kind === 'cue') {
            if (!mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")
                || preg_match('/(?mi)^\s*(FILE|TRACK)\s+/', $bytes) !== 1) {
                throw new UploadStorageFailed('上传 CUE 内容无效。');
            }
            return;
        }
        throw new UploadStorageFailed('上传文件类别无效。');
    }

    /** 只在仍拥有 publishing 状态时保存稳定错误码，避免覆盖已完成或已取消终态。 */
    private function fail(string $sessionId, string $errorCode): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($errorCode, $now, $sessionId): void {
            $changed = Db::table('upload_sessions')->where('id', $sessionId)->where('status', 'publishing')->update([
                'status' => 'failed', 'worker_id' => null, 'heartbeat_at' => null, 'error_code' => $errorCode,
                'finished_at' => $now, 'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
            if ($changed === 1) Db::table('upload_files')->where('session_id', $sessionId)
                ->where('status', 'validating')->update([
                    'status' => 'failed', 'error_code' => $errorCode,
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
        });
    }

    /** 把内部异常归一化为不含路径、解析正文或第三方数据的稳定机器码。 */
    private function errorCode(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof MediaProbeFailed => 'UPLOAD_AUDIO_INVALID',
            $throwable instanceof LyricsParseFailed => 'UPLOAD_LYRICS_INVALID',
            $throwable instanceof PlaylistImportInvalid => 'UPLOAD_PLAYLIST_INVALID',
            $throwable instanceof UploadHashMismatch => 'UPLOAD_HASH_MISMATCH',
            $throwable instanceof UploadConflict => 'UPLOAD_TARGET_CONFLICT',
            $throwable instanceof UploadStorageFailed => 'UPLOAD_STORAGE_FAILED',
            default => 'UPLOAD_INTERNAL_FAILED',
        };
    }
}
