<?php

declare(strict_types=1);

namespace app\application\Scan;

use Closure;
use app\application\Library\LibraryPathInspector;
use app\application\Library\LibraryPathInvalid;
use app\application\Library\RemoteLibraryClientFactory;
use app\application\Library\RemoteLibraryUnavailable;
use app\application\Media\MediaMetadataIndexer;
use app\application\Media\WebDavMetadataIndexer;
use app\application\Metadata\AutomaticSongScrapeScheduler;
use app\application\Notification\NotificationPublisher;
use app\application\Playlist\M3uSyncService;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Database\SqliteTransientRetry;
use app\infrastructure\Database\SqliteWriteGate;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * Claims and executes persistent scan jobs inside the dedicated Workerman process.
 *
 * Filesystem work never runs in an HTTP worker. Discovery streams observations into batches of 200
 * and each batch uses a short SQLite transaction. Only after a complete, cancellation-free pass and
 * a second root-identity check may reconciliation mark unseen paths missing. A root outage, partial
 * unreadability, cancellation, process reload, or database error therefore cannot mass-delete or
 * mass-hide the previous trustworthy inventory (LIB-004, NFR-REL-002, NFR-REL-005).
 */
final class ScanWorkerService
{
    private const BATCH_SIZE = 200;
    private const LEASE_SECONDS = 60;

    public function __construct(
        private readonly LibraryFileDiscovery $discovery = new LibraryFileDiscovery(),
        private readonly LibraryPathInspector $paths = new LibraryPathInspector(),
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly MediaMetadataIndexer $metadata = new MediaMetadataIndexer(),
        private readonly M3uSyncService $m3uSync = new M3uSyncService(),
        private readonly NotificationPublisher $notifications = new NotificationPublisher(),
        private readonly ScanReconciliationGuard $reconciliationGuard = new ScanReconciliationGuard(),
        private readonly WebDavLibraryDiscovery $webDavDiscovery = new WebDavLibraryDiscovery(),
        private readonly RemoteLibraryClientFactory $remoteClients = new RemoteLibraryClientFactory(),
        private readonly WebDavMetadataIndexer $webDavMetadata = new WebDavMetadataIndexer(),
        private readonly LibraryMediaStatistics $mediaStatistics = new LibraryMediaStatistics(),
        private readonly AlbumOrphanCleanupService $albumOrphanCleanup = new AlbumOrphanCleanupService(),
        private readonly AutomaticSongScrapeScheduler $automaticScrapes = new AutomaticSongScrapeScheduler(),
        private readonly SqliteTransientRetry $sqliteRetry = new SqliteTransientRetry(),
        private readonly SqliteWriteGate $sqliteWriteGate = new SqliteWriteGate(),
    ) {
    }

