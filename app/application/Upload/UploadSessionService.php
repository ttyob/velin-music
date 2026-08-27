<?php

declare(strict_types=1);

namespace app\application\Upload;

use app\application\Storage\StorageWriteGuard;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 管理受控歌曲上传会话、文件清单和连续分片状态（UPLOAD-001..006/008/009）。
 *
 * 所有入口同时校验 `manage_storage`、会话所有者和目标音乐库实时 manage 授权。普通用户没有上传
 * 能力，管理员也不能向只有 read 授权的库写入。物理媒体根只在服务端查询后传给 UploadStorageService，
 * 永不进入响应、审计或浏览器输入。SQLite 事务保持短小，目录创建、分片写入、fsync 与清理均在
 * 事务外；个人令牌和 Subsonic 不得调用本服务创建新会话。
 */
final readonly class UploadSessionService
{
    /**
     * 上传会话固定保留 24 小时，供断线续传并让过期清理拥有确定边界。
     *
     * 该值不是管理员或账号上传额度，也不参与新会话准入；到期只封闭尚未发布的受控暂存，会话清理仍
     * 必须验证目录所有权和文件身份。发布中的会话由 Worker 租约校准处理，不会被普通过期清理抢占。
     */
    private const SESSION_TTL_SECONDS = 24 * 3600;

    public function __construct(
        private UploadRelativePathSanitizer $paths = new UploadRelativePathSanitizer(),
        private UploadStorageService $storage = new UploadStorageService(),
        private AuditLogger $audit = new AuditLogger(),
        private UploadWorkflowProjectionService $workflow = new UploadWorkflowProjectionService(),
        private StorageWriteGuard $storageGuard = new StorageWriteGuard(),
    ) {
    }

    /**
     * 返回当前上传者可选择的活动本地音乐库及分片协议大小。
     *
     * 响应只有音乐库不透明 ID 和显示名，不包含媒体根或暂存路径。非超级管理员必须同时具有
     * manage_storage 和目标库 manage 授权；没有可用目标时返回空列表，不退化为全局音乐库。
     *
     * @param array<string,mixed> $actor 已通过 Session 认证的当前身份。
     * @return array<string,mixed>
     */
    public function options(array $actor): array
    {
        $this->requireUploader($actor);
        $query = Db::table('music_libraries as libraries')->where('libraries.status', 'active')
            ->where('libraries.source_type', 'local');
        $this->scopeLibraries($query, $actor, 'libraries.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('libraries.name')->get([
            'libraries.id as library_id', 'libraries.name as library_name',
        ])->all();

        return [
            'libraries' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->library_id,
                'name' => (string) $row->library_name,
            ], $rows),
            'chunkBytes' => UploadStorageService::CHUNK_MAX_BYTES,
            'acceptedExtensions' => [
                'mp3', 'flac', 'aac', 'm4a', 'm4b', 'alac', 'ogg', 'oga', 'opus', 'wav', 'aiff',
                'aif', 'wma', 'ape', 'jpg', 'jpeg', 'png', 'webp', 'lrc', 'm3u', 'm3u8', 'cue',
            ],
        ];
    }

    /**
     * 以 24 小时幂等键创建一个上传会话及不可变文件清单。
     *
     * files 每项只接受 clientKey、相对路径、正字节数和可选最终 SHA-256。路径清洗、扩展白名单、重复
     * 目标和整数溢出在任何文件 I/O 前完成。后台管理员上传不读取系统或账号额度，不按文件数、单文件、
     * 批次、活动会话或暂存声明字节拒绝；真实磁盘余量仍由 StorageWriteGuard 在事务外按整批声明值检查。
     * 暂存目录先以随机会话 ULID 建立；短 `BEGIN IMMEDIATE` 事务随后复验幂等键和目标音乐库授权，并与
     * 脱敏审计一同提交。事务失败会删除刚创建的空暂存目录；同键同请求返回原会话，同键异请求返回 409。
     *
     * @param array<string,mixed> $actor 当前上传者身份。
     * @param list<array<string,mixed>> $files 浏览器提交的完整文件清单。
     * @return array<string,mixed>
     */
    public function create(
        array $actor,
        string $libraryId,
        array $files,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $this->requireUploader($actor);
        $this->requireUlid($libraryId);
        $userId = (string) $actor['id'];
        $keyDigest = $this->idempotencyDigest($idempotencyKey);
        $normalized = $this->normalizeFiles($files);
        $totalBytes = $this->totalBytes($normalized);
        $requestDigest = hash('sha256', json_encode(
            ['libraryId' => $libraryId, 'files' => $normalized],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        /** @var stdClass|null $existing */
        $existing = Db::table('upload_sessions')->where('user_id', $userId)
            ->where('idempotency_digest', $keyDigest)->first();
        if ($existing instanceof stdClass) {
            if (!hash_equals((string) $existing->request_digest, $requestDigest)) {
                throw new UploadConflict('该幂等键已用于其他上传清单。');
            }
            return $this->detail($actor, (string) $existing->id);
        }

        $library = $this->library($actor, $libraryId);
        $sessionId = (string) new Ulid();
        $fileIds = array_map(static fn (): string => (string) new Ulid(), $normalized);
        // 在创建暂存目录前按完整清单预检；该文件系统采样明确位于 SQLite 事务之外。
        $this->storageGuard->assertUploadAllowed(
            (string) $library->resolved_root_path,
            $totalBytes,
        );
        $this->storage->prepareSession((string) $library->resolved_root_path, $sessionId);
        $created = false;
        $replayedId = null;
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            /** @var stdClass|null $race */
            $race = Db::table('upload_sessions')->where('user_id', $userId)
                ->where('idempotency_digest', $keyDigest)->first();
            if ($race instanceof stdClass) {
                if (!hash_equals((string) $race->request_digest, $requestDigest)) {
                    throw new UploadConflict('该幂等键已用于其他上传清单。');
                }
                $replayedId = (string) $race->id;
            } else {
                $this->requireLibraryInTransaction($actor, $libraryId);
                $now = gmdate('Y-m-d\TH:i:s\Z');
                Db::table('upload_sessions')->insert([
                    'id' => $sessionId, 'user_id' => $userId,
                    'library_id' => $libraryId, 'status' => 'created',
                    'total_files' => count($normalized), 'total_bytes' => $totalBytes,
                    'received_bytes' => 0, 'completed_files' => 0, 'published_files' => 0,
                    'idempotency_digest' => $keyDigest, 'request_digest' => $requestDigest,
                    'error_code' => null, 'attempt' => 0, 'version' => 1,
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::SESSION_TTL_SECONDS),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($normalized as $index => $file) {
                    Db::table('upload_files')->insert([
                        'id' => $fileIds[$index], 'session_id' => $sessionId,
                        'client_key' => $file['clientKey'], 'relative_path' => $file['relativePath'],
                        'extension' => $file['extension'], 'media_kind' => $file['mediaKind'],
                        'byte_size' => $file['byteSize'], 'received_bytes' => 0,
                        'expected_sha256' => $file['expectedSha256'], 'content_sha256' => null,
                        'status' => 'pending', 'error_code' => null, 'version' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $this->audit->record($userId, 'upload.session.create', 'upload_session', $sessionId,
                    'success', $requestId, [
                        'libraryId' => $libraryId, 'fileCount' => count($normalized),
                        'totalBytes' => $totalBytes,
                    ]);
                $created = true;
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $throwable) {
            $pdo->exec('ROLLBACK');
            try { $this->storage->cleanupSession((string) $library->resolved_root_path, $sessionId); } catch (Throwable) {}
            throw $throwable;
        }
        if (!$created) {
            $this->storage->cleanupSession((string) $library->resolved_root_path, $sessionId);
            return $this->detail($actor, (string) $replayedId);
        }

        return $this->detail($actor, $sessionId);
    }

    /**
     * 列出当前上传者本人创建的上传会话；跨账号诊断使用独立后台投影服务。
     *
     * 本方法始终按会话所有者收口，使用户端和后台工作台都只能恢复本账号批次。调用者必须具有
     * manage_storage；对象读取不返回物理路径，后续写命令仍实时复验目标库 manage 授权。
     *
     * @param array<string,mixed> $actor 当前上传者身份。
     * @return array{sessions:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function sessions(array $actor, int $limit, int $offset): array
    {
        $this->requireUploader($actor);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = $this->sessionQuery()->where('sessions.user_id', (string) $actor['id']);
        $total = (clone $query)->count('sessions.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('sessions.created_at')->orderByDesc('sessions.id')
            ->offset($offset)->limit($limit)->get($this->sessionColumns())->all();
        return ['sessions' => array_map(fn (stdClass $row): array => $this->mapSession($row), $rows),
            'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * 返回当前上传者本人创建的会话及文件进度，物理路径、分片摘要和文件身份不进入响应。
     *
     * @param array<string,mixed> $actor 当前上传者身份。
     * @return array<string,mixed>
     */
    public function detail(array $actor, string $sessionId): array
    {
        $this->requireUploader($actor);
        $this->requireUlid($sessionId);
        /** @var stdClass|null $row */
        $row = $this->sessionQuery()->where('sessions.id', $sessionId)
            ->where('sessions.user_id', (string) $actor['id'])->first($this->sessionColumns());
        if (!$row instanceof stdClass) throw new UploadNotFound('上传会话不存在。');
        $session = $this->mapSession($row);
        /** @var list<stdClass> $files */
        $files = Db::table('upload_files')->where('session_id', $sessionId)->orderBy('id')->get([
            'id', 'client_key', 'relative_path', 'media_kind', 'byte_size', 'received_bytes', 'status',
            'content_sha256', 'error_code', 'version', 'updated_at', 'completed_at', 'published_at',
        ])->all();
        $session['files'] = array_map(static fn (stdClass $file): array => [
            'id' => (string) $file->id, 'clientKey' => (string) $file->client_key,
            'relativePath' => (string) $file->relative_path, 'mediaKind' => (string) $file->media_kind,
            'byteSize' => (int) $file->byte_size, 'receivedBytes' => (int) $file->received_bytes,
            'status' => (string) $file->status,
            'sha256' => $file->content_sha256 === null ? null : (string) $file->content_sha256,
            'errorCode' => $file->error_code === null ? null : (string) $file->error_code,
            'version' => (int) $file->version, 'updatedAt' => (string) $file->updated_at,
            'completedAt' => $file->completed_at === null ? null : (string) $file->completed_at,
            'publishedAt' => $file->published_at === null ? null : (string) $file->published_at,
        ], $files);
        $session['workflow'] = $session['status'] === 'completed'
            ? $this->workflow->summarize($sessionId, false)
            : null;
        return $session;
    }

    /**
     * 接收一个最多 8 MiB 的连续分片，支持相同偏移、长度和哈希的安全幂等重试。
     *
     * 原始字节和路径不进入 SQLite 或日志。服务端先验证请求 SHA-256、会话所有权、未过期状态、文件
     * 边界和当前偏移，再由存储层写入并 fsync；其提交回调以条件更新同时写 chunk 事实、文件计数和
     * 会话计数。事务竞争会触发文件截断补偿。重复已提交分片只返回当前详情，不再次占用空间。
     *
     * @param array<string,mixed> $actor 当前上传者身份。
     * @return array<string,mixed>
     */
    public function appendChunk(
        array $actor,
        string $sessionId,
        string $fileId,
        int $byteOffset,
        string $bytes,
        string $sha256,
    ): array {
        $this->requireUploader($actor);
        $this->requireUlid($sessionId);
        $this->requireUlid($fileId);
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new UploadInvalid('上传分片哈希不匹配。');
        }
        $row = $this->ownedFile($actor, $sessionId, $fileId);
        $length = strlen($bytes);
        if ($byteOffset < (int) $row->received_bytes) {
            $chunk = Db::table('upload_chunks')->where('file_id', $fileId)
                ->where('byte_offset', $byteOffset)->first();
            if ($chunk instanceof stdClass && (int) $chunk->byte_length === $length
                && hash_equals((string) $chunk->sha256, $sha256)) {
                return $this->detail($actor, $sessionId);
            }
            throw new UploadConflict('上传分片与已接收范围冲突。');
        }
        if ($byteOffset !== (int) $row->received_bytes || $byteOffset + $length > (int) $row->byte_size) {
            throw new UploadConflict('上传分片偏移或长度与当前接收范围不一致。');
        }
        $libraryRoot = (string) $row->resolved_root_path;
        // 分片到达时重新检查安全余量，避免长会话期间外部写入耗尽磁盘。
        $this->storageGuard->assertUploadChunkAllowed($libraryRoot, $length);
        $this->storage->appendChunk($libraryRoot, $sessionId, $fileId, $byteOffset, $bytes,
            function () use ($byteOffset, $fileId, $length, $sessionId, $sha256): void {
                Db::transaction(function () use ($byteOffset, $fileId, $length, $sessionId, $sha256): void {
                    /** @var stdClass|null $current */
                    $current = Db::table('upload_files')->where('id', $fileId)->where('session_id', $sessionId)->first();
                    /** @var stdClass|null $session */
                    $session = Db::table('upload_sessions')->where('id', $sessionId)->first();
                    if (!$current instanceof stdClass || !$session instanceof stdClass
                        || !in_array((string) $session->status, ['created', 'uploading'], true)
                        || (string) $current->status === 'received'
                        || (int) $current->received_bytes !== $byteOffset) {
                        throw new UploadConflict('上传状态已变化，请刷新后重试。');
                    }
                    $now = gmdate('Y-m-d\TH:i:s\Z');
                    $next = $byteOffset + $length;
                    Db::table('upload_chunks')->insert([
                        'file_id' => $fileId, 'byte_offset' => $byteOffset, 'byte_length' => $length,
                        'sha256' => $sha256, 'received_at' => $now,
                    ]);
                    $changed = Db::table('upload_files')->where('id', $fileId)
                        ->where('received_bytes', $byteOffset)->update([
                            'received_bytes' => $next,
                            'status' => $next === (int) $current->byte_size ? 'received' : 'uploading',
                            'version' => Db::raw('version + 1'), 'updated_at' => $now,
                        ]);
                    if ($changed !== 1) throw new UploadConflict('上传文件进度已变化。');
                    $sessionChanged = Db::table('upload_sessions')->where('id', $sessionId)
                        ->whereIn('status', ['created', 'uploading'])->update([
                            'received_bytes' => Db::raw('received_bytes + ' . $length), 'status' => 'uploading',
                            'completed_files' => $next === (int) $current->byte_size
                                ? Db::raw('completed_files + 1')
                                : Db::raw('completed_files'),
                            'version' => Db::raw('version + 1'), 'updated_at' => $now,
                        ]);
                    if ($sessionChanged !== 1) throw new UploadConflict('上传会话状态已变化。');
                });
            });

        return $this->detail($actor, $sessionId);
    }

    /**
     * 把所有字节已经接收的会话排入校验与原子发布队列。
     *
     * 命令只提交 session ID 与 expectedVersion；目标目录和文件清单均取持久化事实。状态、所有文件
     * received、实时音乐库授权和版本在同一短事务复验，成功后由独立 Worker 执行哈希、媒体探测与文件
     * 操作。重复 ready 返回当前快照，不能创建第二个发布任务。
     */
    public function queuePublish(array $actor, string $sessionId, int $expectedVersion, string $requestId): array
    {
        $session = $this->ownedSession($actor, $sessionId);
        if ((string) $session->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new UploadConflict('上传会话已过期，不能发布。');
        }
        if ((string) $session->status === 'ready') return $this->detail($actor, $sessionId);
        if (!in_array((string) $session->status, ['created', 'uploading'], true)
            || (int) $session->version !== $expectedVersion) {
            throw new UploadConflict('上传会话状态或版本已变化。');
        }
        Db::transaction(function () use ($actor, $expectedVersion, $requestId, $session, $sessionId): void {
            $this->requireLibraryInTransaction($actor, (string) $session->library_id);
            $pending = Db::table('upload_files')->where('session_id', $sessionId)
                ->where('status', '!=', 'received')->count();
            if ($pending !== 0) throw new UploadConflict('仍有文件未完整接收。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('upload_sessions')->where('id', $sessionId)
                ->where('user_id', (string) $actor['id'])->where('version', $expectedVersion)
                ->whereIn('status', ['created', 'uploading'])->update([
                    'status' => 'ready', 'error_code' => null,
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new UploadConflict('上传会话状态已变化。');
            $this->audit->record((string) $actor['id'], 'upload.session.publish.queue', 'upload_session',
                $sessionId, 'success', $requestId, ['libraryId' => (string) $session->library_id,
                    'fileCount' => (int) $session->total_files]);
        });

        return $this->detail($actor, $sessionId);
    }

    /**
     * 取消一个尚未被发布 Worker 领取的会话，并只清理其固定暂存节点。
     *
     * 状态先以版本条件改为 cancelled，阻止并发分片提交和 Worker 领取；随后在事务外按文件所有权规则
     * 清理。清理失败会把会话标记为 failed/UPLOAD_CLEANUP_FAILED 以保留运维证据，不声称取消完成。
     */
    public function cancel(array $actor, string $sessionId, int $expectedVersion, string $requestId): array
    {
        $session = $this->ownedSession($actor, $sessionId);
        if (!in_array((string) $session->status, ['created', 'uploading', 'ready'], true)
            || (int) $session->version !== $expectedVersion) {
            throw new UploadConflict('当前上传状态不允许取消。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('upload_sessions')->where('id', $sessionId)
            ->where('user_id', (string) $actor['id'])->where('version', $expectedVersion)
            ->whereIn('status', ['created', 'uploading', 'ready'])->update([
                'status' => 'cancelled', 'cancelled_at' => $now, 'finished_at' => $now,
                'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
        if ($changed !== 1) throw new UploadConflict('上传会话状态已变化。');
        try {
            $this->storage->cleanupSession((string) $session->resolved_root_path, $sessionId);
        } catch (Throwable $throwable) {
            Db::table('upload_sessions')->where('id', $sessionId)->where('status', 'cancelled')->update([
                'status' => 'failed', 'error_code' => 'UPLOAD_CLEANUP_FAILED',
                'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            throw new UploadStorageFailed('上传取消后的暂存清理失败。', previous: $throwable);
        }
        Db::transaction(function () use ($actor, $requestId, $session, $sessionId): void {
            Db::table('upload_files')->where('session_id', $sessionId)
                ->whereNotIn('status', ['published'])->update([
                    'status' => 'cancelled', 'version' => Db::raw('version + 1'),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            $this->audit->record((string) $actor['id'], 'upload.session.cancel', 'upload_session',
                $sessionId, 'success', $requestId, ['libraryId' => (string) $session->library_id]);
        });

        return $this->detail($actor, $sessionId);
    }

    /**
     * 读取当前上传者拥有的上传会话及内部音乐库根。
     *
     * 查询限制会话所有者；调用前已校验上传能力，后续文件操作仍会重新验证根目录身份。
     * 不存在与越权统一失败，不泄露会话。
     *
     * @return stdClass 会话事实及仅供存储层使用的 `resolved_root_path`。
     */
    private function ownedSession(array $actor, string $sessionId): stdClass
    {
        $this->requireUploader($actor);
        $this->requireUlid($sessionId);
        /** @var stdClass|null $row */
        $row = Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->where('sessions.id', $sessionId)->where('sessions.user_id', (string) $actor['id'])
            ->first(['sessions.*', 'libraries.resolved_root_path']);
        if (!$row instanceof stdClass) throw new UploadNotFound('上传会话不存在。');
        return $row;
    }

    /**
     * 读取当前上传者可继续写入的文件、会话状态与内部音乐库根。
     *
     * 仅允许未过期且仍处于接收阶段的会话；失败统一按不可写对象处理。返回的物理根不得
     * 进入响应或日志，只能传给存储层完成分片身份校验与落盘。
     *
     * @return stdClass 文件、会话状态及 `resolved_root_path`。
     */
    private function ownedFile(array $actor, string $sessionId, string $fileId): stdClass
    {
        /** @var stdClass|null $row */
        $row = Db::table('upload_files as files')
            ->join('upload_sessions as sessions', 'sessions.id', '=', 'files.session_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->where('files.id', $fileId)->where('sessions.id', $sessionId)
            ->where('sessions.user_id', (string) $actor['id'])
            ->whereIn('sessions.status', ['created', 'uploading'])
            ->where('sessions.expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->first(['files.*', 'sessions.status as session_status', 'libraries.resolved_root_path']);
        if (!$row instanceof stdClass) throw new UploadNotFound('上传文件不存在或会话不可写。');
        return $row;
    }

    /**
     * 规范化不可变文件清单并拒绝重复 clientKey 或清洗后目标路径。
     *
     * @param list<array<string,mixed>> $files
     * @return list<array{clientKey:string,relativePath:string,extension:string,mediaKind:string,byteSize:int,expectedSha256:?string}>
     */
    private function normalizeFiles(array $files): array
    {
        if ($files === [] || !array_is_list($files)) {
            throw new UploadInvalid('上传文件数量无效。');
        }
        $result = [];
        $clientKeys = [];
        $paths = [];
        $total = 0;
        foreach ($files as $file) {
            if (!is_array($file)) throw new UploadInvalid('上传文件清单无效。');
            $clientKey = $file['clientKey'] ?? null;
            $relativePath = $file['relativePath'] ?? null;
            $byteSize = $file['byteSize'] ?? null;
            $expected = $file['sha256'] ?? null;
            if (!is_string($clientKey) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $clientKey) !== 1
                || !is_string($relativePath) || !is_int($byteSize) || $byteSize < 1
                || ($expected !== null && (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1))) {
                throw new UploadInvalid('上传文件清单字段无效。');
            }
            $path = $this->paths->sanitize($relativePath);
            $pathKey = mb_strtolower($path['relativePath']);
            if (isset($clientKeys[$clientKey]) || isset($paths[$pathKey])) {
                throw new UploadInvalid('上传清单包含重复文件标识或目标路径。');
            }
            $clientKeys[$clientKey] = true;
            $paths[$pathKey] = true;
            if ($byteSize > PHP_INT_MAX - $total) {
                throw new UploadInvalid('上传文件清单总字节数无效。');
            }
            $total += $byteSize;
            $result[] = $path + ['clientKey' => $clientKey, 'byteSize' => $byteSize, 'expectedSha256' => $expected];
        }
        return $result;
    }

    /**
     * 汇总已规范化清单的声明字节数，结果用于数据库事实和真实磁盘余量检查。
     *
     * normalizeFiles 已保证每项为正整数且总和不超过 PHP/SQLite 有符号整数边界；这里不再施加业务
     * 配额，也不访问文件系统。若调用顺序被破坏，溢出必须失败关闭，不能把浮点数写入 SQLite INTEGER。
     *
     * @param list<array{byteSize:int}> $files
     */
    private function totalBytes(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            if ($file['byteSize'] > PHP_INT_MAX - $total) {
                throw new UploadInvalid('上传文件清单总字节数无效。');
            }
            $total += $file['byteSize'];
        }

        return $total;
    }

    /** 查询当前上传者可写的活动本地音乐库；物理根只供受控存储层使用。 */
    private function library(array $actor, string $libraryId): stdClass
    {
        $query = Db::table('music_libraries as libraries')
            ->where('libraries.id', $libraryId)->where('libraries.status', 'active')
            ->where('libraries.source_type', 'local');
        $this->scopeLibraries($query, $actor, 'libraries.id');
        /** @var stdClass|null $row */
        $row = $query->first(['libraries.id', 'libraries.resolved_root_path']);
        if (!$row instanceof stdClass) throw new UploadNotFound('目标音乐库不存在或无权上传。');
        return $row;
    }

    /** 在写事务内重验音乐库仍活动且管理员的 manage 授权未撤销。 */
    private function requireLibraryInTransaction(array $actor, string $libraryId): void
    {
        $query = Db::table('music_libraries as libraries')->where('libraries.id', $libraryId)
            ->where('libraries.status', 'active')->where('libraries.source_type', 'local');
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }
        if (!$query->exists()) throw new UploadNotFound('目标音乐库授权已变化。');
    }

    /** 对查询应用 Session 中刚解析出的音乐库范围，空范围失败关闭。 */
    private function scopeLibraries(mixed $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) return;
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            $accessLevel = is_array($library) ? ($library['accessLevel'] ?? null) : null;
            if (is_array($library) && $accessLevel === 'manage'
                && is_string($library['id'] ?? null)) $ids[] = $library['id'];
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /** 校验上传能力和不可变用户 ID；内部服务不能依赖 Controller 已做过校验。 */
    private function requireUploader(array $actor): void
    {
        $id = $actor['id'] ?? null;
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1
            || !in_array('manage_storage', $capabilities, true)) {
            throw new UploadNotFound('上传功能不可用。');
        }
    }

    /** 以独立域 HMAC 保存幂等键，原始请求头不进入数据库、审计或日志。 */
    private function idempotencyDigest(string $key): string
    {
        if (strlen($key) < 8 || strlen($key) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw new UploadInvalid('上传幂等键无效。');
        }
        return hash_hmac('sha256', "velin-upload-session-idempotency-v1\0" . $key,
            RequestContext::authenticationHashKey());
    }

    /** 严格校验浏览器可引用的 ULID，避免对象条件意外丢失。 */
    private function requireUlid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new UploadInvalid('上传对象标识无效。');
    }

    /** @return \Illuminate\Database\Query\Builder 会话读取固定关联显示名，不读取物理路径。 */
    private function sessionQuery()
    {
        return Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id');
    }

    /** @return list<string> 会话公共投影列，不含幂等摘要、请求摘要、Worker 或路径。 */
    private function sessionColumns(): array
    {
        return ['sessions.id', 'sessions.status', 'sessions.total_files', 'sessions.total_bytes',
            'sessions.received_bytes', 'sessions.completed_files', 'sessions.published_files',
            'sessions.error_code', 'sessions.attempt', 'sessions.version', 'sessions.expires_at',
            'sessions.created_at', 'sessions.updated_at', 'sessions.completed_at', 'sessions.cancelled_at',
            'sessions.scan_status', 'sessions.scan_job_id',
            'libraries.id as library_id', 'libraries.name as library_name'];
    }

    /** @return array<string,mixed> 映射不含物理路径和秘密的用户私有会话摘要。 */
    private function mapSession(stdClass $row): array
    {
        return [
            'id' => (string) $row->id, 'status' => (string) $row->status,
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'totalFiles' => (int) $row->total_files, 'totalBytes' => (int) $row->total_bytes,
            'receivedBytes' => (int) $row->received_bytes, 'completedFiles' => (int) $row->completed_files,
            'publishedFiles' => (int) $row->published_files,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'attempt' => (int) $row->attempt, 'version' => (int) $row->version,
            'expiresAt' => (string) $row->expires_at, 'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
            'completedAt' => $row->completed_at === null ? null : (string) $row->completed_at,
            'cancelledAt' => $row->cancelled_at === null ? null : (string) $row->cancelled_at,
            'scanStatus' => $row->scan_status === null ? null : (string) $row->scan_status,
            'scanJobId' => $row->scan_job_id === null ? null : (string) $row->scan_job_id,
        ];
    }
}
