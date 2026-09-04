<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Storage\StorageLayout;
use app\application\Artwork\ArtworkBlobStore;

use Closure;
use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use app\application\Library\RemoteLibraryClientFactory;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Library\WebDavObject;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Database\SqliteTransientRetry;
use app\infrastructure\Database\SqliteWriteGate;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 登记并消费单曲刮削派生资源 outbox。
 *
 * `enqueue()` 必须在同步刮削保存业务事实的同一短事务内调用，只保存来源引用、摘要和策略快照，不做
 * 文件 I/O。Worker 领取后在事务外重新读取歌词/封面内容，复验发起账号权限、库策略、库存身份和
 * 许可，再通过原子非覆盖发布器写缓存或相邻文件。数据库终态提交失败时，租约恢复会重放；内容相同
 * 的既有目标被发布器视为幂等成功，不同内容进入 conflict，绝不会自动覆盖。
 */
final class ScrapeAssetPublicationService
{
    private const LEASE_SECONDS = 180;
    private const MAX_STALE_LEASE_RECOVERIES = 100;

    public function __construct(
        private readonly ScrapeAssetFilePublisher $publisher = new ScrapeAssetFilePublisher(),
        private readonly CapabilityResolver $capabilities = new CapabilityResolver(),
        private readonly LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly RemoteLibraryClientFactory $remoteClients = new RemoteLibraryClientFactory(),
        private readonly SqliteTransientRetry $sqliteRetry = new SqliteTransientRetry(),
        private readonly SqliteWriteGate $sqliteWriteGate = new SqliteWriteGate(),
        private readonly ArtworkBlobStore $artworkBlobs = new ArtworkBlobStore(),
    ) {}

