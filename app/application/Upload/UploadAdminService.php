<?php

declare(strict_types=1);

namespace app\application\Upload;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 提供给存储管理员的上传会话查询与受控取消能力（ADMIN-PAGE-020）。
 *
 * `manage_storage` 只打开后台入口，普通管理员仍必须对会话目标库拥有实时 manage 授权；
 * 超级管理员才可跨库。响应只包含账号显示名、音乐库、逻辑相对路径和计数，不包含
 * 媒体根、暂存绝对路径、幂等摘要或 Worker 标识。
 *
 * 取消命令先以乐观版本封闭会话，再在 SQLite 事务外删除该会话的固定暂存节点。已被
 * Worker 领取、已发布或需要校准的失败会话不可由此服务清理，防止删除唯一恢复证据。
 */
final readonly class UploadAdminService
{
    public function __construct(
        private UploadStorageService $storage = new UploadStorageService(),
        private AuditLogger $audit = new AuditLogger(),
        private UploadWorkflowProjectionService $workflow = new UploadWorkflowProjectionService(),
    ) {
    }

    /**
     * 返回当前管理员可见的有界会话页。
     *
     * 状态、用户和音乐库筛选在对象范围条件之后叠加；筛选值必须由 Controller 预先通过固定
     * 词表和 ULID 校验。方法只读，不计算文件哈希或枚举物理暂存目录。
     *
     * @param array<string,mixed> $actor 当前 Session 解析的身份和库授权快照。
     * @return array{sessions:list<array<string,mixed>>,total:int,limit:int,offset:int,filterOptions:array<string,mixed>}
     */
    public function sessions(
        array $actor,
        ?string $status,
        ?string $libraryId,
        ?string $userId,
        int $limit,
        int $offset,
    ): array {
        $this->requireAdministrator($actor);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $base = $this->scopedQuery($actor);
        $optionsBase = clone $base;
        if ($status !== null) $base->where('sessions.status', $status);
        if ($libraryId !== null) $base->where('sessions.library_id', $libraryId);
        if ($userId !== null) $base->where('sessions.user_id', $userId);
        $total = (clone $base)->count('sessions.id');
        /** @var list<stdClass> $rows */
        $rows = $base->orderByDesc('sessions.created_at')->orderByDesc('sessions.id')
            ->offset($offset)->limit($limit)->get($this->columns())->all();

        return [
            'sessions' => array_map(fn (stdClass $row): array => $this->mapSession($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filterOptions' => $this->filterOptions($optionsBase),
        ];
    }

    /**
     * 返回一个经重新授权的会话与文件进度。
     *
     * 不存在和已失权都抛出 UploadNotFound，防止通过 ULID 探测其他库或用户。相对路径用于
     * 管理员定位上传清单，但仍不返回任何服务器绝对路径或磁盘 inode。
     *
     * @param array<string,mixed> $actor 当前管理身份。
     * @return array<string,mixed>
     */
    public function detail(array $actor, string $sessionId): array
    {
        $this->requireAdministrator($actor);
        $this->requireUlid($sessionId);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('sessions.id', $sessionId)->first($this->columns());
        if (!$row instanceof stdClass) throw new UploadNotFound('上传会话不存在。');
        $session = $this->mapSession($row);
        /** @var list<stdClass> $files */
        $files = Db::table('upload_files')->where('session_id', $sessionId)->orderBy('id')->get([
            'id', 'client_key', 'relative_path', 'media_kind', 'byte_size', 'received_bytes',
            'status', 'error_code', 'version', 'updated_at', 'completed_at', 'published_at',
        ])->all();
        $session['files'] = array_map(static fn (stdClass $file): array => [
            'id' => (string) $file->id,
            'clientKey' => (string) $file->client_key,
            'relativePath' => (string) $file->relative_path,
            'mediaKind' => (string) $file->media_kind,
            'byteSize' => (int) $file->byte_size,
            'receivedBytes' => (int) $file->received_bytes,
            'status' => (string) $file->status,
            'errorCode' => $file->error_code === null ? null : (string) $file->error_code,
            'version' => (int) $file->version,
            'updatedAt' => (string) $file->updated_at,
            'completedAt' => $file->completed_at === null ? null : (string) $file->completed_at,
            'publishedAt' => $file->published_at === null ? null : (string) $file->published_at,
        ], $files);
        $session['workflow'] = $session['status'] === 'completed'
            ? $this->workflow->summarize($sessionId, true)
            : null;

        return $session;
    }

    /**
     * 管理员取消一个尚未被 Worker 领取的上传。
     *
     * expectedVersion 防止管理员在用户刚好提交新分片或 Worker 领取后清理旧快照。会话终态先
     * 条件提交，然后才在事务外清理固定暂存目录；清理失败转为 `UPLOAD_CLEANUP_FAILED`
     * 并保留证据。成功后文件状态和脱敏审计在同一短事务内提交。
     *
     * @param array<string,mixed> $actor 当前管理身份。
     * @return array<string,mixed>
     */
    public function cancel(
        array $actor,
        string $sessionId,
        int $expectedVersion,
        string $requestId,
    ): array {
        $session = $this->internalSession($actor, $sessionId);
        if (!in_array((string) $session->status, ['created', 'uploading', 'ready'], true)
            || (int) $session->version !== $expectedVersion) {
            throw new UploadConflict('当前上传状态不允许管理员取消。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('upload_sessions')->where('id', $sessionId)
            ->where('version', $expectedVersion)->whereIn('status', ['created', 'uploading', 'ready'])->update([
                'status' => 'cancelled', 'cancelled_at' => $now, 'finished_at' => $now,
                'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
        if ($changed !== 1) throw new UploadConflict('上传会话状态已变化。');
        try {
            $this->storage->cleanupSession((string) $session->upload_root_path, $sessionId);
        } catch (Throwable $throwable) {
            Db::table('upload_sessions')->where('id', $sessionId)->where('status', 'cancelled')->update([
                'status' => 'failed', 'error_code' => 'UPLOAD_CLEANUP_FAILED',
                'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            throw new UploadStorageFailed('管理员取消后的上传暂存清理失败。', previous: $throwable);
        }
        Db::transaction(function () use ($actor, $requestId, $session, $sessionId): void {
            Db::table('upload_files')->where('session_id', $sessionId)->where('status', '!=', 'published')->update([
                'status' => 'cancelled', 'version' => Db::raw('version + 1'),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->audit->record((string) $actor['id'], 'upload.session.admin.cancel', 'upload_session',
                $sessionId, 'success', $requestId, [
                    'ownerUserId' => (string) $session->user_id,
                    'libraryId' => (string) $session->library_id,
                ]);
        });

        return $this->detail($actor, $sessionId);
    }

    /**
     * 清理已经结束的上传会话事实。
     *
     * 只接受 completed/cancelled/expired/failed；服务端重新验证管理员和目标库范围后，先在数据库事务
     * 外按登记的音乐库根清理该会话受控暂存，再用原状态条件删除会话事实。删除会级联上传清单和分片，
     * 但不会遍历或删除已经发布到音乐库的媒体文件；暂存节点身份异常或状态并发变化会失败关闭。
     */
    public function clearTerminal(array $actor, string $sessionId, string $status, string $requestId): void
    {
        $session = $this->internalSession($actor, $sessionId);
        if (!in_array($status, ['completed', 'cancelled', 'expired', 'failed'], true)
            || (string) $session->status !== $status) {
            throw new UploadConflict('当前上传状态不允许清理。');
        }
        $this->storage->cleanupSessionIfPresent((string) $session->upload_root_path, $sessionId);
        $changed = Db::table('upload_sessions')->where('id', $sessionId)->where('status', $status)->delete();
        if ($changed !== 1) throw new UploadConflict('上传状态已经变化。');
        $this->audit->record((string) $actor['id'], 'upload.session.admin.clear', 'upload_session',
            $sessionId, 'success', $requestId, [
                'libraryId' => (string) $session->library_id,
                'previousStatus' => $status,
                'publishedMediaDeleted' => false,
            ]);
    }

    /** @return stdClass 带内部上传根的受权会话，仅供精确暂存清理使用。 */
    private function internalSession(array $actor, string $sessionId): stdClass
    {
        $this->requireAdministrator($actor);
        $this->requireUlid($sessionId);
        /** @var stdClass|null $row */
        $query = Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->where('sessions.id', $sessionId);
        $this->scopeLibraries($query, $actor, 'sessions.library_id');
        $row = $query->first([
            'sessions.*',
            'libraries.resolved_root_path as upload_root_path',
        ]);
        if (!$row instanceof stdClass) throw new UploadNotFound('上传会话不存在。');
        return $row;
    }

    /** 构造包含显示关联但不含物理路径的管理查询，并首先应用库范围。 */
    private function scopedQuery(array $actor)
    {
        $query = Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->join('users as owners', 'owners.id', '=', 'sessions.user_id');
        $this->scopeLibraries($query, $actor, 'sessions.library_id');
        return $query;
    }

    /** 非超级管理员只允许 manage 库；空范围显式收窄为不可能命中的值。 */
    private function scopeLibraries(mixed $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) return;
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                && is_string($library['id'] ?? null)) $ids[] = $library['id'];
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /** @return list<string> 管理列表固定安全列，不包含物理路径与秘密摘要。 */
    private function columns(): array
    {
        return [
            'sessions.id', 'sessions.user_id', 'sessions.status', 'sessions.total_files',
            'sessions.total_bytes', 'sessions.received_bytes', 'sessions.completed_files',
            'sessions.published_files', 'sessions.error_code', 'sessions.attempt', 'sessions.version',
            'sessions.expires_at', 'sessions.created_at', 'sessions.updated_at', 'sessions.completed_at',
            'sessions.cancelled_at', 'sessions.scan_status', 'sessions.scan_job_id',
            'libraries.id as library_id', 'libraries.name as library_name',
            'owners.display_name as owner_name', 'owners.username as owner_username',
        ];
    }

    /** @return array<string,mixed> 会话管理投影的计数都来自原事实表，不伪造物理磁盘占用。 */
    private function mapSession(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'owner' => ['id' => (string) $row->user_id, 'displayName' => (string) $row->owner_name,
                'username' => (string) $row->owner_username],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'totalFiles' => (int) $row->total_files,
            'totalBytes' => (int) $row->total_bytes,
            'receivedBytes' => (int) $row->received_bytes,
            'completedFiles' => (int) $row->completed_files,
            'publishedFiles' => (int) $row->published_files,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'attempt' => (int) $row->attempt,
            'version' => (int) $row->version,
            'expiresAt' => (string) $row->expires_at,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
            'completedAt' => $row->completed_at === null ? null : (string) $row->completed_at,
            'cancelledAt' => $row->cancelled_at === null ? null : (string) $row->cancelled_at,
            'scanStatus' => $row->scan_status === null ? null : (string) $row->scan_status,
            'scanJobId' => $row->scan_job_id === null ? null : (string) $row->scan_job_id,
            'commands' => [
                'canCancel' => in_array((string) $row->status, ['created', 'uploading', 'ready'], true),
                'canCleanup' => false,
            ],
        ];
    }

    /** @return array{libraries:list<array{id:string,label:string}>,users:list<array{id:string,label:string}>} */
    private function filterOptions(mixed $base): array
    {
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->name,
            ])->all();
        $users = (clone $base)->select(['owners.id', 'owners.display_name'])->distinct()
            ->orderBy('owners.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->display_name,
            ])->all();
        return ['libraries' => $libraries, 'users' => $users];
    }

    /** 领域服务重复校验管理能力，不依赖 Controller 隐藏菜单或前置授权。 */
    private function requireAdministrator(array $actor): void
    {
        $id = $actor['id'] ?? null;
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1
            || !in_array('manage_storage', $capabilities, true)) {
            throw new UploadNotFound('上传管理功能不可用。');
        }
    }

    /** 管理路由只接受严格 ULID，避免对象范围条件被意外忽略。 */
    private function requireUlid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new UploadInvalid('上传会话标识无效。');
        }
    }
}