    /**
     * Reclaims stale leases left by a crashed Worker.
     *
     * Jobs below three attempts return to the queue; exhausted jobs fail explicitly. A stale
     * cancellation request becomes cancelled rather than being executed again. Conditional updates
     * ensure a live Worker that refreshed its heartbeat after selection retains ownership.
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('library_scan_jobs')
            ->whereIn('status', ['running', 'cancel_requested'])
            ->where(static function ($query) use ($threshold): void {
                $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $threshold);
            })
            ->get(['id', 'library_id', 'requested_by', 'request_id', 'scan_type', 'status', 'attempt', 'heartbeat_at'])
            ->all();

        foreach ($rows as $row) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->writeTransaction(function () use ($now, $row): void {
                $query = Db::table('library_scan_jobs')
                    ->where('id', (string) $row->id)
                    ->where('status', (string) $row->status);
                if ($row->heartbeat_at === null) {
                    $query->whereNull('heartbeat_at');
                } else {
                    $query->where('heartbeat_at', (string) $row->heartbeat_at);
                }

                if ((string) $row->status === 'cancel_requested') {
                    if ($query->update([
                        'status' => 'cancelled',
                        'phase' => 'cancelled',
                        'worker_id' => null,
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]) === 1) {
                        $this->restoreLibraryAfterNonSuccess((string) $row->library_id, $now);
                    }
                    return;
                }

                if ((int) $row->attempt >= 3) {
                    if ($query->update([
                        'status' => 'failed',
                        'phase' => 'failed',
                        'worker_id' => null,
                        'finished_at' => $now,
                        'error_code' => 'SCAN_LEASE_EXHAUSTED',
                        'error_message' => '扫描执行进程多次中断，请检查服务日志后重新提交。',
                        'updated_at' => $now,
                    ]) === 1) {
                        Db::table('music_libraries')->where('id', (string) $row->library_id)->update([
                            'scan_status' => 'error',
                            'version' => Db::raw('version + 1'),
                            'updated_at' => $now,
                        ]);
                        $this->notifications->publishScanTerminal([
                            'id' => (string) $row->id,
                            'libraryId' => (string) $row->library_id,
                            'requestedBy' => $row->requested_by === null ? null : (string) $row->requested_by,
                            'requestId' => (string) $row->request_id,
                            'scanType' => (string) $row->scan_type,
                        ], 'failed', [], 'SCAN_LEASE_EXHAUSTED', $now);
                    }
                    return;
                }

                if ($query->update([
                    'status' => 'queued',
                    'phase' => 'queued',
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'started_at' => null,
                    'updated_at' => $now,
                ]) === 1) {
                    Db::table('music_libraries')->where('id', (string) $row->library_id)->update([
                        'scan_status' => 'queued',
                        'version' => Db::raw('version + 1'),
                        'updated_at' => $now,
                    ]);
                }
            });
        }
    }

    /**
     * 以条件更新领取最早的排队任务，并冻结本次扫描使用的媒体根与远端元数据模式。
     *
     * 调用方必须提供当前 Worker 的稳定标识；查询和状态更新位于同一短事务，多个 Worker 竞争时只有一个
     * 能把任务从 queued 改为 running。返回的 `remoteMetadataMode` 是任务开始时的库配置快照，扫描期间
     * 即使管理员修改库配置也不会让同一任务混用两种正文读取策略。队列为空或竞争失败时返回 null，
     * 不创建补偿任务，也不读取任何本地或远端媒体。
     *
     * @return array<string, mixed>|null 已冻结的扫描输入；无可领取任务时返回 null
     */
    public function claimNext(string $workerId): ?array
    {
        return $this->writeTransaction(function () use ($workerId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('library_scan_jobs as jobs')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id')
                ->where('jobs.status', 'queued')
                ->where('libraries.status', 'active')
                ->orderBy('jobs.created_at')
                ->first([
                    'jobs.id', 'jobs.library_id', 'jobs.requested_by', 'jobs.request_id',
                    'jobs.scan_type', 'libraries.root_path', 'libraries.resolved_root_path',
                    'libraries.symlink_policy', 'libraries.source_type', 'libraries.remote_metadata_mode',
                ]);
            if (!$row instanceof stdClass) {
                return null;
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $claimed = Db::table('library_scan_jobs')
                ->where('id', (string) $row->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'running',
                    'phase' => 'discovering',
                    'attempt' => Db::raw('attempt + 1'),
                    'worker_id' => $workerId,
                    'heartbeat_at' => $now,
                    'started_at' => $now,
                    'error_code' => null,
                    'error_message' => null,
                    'updated_at' => $now,
                ]);
            if ($claimed !== 1) {
                return null;
            }
            Db::table('music_libraries')->where('id', (string) $row->library_id)->update([
                'scan_status' => 'scanning',
                'version' => Db::raw('version + 1'),
                'updated_at' => $now,
            ]);

            return [
                'id' => (string) $row->id,
                'libraryId' => (string) $row->library_id,
                'requestedBy' => $row->requested_by === null ? null : (string) $row->requested_by,
                'requestId' => (string) $row->request_id,
                'scanType' => (string) $row->scan_type,
                'rootPath' => (string) $row->root_path,
                'resolvedRootPath' => (string) $row->resolved_root_path,
                'symlinkPolicy' => (string) $row->symlink_policy,
                'sourceType' => (string) $row->source_type,
                'remoteMetadataMode' => (string) ($row->remote_metadata_mode ?? 'filename_only'),
            ];
        });
    }