    /**
     * 在刮削成功事务内登记一份歌曲封面。
     *
     * 歌词由刮削 Worker 在事务外先发布，再于业务事务登记文件索引，不再进入异步 outbox。平台封面
     * 仍使用该 outbox：`managed_cache` 写内容寻址缓存，`adjacent` 写音频同基名 `.webp`，两者都不得
     * 覆盖已有不同内容的文件。
     */
    public function enqueue(string $targetId, ?string $lyricId, ?string $artworkCandidateId, string $now): void
    {
        /** @var stdClass|null $target */
        $target = Db::table('metadata_sync_scrape_targets as targets')
            ->join('media_songs as songs', 'songs.id', '=', 'targets.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('targets.id', $targetId)->first([
                'targets.id', 'songs.id as song_id', 'songs.library_id', 'songs.inventory_file_id',
                'libraries.scrape_storage_mode',
            ]);
        if (!$target instanceof stdClass) {
            throw new MediaMetadataConflict('刮削派生资源缺少当前库存身份。');
        }
        if ($artworkCandidateId !== null) {
            /** @var stdClass|null $artwork */
            $artwork = Db::table('media_manual_artwork_candidates')->where('id', $artworkCandidateId)
                ->where('song_id', (string) $target->song_id)
                ->where('library_id', (string) $target->library_id)
                ->first(['id', 'content_sha256']);
            if (!$artwork instanceof stdClass) throw new MediaMetadataConflict('刮削封面来源已经变化。');
            $allowed = $this->licenseAllows('cache_allowed', (string) $target->scrape_storage_mode);
            $this->enqueueIdempotently(
                $target,
                'artwork',
                (string) $artwork->id,
                (string) $artwork->content_sha256,
                1,
                'cache_allowed',
                $allowed,
                $now,
            );
        }
    }

    /**
     * 以资源身份幂等登记发布任务，吸收事务提交后的重试和进程崩溃恢复。
     *
     * SQLite 在事务提交或锁竞争边界可能已经落下资源 outbox 行，但调用方仍会按原目标重放；
     * 直接 INSERT 会命中 `scrape_target_id/resource_kind/source_record_id` 唯一约束并把本应成功
     * 的刮削误报为内部失败。相同来源摘要只复用现有行：queued/running/succeeded 保持原状态，
     * failed/conflict/skipped 在当前许可允许时恢复为 queued；来源摘要或版本变化则失败关闭，避免
     * 把旧发布任务绑定到新封面。该方法只修改数据库，不执行文件操作，调用方位于外层短事务内。
     */
    private function enqueueIdempotently(
        stdClass $target,
        string $kind,
        string $sourceId,
        string $sourceSha256,
        int $version,
        string $licensePolicy,
        bool $allowed,
        string $now,
    ): void {
        /** @var stdClass|null $existing */
        $existing = Db::table('scrape_asset_publications')
            ->where('scrape_target_id', (string) $target->id)
            ->where('resource_kind', $kind)
            ->where('source_record_id', $sourceId)
            ->first([
                'id', 'source_sha256', 'source_version', 'license_policy', 'storage_mode', 'status',
            ]);
        if (!$existing instanceof stdClass) {
            $this->insertPublication(
                $target,
                $kind,
                $sourceId,
                $sourceSha256,
                $version,
                $licensePolicy,
                $allowed,
                $now,
            );
            return;
        }
        if (!hash_equals((string) $existing->source_sha256, $sourceSha256)
            || (int) $existing->source_version !== $version
            || (string) $existing->license_policy !== $licensePolicy
            || (string) $existing->storage_mode !== (string) $target->scrape_storage_mode) {
            throw new MediaMetadataConflict('刮削资源发布身份已经变化。');
        }
        if (!$allowed || in_array((string) $existing->status, ['queued', 'running', 'succeeded'], true)) {
            return;
        }
        Db::table('scrape_asset_publications')->where('id', (string) $existing->id)->update([
            'status' => 'queued',
            'published_relative_path' => null,
            'error_code' => null,
            'attempt' => 0,
            'worker_id' => null,
            'heartbeat_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ]);
    }

    /**
     * 条件领取最早的发布行；同一行只允许一个 Worker 获得租约。
     *
     * scrapeTargetId 仅供逐曲有界 drain 锁定当前歌曲的发布任务，防止其他批次的旧发布行插队后让当前
     * target 错过即时资源汇总。省略时保持后台恢复流程的全局最早顺序；筛选只使用内部 target ID，
     * 不接受浏览器输入，也不改变发布内容、路径或许可边界。
     */
    public function claimNext(string $workerId, ?string $scrapeTargetId = null): ?array
    {
        return Db::transaction(function () use ($workerId, $scrapeTargetId): ?array {
            /** @var stdClass|null $row */
            $query = Db::table('scrape_asset_publications')->where('status', 'queued')->whereNull('worker_id');
            if ($scrapeTargetId !== null) $query->where('scrape_target_id', $scrapeTargetId);
            $row = $query->orderBy('created_at')->orderBy('id')->first(['id']);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('scrape_asset_publications')->where('id', (string) $row->id)
                ->where('status', 'queued')->whereNull('worker_id')->update([
                    'status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                    'attempt' => Db::raw('attempt + 1'), 'started_at' => $now, 'updated_at' => $now,
                ]);
            return $changed === 1 ? ['id' => (string) $row->id, 'workerId' => $workerId] : null;
        });
    }

    /**
     * 执行一个已领取资源，文件 I/O 全部位于 SQLite 写事务之外。
     *
     * @param array{id:string,workerId:string} $claimed
     */
    public function execute(array $claimed): void
    {
        /** @var stdClass|null $row */
        $row = Db::table('scrape_asset_publications as publications')
            ->join('metadata_sync_scrape_targets as targets', 'targets.id', '=', 'publications.scrape_target_id')
            ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
            ->join('media_songs as songs', 'songs.id', '=', 'publications.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'publications.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'publications.library_id')
            ->where('publications.id', $claimed['id'])->where('publications.status', 'running')
            ->where('publications.worker_id', $claimed['workerId'])->first([
                'publications.*', 'targets.job_id', 'jobs.requested_by', 'jobs.request_id',
                'songs.library_id as song_library_id', 'songs.inventory_file_id as song_inventory_file_id',
                'files.relative_path as audio_relative_path', 'files.resolved_path as audio_resolved_path',
                'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
                'files.remote_etag',
                'files.status as file_status', 'files.metadata_status',
                'libraries.resolved_root_path', 'libraries.scrape_storage_mode as current_storage_mode',
                'libraries.status as library_status', 'libraries.source_type',
            ]);
        if (!$row instanceof stdClass) return;
        try {
            $this->assertCurrentScope($row);
            [$content, $extension] = $this->sourceContent($row);
            $outputSha256 = hash('sha256', $content);
            $relativePath = (string) $row->storage_mode === 'managed_cache'
                ? $this->cacheRelativePath($row, $outputSha256, $extension)
                : $this->adjacentRelativePath($row, $extension);
            $audioIdentity = (string) $row->storage_mode === 'adjacent' ? [
                'relativePath' => (string) $row->audio_relative_path,
                'device' => (int) $row->device_id,
                'inode' => (int) $row->inode,
                'size' => (int) $row->file_size,
                'modifiedAt' => (int) $row->modified_at,
            ] : null;
            $root = (string) $row->storage_mode === 'managed_cache'
                ? $this->cacheRoot() : rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
            $published = $this->publisher->publish(
                $root, $relativePath, $content, $outputSha256,
                (string) $row->storage_mode === 'managed_cache', $audioIdentity,
            );
        } catch (ScrapeAssetPublicationFailed $failure) {
            $this->finishFailed($row, $failure->errorCode, $failure->conflict);
            return;
        } catch (Throwable) {
            $this->finishFailed($row, 'SCRAPE_ASSET_INTERNAL_FAILED', false);
            return;
        }
        try {
            $this->finishSucceeded($row, $published);
        } catch (Throwable $throwable) {
            // 文件已发布后若 SQLite 提交失败，必须保留 running 租约。超时恢复会重新读取同一来源，
            // 发布器以目标摘要识别幂等成功；此处改成 failed 会让已存在文件失去可靠的数据库终态。日志只
            // 记录内部标识与异常类型，不记录已发布路径、来源内容、摘要或服务器目录。
            if (is_array(config('log'))) {
                try {
                    Log::error('Scrape asset was published but its database state was not committed.', [
                        'publication_id' => (string) $row->id,
                        'target_id' => (string) $row->scrape_target_id,
                        'reason_code' => 'SCRAPE_ASSET_FINALIZE_DEFERRED',
                        'exception_class' => $throwable::class,
                    ]);
                } catch (Throwable) {
                    // 日志不可用不能改变已经发生的文件事实，租约恢复仍是唯一可靠补偿路径。
                }
            }
        }
    }

    /**
     * 有界恢复资源发布租约；已落盘目标会在重试时通过内容摘要成为幂等成功。
     *
     * 每轮只按心跳和 ID 稳定恢复一百行，并在一个短事务中用旧心跳执行 CAS。这样大规模进程中断不会
     * 形成无界数组或逐行自动提交风暴；未处理行由后续轮询继续恢复。恢复只释放租约，不删除或覆盖文件。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        $this->writeTransaction(function () use ($threshold): void {
            /** @var list<stdClass> $rows */
            $rows = Db::table('scrape_asset_publications')->where('status', 'running')
                ->whereNotNull('worker_id')->where('heartbeat_at', '<', $threshold)
                ->orderBy('heartbeat_at')->orderBy('id')->limit(self::MAX_STALE_LEASE_RECOVERIES)
                ->get(['id', 'heartbeat_at'])->all();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            foreach ($rows as $row) {
                Db::table('scrape_asset_publications')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                        'status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null,
                        'error_code' => null, 'updated_at' => $now,
                    ]);
            }
        });
    }

    /**
     * 在进程级写闸门内重试一个可幂等重放的资源队列短事务。
     *
     * 调用方不得把文件读取或发布放进该闭包；SQLite BUSY/LOCKED 最多按共享策略退避三次，其他异常原样
     * 抛出。租约恢复使用旧心跳 CAS，因此事务整体重放不会接管已续租行，也不会产生文件副作用。
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function writeTransaction(Closure $operation): mixed
    {
        return $this->sqliteRetry->run(
            fn (): mixed => $this->sqliteWriteGate->run(static fn (): mixed => Db::transaction($operation)),
        );
    }

    /**
     * 给刮削任务返回不含物理路径、来源记录 ID 和内容摘要的发布状态。
     *
     * @param list<string> $targetIds 已经过任务所有权和全部库实时授权过滤的目标 ID。
     * @return list<array<string,mixed>>
     */
    public function projectForTargets(array $targetIds): array
    {
        if ($targetIds === []) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('scrape_asset_publications')->whereIn('scrape_target_id', $targetIds)
            ->orderBy('scrape_target_id')->orderBy('resource_kind')->get([
                'scrape_target_id', 'resource_kind', 'storage_mode', 'status', 'error_code',
            ])->all();
        return array_map(static fn (stdClass $row): array => [
            'targetId' => (string) $row->scrape_target_id,
            'kind' => (string) $row->resource_kind,
            'storageMode' => (string) $row->storage_mode,
            'status' => (string) $row->status,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
        ], $rows);
    }

    /** 插入 queued 或许可拒绝的 skipped 行；终态跳过也保留可观察原因。 */
    private function insertPublication(
        stdClass $target,
        string $kind,
        string $sourceId,
        string $sha256,
        int $version,
        string $licensePolicy,
        bool $allowed,
        string $now,
    ): void {
        Db::table('scrape_asset_publications')->insert([
            'id' => (string) new Ulid(), 'scrape_target_id' => (string) $target->id,
            'song_id' => (string) $target->song_id, 'library_id' => (string) $target->library_id,
            'inventory_file_id' => (string) $target->inventory_file_id, 'resource_kind' => $kind,
            'source_record_id' => $sourceId, 'source_sha256' => $sha256, 'source_version' => $version,
            'license_policy' => $licensePolicy, 'storage_mode' => (string) $target->scrape_storage_mode,
            'status' => $allowed ? 'queued' : 'skipped', 'published_relative_path' => null,
            'error_code' => $allowed ? null : 'SCRAPE_ASSET_LICENSE_DENIED', 'attempt' => 0,
            'worker_id' => null, 'heartbeat_at' => null, 'created_at' => $now, 'started_at' => null,
            'finished_at' => $allowed ? null : $now, 'updated_at' => $now,
        ]);
    }

    /** 根据资源类型读取并复验业务事实，正文和字节只存在于当前 Worker 调用栈。 */
    private function sourceContent(stdClass $row): array
    {
        if ((string) $row->resource_kind !== 'artwork') {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SOURCE_STALE');
        }
        /** @var stdClass|null $source */
        $source = Db::table('media_manual_artwork_candidates')->where('id', (string) $row->source_record_id)
            ->where('song_id', (string) $row->song_id)->where('library_id', (string) $row->library_id)
            ->first(['image_bytes', 'byte_size', 'content_sha256', 'mime_type']);
        try {
            $bytes = $source instanceof stdClass ? $this->artworkBlobs->bytes($source) : '';
        } catch (\RuntimeException) {
            $bytes = '';
        }
        if (!$source instanceof stdClass || $bytes === '' || (string) $source->mime_type !== 'image/webp'
            || !hash_equals((string) $row->source_sha256, (string) $source->content_sha256)
            || (string) $row->license_policy !== 'cache_allowed'
            || !$this->licenseAllows((string) $row->license_policy, (string) $row->storage_mode)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SOURCE_STALE');
        }
        return [$bytes, 'webp'];
    }

    /**
     * 复验账号能力、库授权、库策略和当前媒体对象身份。
     *
     * 本地库继续使用根内真实路径与四元身份；WebDAV 库只允许 `managed_cache`，并在写缓存前重新
     * PROPFIND 父目录验证 ETag、大小和修改时间。远端断开或对象漂移只让本发布失败，不会把旧扫描
     * 快照当作写入授权，也不会尝试修改远端目录。
     */
    private function assertCurrentScope(stdClass $row): void
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', (string) $row->requested_by)->where('status', 'active')
            ->whereNull('deleted_at')->first(['id', 'is_super_admin']);
        if (!$user instanceof stdClass) throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUTH_REVOKED');
        $super = (int) $user->is_super_admin === 1;
        $caps = $this->capabilities->resolve((string) $user->id, $super);
        if (!in_array('edit_metadata', $caps, true) || !in_array('run_scrape', $caps, true)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUTH_REVOKED');
        }
        $managed = false;
        foreach ($this->libraries->resolve((string) $user->id, $super) as $library) {
            if ($library['id'] === (string) $row->library_id && $library['accessLevel'] === 'manage') $managed = true;
        }
        if (!$managed || (string) $row->library_status !== 'active'
            || (string) $row->song_library_id !== (string) $row->library_id
            || (string) $row->song_inventory_file_id !== (string) $row->inventory_file_id
            || (string) $row->file_status !== 'available' || (string) $row->metadata_status !== 'ready'
            || (string) $row->current_storage_mode !== (string) $row->storage_mode) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SCOPE_STALE');
        }
        if ((string) $row->source_type !== 'local') {
            $this->assertCurrentWebDavScope($row);
            return;
        }
        if ((string) $row->source_type !== 'local') {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SCOPE_STALE');
        }
        $libraryRoot = realpath((string) $row->resolved_root_path);
        if ($libraryRoot === false || $libraryRoot !== rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR)
            || is_link((string) $row->resolved_root_path) || !is_dir($libraryRoot)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_ROOT_UNAVAILABLE');
        }
        $relativeSegments = $this->relativeSegments((string) $row->audio_relative_path);
        $expectedAudioPath = $libraryRoot . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, $relativeSegments);
        if ($expectedAudioPath !== (string) $row->audio_resolved_path) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
        }
        if ((string) $row->storage_mode === 'managed_cache') {
            $cache = realpath($this->cacheRoot());
            if ($cache === false || $this->pathsOverlap($libraryRoot, $cache)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_CACHE_ROOT_INVALID');
            }
        }
        $audio = realpath((string) $row->audio_resolved_path);
        $stat = @stat((string) $row->audio_resolved_path);
        if ($audio !== (string) $row->audio_resolved_path || is_link((string) $row->audio_resolved_path)
            || !is_file((string) $row->audio_resolved_path) || !is_array($stat)
            || (int) ($stat['dev'] ?? -1) !== (int) $row->device_id
            || (int) ($stat['ino'] ?? -1) !== (int) $row->inode
            || (int) ($stat['size'] ?? -1) !== (int) $row->file_size
            || (int) ($stat['mtime'] ?? -1) !== (int) $row->modified_at) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
        }
    }

    /**
     * 复验只读网络库对象并确认固定缓存根可用。
     *
     * 相对路径必须来自根内库存且不能包含点段；连接工厂只按内部库 ID 解密凭据。任何协议、认证、TLS
     * 或身份失败都收敛为稳定错误码，异常正文、URL、用户名和远端路径不会进入任务或日志。
     */
    private function assertCurrentWebDavScope(stdClass $row): void
    {
        if ((string) $row->storage_mode !== 'managed_cache'
            || (string) $row->current_storage_mode !== 'managed_cache'
            || !is_string($row->remote_etag) || $row->remote_etag === '') {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_SCOPE_STALE');
        }
        $this->relativeSegments((string) $row->audio_relative_path);
        $relative = str_replace('\\', '/', (string) $row->audio_relative_path);
        $directory = dirname($relative);
        $directory = $directory === '.' ? '' : $directory;
        try {
            $current = null;
            foreach ($this->remoteClients->forLibrary((string) $row->library_id)->listDirectory($directory) as $candidate) {
                if (!$candidate->directory && $candidate->relativePath === $relative) {
                    $current = $candidate;
                    break;
                }
            }
            if (!$current instanceof WebDavObject
                || $current->size !== (int) $row->file_size
                || $current->modifiedAt !== (int) $row->modified_at
                || !hash_equals((string) $row->remote_etag, $current->etag)) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
            }
        } catch (ScrapeAssetPublicationFailed $failure) {
            throw $failure;
        } catch (RemoteLibraryUnavailable) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_REMOTE_UNAVAILABLE');
        }
        $cache = realpath($this->cacheRoot());
        if ($cache === false || is_link($cache) || !is_dir($cache) || !is_writable($cache)) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_CACHE_ROOT_INVALID');
        }
    }

    /** 内容寻址缓存路径只包含固定 ULID、类型、摘要和扩展名。 */
    private function cacheRelativePath(stdClass $row, string $sha256, string $extension): string
    {
        foreach ([(string) $row->library_id, (string) $row->song_id] as $id) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_IDENTITY_INVALID');
            }
        }
        return (string) $row->library_id . '/' . (string) $row->song_id . '/'
            . (string) $row->resource_kind . '/' . $sha256 . '.' . $extension;
    }

    /** 相邻资源固定使用音频同基名，绝不生成共享 `cover.*` 或接受外部文件名。 */
    private function adjacentRelativePath(stdClass $row, string $extension): string
    {
        $normalized = str_replace('\\', '/', (string) $row->audio_relative_path);
        $directory = dirname($normalized);
        $stem = pathinfo($normalized, PATHINFO_FILENAME);
        if ($stem === '') throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_PATH_INVALID');
        return ($directory === '.' ? '' : $directory . '/') . $stem . '.' . $extension;
    }

    /** @return list<string> 复验库存相对路径，避免数据库损坏把发布身份指向库根外。 */
    private function relativeSegments(string $relativePath): array
    {
        $normalized = str_replace('\\', '/', $relativePath);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, "\0")) {
            throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
        }
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_AUDIO_STALE');
            }
        }
        return $segments;
    }

    /** 比较带分隔符的规范真实路径，等于或任一包含另一方都视为重叠。 */
    private function pathsOverlap(string $left, string $right): bool
    {
        $leftPrefix = rtrim($left, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $rightPrefix = rtrim($right, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $left === $right || str_starts_with($leftPrefix, $rightPrefix)
            || str_starts_with($rightPrefix, $leftPrefix);
    }

    /** 两种私有存储都接受 cache_allowed；display_only 及未知策略始终失败关闭。 */
    private function licenseAllows(string $policy, string $mode): bool
    {
        return in_array($mode, ['managed_cache', 'adjacent'], true)
            && in_array($policy, ['local_controlled', 'cache_allowed', 'redistributable'], true);
    }

    /** 发布成功后以租约 CAS 提交相对路径，并记录不含路径和内容的审计事实。 */
    private function finishSucceeded(stdClass $row, string $relativePath): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($row, $relativePath, $now): void {
            $changed = Db::table('scrape_asset_publications')->where('id', (string) $row->id)
                ->where('status', 'running')->where('worker_id', (string) $row->worker_id)->update([
                    'status' => 'succeeded', 'published_relative_path' => $relativePath, 'error_code' => null,
                    'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new ScrapeAssetPublicationFailed('SCRAPE_ASSET_LEASE_LOST');
            $this->wakeTarget((string) $row->scrape_target_id, $now);
            $this->audit->record((string) $row->requested_by, 'metadata.sync_scrape.asset.publish',
                'song', (string) $row->song_id, 'success', (string) $row->request_id, [
                    'jobId' => (string) $row->job_id, 'libraryId' => (string) $row->library_id,
                    'resourceKind' => (string) $row->resource_kind, 'storageMode' => (string) $row->storage_mode,
                ]);
        });
    }

    /** 失败只保存稳定错误码；现场目标保留给管理员检查，不能执行猜测性清理。 */
    private function finishFailed(stdClass $row, string $errorCode, bool $conflict): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('scrape_asset_publications')->where('id', (string) $row->id)
            ->where('status', 'running')->where('worker_id', (string) $row->worker_id)->update([
                'status' => $conflict ? 'conflict' : 'failed', 'published_relative_path' => null,
                'error_code' => $errorCode, 'worker_id' => null, 'heartbeat_at' => null,
                'finished_at' => $now, 'updated_at' => $now,
            ]);
        if ($changed === 1) $this->wakeTarget((string) $row->scrape_target_id, $now);
    }

    /**
     * 派生发布进入终态后提前当前歌曲的资源汇总时间。
     *
     * 更新只命中仍为 pending/completing_resources 的父 target，不复活运行中、人工确认或已终结任务；
     * SQLite 是唯一事实，进程内 drain 会立即重领，进程崩溃时周期轮询仍可恢复。旧 schema 缺少统一
     * 流程列时无副作用返回，保证滚动升级和缩减契约测试兼容。
     */
    private function wakeTarget(string $targetId, string $now): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasColumn('metadata_sync_scrape_targets', 'phase')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'next_attempt_at')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'updated_at')) return;
        Db::table('metadata_sync_scrape_targets')->where('id', $targetId)->where('status', 'pending')
            ->where('phase', 'completing_resources')->update(['next_attempt_at' => $now, 'updated_at' => $now]);
    }

    /** 返回并校验部署固定的缓存根；HTTP 和数据库均不能覆盖该路径。 */
    private function cacheRoot(): string
    {
        return StorageLayout::SCRAPE_CACHE_ROOT;
    }
}