    /**
     * 执行一个已取得租约的扫描任务，并提交可验证的终态。
     *
     * SQLite 的 BUSY/LOCKED 只表示其他短写事务暂时占用写锁，不应把一次合法扫描永久判为失败。
     * 本方法因此在同一租约内对完整扫描尝试最多退避重放三次；每次尝试先清理本任务的临时报告，
     * 库存观察依赖现有 upsert 收敛，且只有完整遍历成功后才允许缺失校准。约束、SQL 和文件系统错误
     * 不进入重试；瞬时错误耗尽后保存 SCAN_DATABASE_BUSY_RETRY_EXHAUSTED。进程停止、用户取消和
     * 租约丢失沿用原状态机处理，不会因数据库重试改变为成功或批量隐藏旧索引。
     *
     * @param array<string, mixed> $job Result returned by claimNext.
     * @param callable(): bool $isStopping Returns true when Workerman is reloading/stopping. A stop
     *        releases the lease back to queued, unlike a user cancellation which becomes cancelled.
     */
    public function execute(array $job, callable $isStopping): void
    {
        try {
            $this->sqliteRetry->run(
                function () use ($isStopping, $job): void {
                    $this->executeAttempt($job, $isStopping);
                },
                static function (int $retry, int $delayMs) use ($job): void {
                    Log::warning('Library scan SQLite write conflict will be retried.', [
                        'job_id' => (string) $job['id'],
                        'reason_code' => 'SQLITE_TRANSIENT_WRITE_CONFLICT',
                        'retry_number' => $retry,
                        'delay_ms' => $delayMs,
                    ]);
                },
            );
        } catch (QueryException $throwable) {
            $this->finishFailed($job, $throwable);
        }
    }

    /**
     * 执行一次完整扫描尝试；只把结构化判定所需的 QueryException 交给外层重试边界。
     *
     * 单次尝试可能完成若干短事务，但缺失校准与成功终态只在完整遍历后提交；因此外层从头重放时，
     * 同一 jobId 的临时报告会重建，库存和媒体 upsert 保持幂等。其他异常在本层转换为既有任务终态。
     *
     * @param array<string, mixed> $job Result returned by claimNext.
     * @param callable(): bool $isStopping
     */
    private function executeAttempt(array $job, callable $isStopping): void
    {
        $jobId = (string) $job['id'];
        $libraryId = (string) $job['libraryId'];
        $lastHeartbeat = 0;
        $batch = [];
        $sourceBatch = [];
        $discoveredFiles = 0;
        $metadataStats = ['parsedFiles' => 0, 'failedFiles' => 0, 'updatedFiles' => 0];

        try {
            // A reclaimed attempt owns a fresh report; stale partial rows must not masquerade as
            // files processed by the new attempt if its traversal ends earlier or is cancelled.
            Db::table('library_scan_file_results')->where('scan_job_id', $jobId)->delete();
            $sourceType = (string) ($job['sourceType'] ?? 'local');
            $remote = null;
            if ($sourceType !== 'local') {
                $remote = $this->remoteClients->forLibrary($libraryId);
                $remote->assertConnection();
                $resolvedRoot = (string) $job['resolvedRootPath'];
            } else {
                $resolvedRoot = $this->paths->resolve((string) $job['rootPath']);
                if ($resolvedRoot !== (string) $job['resolvedRootPath']) {
                    throw new LibraryPathInvalid('音乐库根目录身份已变化。');
                }
            }

            $checkpoint = function (int $processedEntries) use (
                $isStopping,
                $jobId,
                &$lastHeartbeat,
                &$discoveredFiles,
            ): void {
                if ($isStopping()) {
                    throw new ScanExecutionInterrupted('Worker is stopping.');
                }
                $status = Db::table('library_scan_jobs')->where('id', $jobId)->value('status');
                if ($status === 'cancel_requested') {
                    throw new ScanExecutionCancelled('Cancellation requested.');
                }
                if ($status !== 'running') {
                    throw new ScanExecutionInterrupted('Worker lease no longer belongs to this execution.');
                }
                if (time() - $lastHeartbeat >= 2) {
                    $now = gmdate('Y-m-d\TH:i:s\Z');
                    Db::table('library_scan_jobs')->where('id', $jobId)->where('status', 'running')->update([
                        'processed_entries' => $processedEntries,
                        'discovered_files' => $discoveredFiles,
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $lastHeartbeat = time();
                }
            };

            $generator = $sourceType !== 'local'
                ? $this->webDavDiscovery->files($libraryId, $remote, $checkpoint)
                : $this->discovery->files($resolvedRoot, (string) $job['symlinkPolicy'], $checkpoint);
            foreach ($generator as $file) {
                if ($file instanceof DiscoveredM3uSource) {
                    $sourceBatch[] = $file;
                    if (count($sourceBatch) >= self::BATCH_SIZE) {
                        $this->persistM3uSourceBatch($jobId, $libraryId, $sourceBatch);
                        $sourceBatch = [];
                    }
                    continue;
                }
                $batch[] = $file;
                ++$discoveredFiles;
                if (count($batch) >= self::BATCH_SIZE) {
                    $this->persistBatch($jobId, $libraryId, $batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->persistBatch($jobId, $libraryId, $batch);
            }
            if ($sourceBatch !== []) {
                $this->persistM3uSourceBatch($jobId, $libraryId, $sourceBatch);
            }
            $stats = $generator->getReturn();
            $checkpoint((int) $stats['processedEntries']);

            /**
             * realpath 相同只能证明路径字符串未变化，不能证明多个 Worker 看到的是同一个挂载内容。
             * 在任何元数据或缺失校准前读取实时可用库存并拦截“已有媒体却发现零文件”的结果，避免
             * 宿主机与容器同时连接同一 SQLite 时，空的宿主机 `/media` 隐藏容器内全部歌曲。
             */
            $availableInventoryFiles = Db::table('library_file_inventory')
                ->where('library_id', $libraryId)
                ->where('status', 'available')
                ->count();
            $this->reconciliationGuard->assertSafe($discoveredFiles, $availableInventoryFiles);

            /**
             * FFprobe runs only after discovery has committed path/stat observations and before
             * missing-file reconciliation. Its callback reuses the job lease checks, keeping
             * cancellation responsive without holding a SQLite transaction around child processes.
             */
            $metadataCheckpoint = function (int $parsed, int $failed, int $updated) use ($checkpoint, $stats, $jobId): void {
                    $checkpoint((int) $stats['processedEntries']);
                    $now = gmdate('Y-m-d\TH:i:s\Z');
                    Db::table('library_scan_jobs')->where('id', $jobId)->where('status', 'running')->update([
                        'metadata_parsed_files' => $parsed,
                        'metadata_failed_files' => $failed,
                        'updated_files' => $updated,
                        'heartbeat_at' => $now,
                        'updated_at' => $now,
                    ]);
                };
            $metadataStats = $sourceType !== 'local'
                ? $this->webDavMetadata->index(
                    $jobId,
                    $libraryId,
                    $remote,
                    (string) ($job['remoteMetadataMode'] ?? 'filename_only'),
                    $metadataCheckpoint,
                )
                : $this->metadata->index($jobId, $libraryId, $resolvedRoot, $metadataCheckpoint);

            // Mount or symlink replacement after traversal invalidates the entire reconciliation.
            if ($sourceType !== 'local') {
                $remote->assertConnection();
            } elseif ($this->paths->resolve((string) $job['rootPath']) !== (string) $job['resolvedRootPath']) {
                throw new LibraryPathInvalid('音乐库根目录身份已变化。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $phaseChanged = Db::table('library_scan_jobs')->where('id', $jobId)->where('status', 'running')->update([
                'phase' => 'reconciling',
                'processed_entries' => (int) $stats['processedEntries'],
                'discovered_files' => $discoveredFiles,
                'ignored_entries' => (int) $stats['ignoredEntries'],
                'failed_entries' => (int) $stats['failedEntries'],
                'metadata_parsed_files' => $metadataStats['parsedFiles'],
                'metadata_failed_files' => $metadataStats['failedFiles'],
                'updated_files' => $metadataStats['updatedFiles'],
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);
            if ($phaseChanged !== 1) {
                $this->throwForLostLease($jobId);
            }

            $this->writeTransaction(function () use (
                $discoveredFiles,
                $job,
                $jobId,
                $libraryId,
                $metadataStats,
                $now,
                $stats,
            ): void {
                $status = Db::table('library_scan_jobs')->where('id', $jobId)->value('status');
                if ($status !== 'running') {
                    $this->throwForLostLease($jobId);
                }
                $missingFiles = 0;
                if ((int) $stats['failedEntries'] === 0) {
                    $missingFiles = Db::table('library_file_inventory')
                        ->where('library_id', $libraryId)
                        ->where('status', 'available')
                        ->where('last_seen_scan_job_id', '!=', $jobId)
                        ->update([
                            'status' => 'missing',
                            'missing_since' => $now,
                            'updated_at' => $now,
                        ]);
                    Db::table('library_m3u_sources')->where('library_id', $libraryId)
                        ->where('status', 'available')->where('last_seen_scan_job_id', '!=', $jobId)
                        ->update(['status' => 'missing', 'missing_since' => $now, 'updated_at' => $now]);
                    // Missing source state belongs to the same trustworthy full-pass reconciliation.
                    // Rules are retained for recovery, but a stale file can no longer be read or synced.
                    Db::table('playlist_m3u_sync_rules')
                        ->whereIn('source_id', function ($query) use ($libraryId): void {
                            $query->select('id')->from('library_m3u_sources')
                                ->where('library_id', $libraryId)->where('status', 'missing');
                        })->where('status', '!=', 'missing')->update([
                            'status' => 'missing', 'error_code' => 'SOURCE_MISSING',
                            'version' => Db::raw('version + 1'), 'updated_at' => $now,
                        ]);
                }
                $addedFiles = Db::table('library_file_inventory')
                    ->where('library_id', $libraryId)
                    ->where('first_seen_scan_job_id', $jobId)
                    ->count();
                // Reconciliation can hide songs; refresh affected album totals inside the same commit.
                Db::statement(<<<'SQL'
UPDATE media_albums
SET song_count = (
        SELECT COUNT(*) FROM media_songs songs
        JOIN library_file_inventory files ON files.id = songs.inventory_file_id
        WHERE songs.album_id = media_albums.id
          AND files.status = 'available' AND files.metadata_status = 'ready'
    ),
    duration_ms = COALESCE((
        SELECT SUM(songs.duration_ms) FROM media_songs songs
        JOIN library_file_inventory files ON files.id = songs.inventory_file_id
        WHERE songs.album_id = media_albums.id
          AND files.status = 'available' AND files.metadata_status = 'ready'
    ), 0),
    updated_at = ?
WHERE library_id = ?
SQL, [$now, $libraryId]);
                // 只有可信完整扫描才允许回收空专辑；服务内部再次检查歌曲和业务引用，缺失歌曲不会被误删。
                $deletedOrphanAlbums = $this->albumOrphanCleanup->cleanup($libraryId);
                // 三个展示计数必须来自同一个可见媒体快照，不能只更新歌曲数而留下创建时的专辑/艺人零值。
                $mediaCounts = $this->mediaStatistics->snapshot($libraryId);
                $completed = Db::table('library_scan_jobs')->where('id', $jobId)->where('status', 'running')->update([
                    'status' => 'succeeded',
                    'phase' => 'completed',
                    'processed_entries' => (int) $stats['processedEntries'],
                    'discovered_files' => $discoveredFiles,
                    'added_files' => $addedFiles,
                    'missing_files' => $missingFiles,
                    'ignored_entries' => (int) $stats['ignoredEntries'],
                    'failed_entries' => (int) $stats['failedEntries'],
                    'metadata_parsed_files' => $metadataStats['parsedFiles'],
                    'metadata_failed_files' => $metadataStats['failedFiles'],
                    'updated_files' => $metadataStats['updatedFiles'],
                    'worker_id' => null,
                    'heartbeat_at' => $now,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($completed !== 1) {
                    $this->throwForLostLease($jobId);
                }
                Db::table('music_libraries')->where('id', $libraryId)->update([
                    'scan_status' => 'ready',
                    'song_count' => $mediaCounts['songs'],
                    'album_count' => $mediaCounts['albums'],
                    'artist_count' => $mediaCounts['artists'],
                    'last_scanned_at' => $now,
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
                $this->auditLogger->record(
                    $job['requestedBy'] === null ? null : (string) $job['requestedBy'],
                    'library.scan.complete',
                    'library_scan_job',
                    $jobId,
                    'success',
                    (string) $job['requestId'],
                    [
                        'libraryId' => $libraryId,
                        'discoveredFiles' => $discoveredFiles,
                        'failedEntries' => (int) $stats['failedEntries'],
                        'metadataParsedFiles' => $metadataStats['parsedFiles'],
                        'metadataFailedFiles' => $metadataStats['failedFiles'],
                        'deletedOrphanAlbums' => $deletedOrphanAlbums,
                    ],
                );
                $this->notifications->publishScanTerminal($job, 'succeeded', [
                    'discoveredFiles' => $discoveredFiles,
                    'addedFiles' => $addedFiles,
                    'failedEntries' => (int) $stats['failedEntries'],
                ], null, $now);
            });
            try {
                // Sync is a post-scan personal workflow. Per-rule failures are isolated by the service;
                // an infrastructure failure here is logged but cannot rewrite the already truthful scan.
                $this->m3uSync->synchronizeLibrary($libraryId, (string) $job['requestId']);
            } catch (Throwable $syncFailure) {
                Log::warning('M3U post-scan synchronization was deferred.', [
                    'request_id' => (string) $job['requestId'],
                    'library_id' => $libraryId,
                    'exception_class' => $syncFailure::class,
                ]);
            }
            try {
                // 系统自动扫描只把本次真正索引成功的歌曲交给统一逐曲刮削。调度失败不能篡改已经提交的
                // 扫描终态；确定 requestId 让进程重启后的补偿调用保持幂等。
                $this->automaticScrapes->dispatch($job);
            } catch (Throwable $scrapeFailure) {
                Log::warning('Automatic song scrape dispatch was deferred.', [
                    'request_id' => (string) $job['requestId'],
                    'library_id' => $libraryId,
                    'exception_class' => $scrapeFailure::class,
                ]);
            }
        } catch (ScanExecutionCancelled) {
            $this->finishCancelled($job);
        } catch (ScanExecutionInterrupted) {
            $this->releaseForRetry($job);
        } catch (QueryException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            $this->finishFailed($job, $throwable);
        }
    }

    /**
     * Upserts a discovery batch using SQLite's native conflict target in one short transaction.
     *
     * The generated ULID is used only for new paths; an existing row retains its stable inventory ID.
     * A later MySQL repository will use INSERT ... ON DUPLICATE KEY UPDATE behind this boundary.
     *
     * @param list<DiscoveredAudioFile> $files
     */
    private function persistBatch(string $jobId, string $libraryId, array $files): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($files, $jobId, $libraryId, $now): void {
            $pdo = Db::connection()->getPdo();
            $statement = $pdo->prepare(<<<'SQL'
INSERT INTO library_file_inventory (
    id, library_id, relative_path, resolved_path, extension, device_id, inode, file_size,
    modified_at, remote_etag, status, first_seen_scan_job_id, last_seen_scan_job_id, missing_since,
    created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, NULL, ?, ?)
ON CONFLICT(library_id, relative_path) DO UPDATE SET
    byte_hash_status = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN 'pending'
        ELSE library_file_inventory.byte_hash_status END,
    byte_sha256 = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN NULL
        ELSE library_file_inventory.byte_sha256 END,
    acoustic_fingerprint_status = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN 'pending'
        ELSE library_file_inventory.acoustic_fingerprint_status END,
    acoustic_fingerprint_sha256 = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN NULL
        ELSE library_file_inventory.acoustic_fingerprint_sha256 END,
    acoustic_duration_seconds = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN NULL
        ELSE library_file_inventory.acoustic_duration_seconds END,
    duplicate_evidence_signature = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN NULL
        ELSE library_file_inventory.duplicate_evidence_signature END,
    duplicate_evidence_error_code = CASE
        WHEN library_file_inventory.device_id != excluded.device_id
          OR library_file_inventory.inode != excluded.inode
          OR library_file_inventory.file_size != excluded.file_size
          OR library_file_inventory.modified_at != excluded.modified_at
          OR COALESCE(library_file_inventory.remote_etag, '') != COALESCE(excluded.remote_etag, '') THEN NULL
        ELSE library_file_inventory.duplicate_evidence_error_code END,
    resolved_path = excluded.resolved_path,
    extension = excluded.extension,
    device_id = excluded.device_id,
    inode = excluded.inode,
    file_size = excluded.file_size,
    modified_at = excluded.modified_at,
    remote_etag = excluded.remote_etag,
    status = 'available',
    last_seen_scan_job_id = excluded.last_seen_scan_job_id,
    missing_since = NULL,
    updated_at = excluded.updated_at
SQL);
            foreach ($files as $file) {
                $statement->execute([
                    (string) new Ulid(),
                    $libraryId,
                    $file->relativePath,
                    $file->resolvedPath,
                    $file->extension,
                    $file->deviceId,
                    $file->inode,
                    $file->fileSize,
                    $file->modifiedAt,
                    $file->remoteEtag,
                    $jobId,
                    $jobId,
                    $now,
                    $now,
                ]);
            }
        });
    }

    /**
     * Upserts path-hidden M3U source identities from the same traversal used for audio discovery.
     *
     * The relative/resolved paths remain server-only and allow the later reader to defend against
     * mount, symlink, and file replacement. Existing rows retain opaque IDs; reappearance clears the
     * missing state. No source bytes are read and no playlist is changed in this short transaction.
     * Future MySQL uses INSERT ... ON DUPLICATE KEY UPDATE behind this boundary.
     *
     * @param list<DiscoveredM3uSource> $sources
     */
    private function persistM3uSourceBatch(string $jobId, string $libraryId, array $sources): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($jobId, $libraryId, $now, $sources): void {
            $statement = Db::connection()->getPdo()->prepare(<<<'SQL'
INSERT INTO library_m3u_sources (
    id, library_id, relative_path, resolved_path, display_name, extension, device_id, inode,
    file_size, modified_at, status, first_seen_scan_job_id, last_seen_scan_job_id,
    missing_since, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, NULL, ?, ?)
ON CONFLICT(library_id, relative_path) DO UPDATE SET
    resolved_path = excluded.resolved_path,
    display_name = excluded.display_name,
    extension = excluded.extension,
    device_id = excluded.device_id,
    inode = excluded.inode,
    file_size = excluded.file_size,
    modified_at = excluded.modified_at,
    status = 'available',
    last_seen_scan_job_id = excluded.last_seen_scan_job_id,
    missing_since = NULL,
    updated_at = excluded.updated_at
SQL);
            foreach ($sources as $source) {
                $statement->execute([
                    (string) new Ulid(), $libraryId, $source->relativePath, $source->resolvedPath,
                    $source->displayName, $source->extension, $source->deviceId, $source->inode,
                    $source->fileSize, $source->modifiedAt, $jobId, $jobId, $now, $now,
                ]);
            }
        });
    }

    /** Commits a cooperative cancellation without reconciling unseen inventory paths. */
    private function finishCancelled(array $job): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($job, $now): void {
            $changed = Db::table('library_scan_jobs')->where('id', (string) $job['id'])
                ->whereIn('status', ['running', 'cancel_requested'])
                ->update([
                    'status' => 'cancelled',
                    'phase' => 'cancelled',
                    'worker_id' => null,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($changed === 1) {
                $this->restoreLibraryAfterNonSuccess((string) $job['libraryId'], $now);
            }
        });
    }

    /** Releases a job during process reload so a later Worker can resume from a fresh full pass. */
    private function releaseForRetry(array $job): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($job, $now): void {
            $status = Db::table('library_scan_jobs')->where('id', (string) $job['id'])->value('status');
            if ($status === 'cancel_requested') {
                $changed = Db::table('library_scan_jobs')->where('id', (string) $job['id'])
                    ->where('status', 'cancel_requested')
                    ->update([
                        'status' => 'cancelled',
                        'phase' => 'cancelled',
                        'worker_id' => null,
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($changed === 1) {
                    $this->restoreLibraryAfterNonSuccess((string) $job['libraryId'], $now);
                }
                return;
            }
            $changed = Db::table('library_scan_jobs')->where('id', (string) $job['id'])->where('status', 'running')->update([
                'status' => 'queued',
                'phase' => 'queued',
                'worker_id' => null,
                'heartbeat_at' => null,
                'started_at' => null,
                'updated_at' => $now,
            ]);
            if ($changed === 1) {
                Db::table('music_libraries')->where('id', (string) $job['libraryId'])->update([
                    'scan_status' => 'queued',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /** Throws the correct cooperative outcome after a conditional lease update loses its race. */
    private function throwForLostLease(string $jobId): never
    {
        $status = Db::table('library_scan_jobs')->where('id', $jobId)->value('status');
        if ($status === 'cancel_requested') {
            throw new ScanExecutionCancelled('Cancellation won the terminal-state race.');
        }

        throw new ScanExecutionInterrupted('Worker lease changed before terminal commit.');
    }

    /** Converts internal failures into a stable administrator-visible code without exposing paths. */
    private function finishFailed(array $job, Throwable $throwable): void
    {
        $rootUnavailable = $throwable instanceof LibraryPathInvalid;
        $webDavUnavailable = $throwable instanceof RemoteLibraryUnavailable;
        $emptyDiscovery = $throwable instanceof SuspiciousEmptyScan;
        $databaseBusy = $throwable instanceof QueryException && $this->sqliteRetry->isRetryable($throwable);
        $code = $webDavUnavailable ? $throwable->errorCode : ($rootUnavailable
            ? 'LIBRARY_ROOT_UNAVAILABLE'
            : ($emptyDiscovery ? 'SCAN_EMPTY_DISCOVERY_REJECTED'
                : ($databaseBusy ? 'SCAN_DATABASE_BUSY_RETRY_EXHAUSTED' : 'SCAN_EXECUTION_FAILED')));
        $message = match (true) {
            $webDavUnavailable => $throwable->getMessage(),
            $rootUnavailable => '音乐库根目录不可用或身份已变化，未执行缺失文件校准。',
            $emptyDiscovery => '扫描未发现任何音频，但音乐库仍有可用库存；请检查挂载与重复 Worker。',
            $databaseBusy => '数据库写入繁忙且自动重试已耗尽，旧索引未被批量清除。',
            default => '扫描执行失败，旧索引未被批量清除。',
        };
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($code, $job, $message, $now): void {
            Db::table('library_scan_jobs')->where('id', (string) $job['id'])
                ->whereIn('status', ['running', 'cancel_requested'])
                ->update([
                    'status' => 'failed',
                    'phase' => 'failed',
                    'worker_id' => null,
                    'finished_at' => $now,
                    'error_code' => $code,
                    'error_message' => $message,
                    'updated_at' => $now,
                ]);
            Db::table('music_libraries')->where('id', (string) $job['libraryId'])->update([
                'scan_status' => 'error',
                'version' => Db::raw('version + 1'),
                'updated_at' => $now,
            ]);
            $this->auditLogger->record(
                $job['requestedBy'] === null ? null : (string) $job['requestedBy'],
                'library.scan.complete',
                'library_scan_job',
                (string) $job['id'],
                'failure',
                (string) $job['requestId'],
                ['libraryId' => (string) $job['libraryId'], 'errorCode' => $code],
            );
            $this->notifications->publishScanTerminal($job, 'failed', [
                'failedEntries' => 1,
            ], $code, $now);
        });
        Log::error('Library scan Worker failed.', [
            'job_id' => (string) $job['id'],
            'exception_class' => $throwable::class,
            'error_code' => $code,
        ]);
    }

    /** Restores pre-existing scan readiness after cancellation without changing media counts. */
    private function restoreLibraryAfterNonSuccess(string $libraryId, string $now): void
    {
        $this->writeTransaction(function () use ($libraryId, $now): void {
            /** @var stdClass|null $library */
            $library = Db::table('music_libraries')->where('id', $libraryId)->first(['last_scanned_at']);
            Db::table('music_libraries')->where('id', $libraryId)->update([
                'scan_status' => $library?->last_scanned_at === null ? 'never_scanned' : 'ready',
                'version' => Db::raw('version + 1'),
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * 在 SQLite 写入闸门内执行一个短事务。
     *
     * 扫描 Worker 仍在闸门外完成文件发现、标签读取和远端请求，只有实际数据库事务排队；这样不会把
     * 媒体库遍历时间变成全局锁持有时间。闸门异常和数据库异常原样交给现有扫描重试/失败状态机。
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function writeTransaction(Closure $operation): mixed
    {
        return $this->sqliteWriteGate->run(static fn (): mixed => Db::transaction($operation));
    }
}
