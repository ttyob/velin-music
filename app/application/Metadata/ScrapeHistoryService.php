<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Auth\AuthorizationDenied;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use JsonException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 提供逐曲刮削记录的脱敏查询和受控批量重新入队。
 *
 * 记录以 target 为粒度，而不是以父批次为粒度：活动 target 置顶展示为“刮削中”，终态 target 保留
 * 每首歌不同的匹配、歌词、封面和资源结果。等待人工确认不属于正在执行，继续由任务中心承载。查询只
 * 覆盖当前账号仍可 manage 的音乐库，不读取渠道原始响应、歌词正文、图片字节、文件路径、内容摘要或
 * Worker 租约。歌曲已经删除、移库或库已停用时，终态记录仍可查看但明确标记为不可重新入队。
 *
 * 重新入队不会复用旧候选、旧证据或旧资源状态，而是从当前歌曲事实创建新的自动整体刮削任务。除管理员
 * 明确执行清空外，旧任务和历史 target 不会被重入队命令改写；最多选择 50 条记录，同一歌曲的多条历史
 * 会稳定去重。幂等键经专用唯一索引绑定到新父任务，网络重试只返回同一任务；同键改选其他歌曲失败
 * 关闭，避免误把不同用户意图合并。
 */
final readonly class ScrapeHistoryService
{
    private const ACTIVE_TARGET_STATUSES = ['pending', 'running'];
    private const ACTIVE_JOB_STATUSES = ['queued', 'running'];
    private const TERMINAL_STATUSES = ['succeeded', 'unmatched', 'failed'];
    private const FILTER_STATUSES = ['queued', 'running', 'succeeded', 'unmatched', 'failed'];

    public function __construct(
        private MetadataSyncScrapeService $scrapes = new MetadataSyncScrapeService(),
        private AuditLogger $audit = new AuditLogger(),
    ) {}

    /**
     * 返回当前管理员可管理音乐库内的逐曲活动记录和历史分页。
     *
     * 所有筛选值先经过固定白名单和长度校验，再进入参数化查询。分页上限 100，偏移上限 100000；资源
     * JSON 只解析固定状态计数，损坏内容降级为空摘要且不会透传。该方法只读，可安全重复调用。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array<string,mixed>
     */
    public function page(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $mode,
        ?string $queryText,
        int $limit,
        int $offset,
    ): array {
        $this->requireRunScrape($actor);
        $manageable = $this->manageableLibraryIds($actor);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(100_000, $offset));
        $queryText = trim((string) $queryText);
        if ($queryText !== '' && mb_strlen($queryText, 'UTF-8') > 100) {
            throw new MediaMetadataInvalid('刮削历史搜索词过长。');
        }
        if ($libraryId !== null && ($this->invalidUlid($libraryId) || !isset($manageable[$libraryId]))) {
            throw new MediaMetadataNotFound('音乐库不存在或不可管理。');
        }
        if ($status !== null && !in_array($status, self::FILTER_STATUSES, true)) {
            throw new MediaMetadataInvalid('刮削记录状态筛选无效。');
        }
        if ($mode !== null && !in_array($mode, ['manual', 'automatic'], true)) {
            throw new MediaMetadataInvalid('刮削历史模式筛选无效。');
        }

        if ($manageable === []) {
            return $this->emptyPage($limit, $offset);
        }
        $base = $this->visibleRecordQuery(array_keys($manageable));
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->name,
            ])->all();

        $filtered = clone $base;
        if ($libraryId !== null) $filtered->where('targets.library_id', $libraryId);
        if ($status === 'queued') {
            $filtered->where('targets.status', 'pending');
            if ($this->supportsUnifiedPipeline()) {
                $filtered->where('targets.phase', 'provider_query');
            }
        } elseif ($status === 'running') {
            $filtered->where(function ($active): void {
                $active->where('targets.status', 'running');
                if ($this->supportsUnifiedPipeline()) {
                    $active->orWhere(function ($resources): void {
                        $resources->where('targets.status', 'pending')
                            ->whereIn('targets.phase', ['applying', 'completing_resources']);
                    });
                }
            });
        } elseif ($status !== null) {
            $filtered->where('targets.status', $status);
        }
        if ($mode !== null && $this->supportsConfirmationMode()) {
            $filtered->where('jobs.confirmation_required', $mode === 'manual' ? 1 : 0);
        } elseif ($mode === 'manual') {
            return [
                ...$this->emptyPage($limit, $offset),
                'filterOptions' => ['libraries' => $libraries],
            ];
        }
        if ($queryText !== '') {
            $like = '%' . $queryText . '%';
            $filtered->where(function ($query) use ($like): void {
                $query->where('songs.title', 'like', $like)
                    ->orWhere('albums.title', 'like', $like)
                    ->orWhere('libraries.name', 'like', $like)
                    ->orWhere('owners.display_name', 'like', $like)
                    ->orWhereExists(function ($artists) use ($like): void {
                        $artists->selectRaw('1')->from('media_song_artists as history_artist_links')
                            ->join('media_artists as history_artists', 'history_artists.id', '=', 'history_artist_links.artist_id')
                            ->whereColumn('history_artist_links.song_id', 'targets.song_id')
                            ->where('history_artists.name', 'like', $like);
                    });
            });
        }

        $total = (clone $filtered)->count('targets.id');
        /** @var list<stdClass> $rows */
        $rows = $filtered
            ->orderByRaw("CASE WHEN targets.status IN ('pending','running') "
                . "AND jobs.status IN ('queued','running') THEN 0 ELSE 1 END")
            ->orderByDesc('targets.finished_at')->orderByDesc('targets.created_at')
            ->orderByDesc('targets.id')->offset($offset)->limit($limit)->get($this->columns())->all();

        return [
            'records' => array_map(fn (stdClass $row): array => $this->mapRecord($row, $actor), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filterOptions' => ['libraries' => $libraries],
        ];
    }

    /**
     * 将选中的历史记录按当前歌曲事实重新加入自动整体刮削队列。
     *
     * 选择、实时库授权、歌曲存在性、幂等任务复用、新任务创建和审计处于同一 SQLite 写事务；新任务
     * 服务仍会再次冻结当前证据并校验全部歌曲。重复历史记录指向同一歌曲时只创建一个 target。任何
     * 记录失权、非终态、歌曲删除或移库都会让整批失败，不留下部分新任务。旧历史记录始终不修改。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{job:array<string,mixed>,selectedRecordCount:int,queuedSongCount:int,reused:bool}
     */
    public function requeue(array $actor, mixed $targetIds, mixed $idempotencyKey, string $requestId): array
    {
        $this->requireRunScrape($actor);
        $normalizedTargets = $this->normalizeTargetIds($targetIds);
        if (!is_string($idempotencyKey)
            || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $idempotencyKey) !== 1) {
            throw new MediaMetadataInvalid('刮削历史幂等键无效。');
        }
        $actorId = (string) ($actor['id'] ?? '');
        $commandRequestId = 'scrape-history-requeue:' . $actorId . ':' . $idempotencyKey;

        try {
            return Db::transaction(function () use (
                $actor,
                $actorId,
                $normalizedTargets,
                $commandRequestId,
                $requestId,
            ): array {
                $songIds = $this->resolveRequeueSongs($actor, array_keys($normalizedTargets));
                /** @var stdClass|null $existing */
                $existing = Db::table('metadata_sync_scrape_jobs')->where('requested_by', $actorId)
                    ->where('request_id', $commandRequestId)->first(['id']);
                if ($existing instanceof stdClass) {
                    $this->assertSameSongs((string) $existing->id, $songIds);
                    return [
                        'job' => $this->scrapes->show($actor, (string) $existing->id),
                        'selectedRecordCount' => count($normalizedTargets),
                        'queuedSongCount' => count($songIds),
                        'reused' => true,
                    ];
                }

                $job = $this->scrapes->createAutomaticBatch($actor, $songIds, $commandRequestId);
                $this->audit->record($actorId, 'metadata.sync_scrape.history_requeue',
                    'metadata_sync_scrape_job', (string) $job['id'], 'success', $requestId, [
                        'selectedRecordCount' => count($normalizedTargets),
                        'queuedSongCount' => count($songIds),
                    ]);
                return [
                    'job' => $job,
                    'selectedRecordCount' => count($normalizedTargets),
                    'queuedSongCount' => count($songIds),
                    'reused' => false,
                ];
            });
        } catch (QueryException $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'unique')) throw $exception;
            $songIds = $this->resolveRequeueSongs($actor, array_keys($normalizedTargets));
            /** @var stdClass|null $existing */
            $existing = Db::table('metadata_sync_scrape_jobs')->where('requested_by', $actorId)
                ->where('request_id', $commandRequestId)->first(['id']);
            if (!$existing instanceof stdClass) throw $exception;
            $this->assertSameSongs((string) $existing->id, $songIds);
            return [
                'job' => $this->scrapes->show($actor, (string) $existing->id),
                'selectedRecordCount' => count($normalizedTargets),
                'queuedSongCount' => count($songIds),
                'reused' => true,
            ];
        }
    }

    /**
     * 清空当前账号管理范围内尚未被 Worker 领取的逐曲等待队列。
     *
     * 只删除 `pending + worker_id IS NULL` 且仍处于 provider_query 的目标；已经 running，或已经进入
     * applying/completing_resources 的目标可能正在产生数据库或文件副作用，必须继续按原状态机收口。
     * 选择目标、条件删除、父任务重算和审计处于同一短事务，和 Worker 的 CAS 领取互斥：先领取则保留，
     * 先清理则 Worker 无法再取得目标。已完成歌曲及其媒体事实完全不修改。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{clearedTargetCount:int,deletedJobCount:int,remainingActiveTargetCount:int}
     */
    public function clearQueue(array $actor, string $requestId): array
    {
        $this->requireRunScrape($actor);
        $manageable = $this->manageableLibraryIds($actor);
        if ($manageable === []) throw new AuthorizationDenied('没有可管理的音乐库。');

        return Db::transaction(function () use ($actor, $manageable, $requestId): array {
            $eligible = Db::table('metadata_sync_scrape_targets as targets')
                ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
                ->whereIn('targets.library_id', array_keys($manageable))
                ->where('targets.status', 'pending')->whereNull('targets.worker_id')
                ->whereIn('jobs.status', self::ACTIVE_JOB_STATUSES);
            if ($this->supportsUnifiedPipeline()) $eligible->where('targets.phase', 'provider_query');
            /** @var list<stdClass> $rows */
            $rows = $eligible->orderBy('targets.job_id')->orderBy('targets.position')
                ->get(['targets.id', 'targets.job_id'])->all();
            $targetIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
            $jobIds = array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->job_id,
                $rows,
            )));
            $cleared = $this->deleteTargetRows($targetIds, true);
            $deletedJobs = $this->rebuildOrDeleteJobs($jobIds);
            $remaining = $this->countActiveTargets(array_keys($manageable));
            if ($cleared > 0) {
                $this->audit->record((string) ($actor['id'] ?? ''), 'metadata.sync_scrape.queue_clear',
                    'metadata_sync_scrape_queue', null, 'success', $requestId, [
                        'clearedTargetCount' => $cleared,
                        'deletedJobCount' => $deletedJobs,
                        'remainingActiveTargetCount' => $remaining,
                    ]);
            }
            return [
                'clearedTargetCount' => $cleared,
                'deletedJobCount' => $deletedJobs,
                'remainingActiveTargetCount' => $remaining,
            ];
        });
    }

    /**
     * 删除当前账号管理范围内的全部逐曲终态记录。
     *
     * 等待人工确认虽复用 unmatched 状态，但仍是可继续操作的业务任务，因此不会删除。终态 target 的
     * 渠道候选和派生发布状态随记录清理；已经写入的 scraped 字段、歌词索引/文件、封面候选/选择、媒体
     * 文件和不可变审计日志不在本事务删除范围。关联图片任务保留自身历史并先解除 target 引用。删除后
     * 原父任务若没有任何 target 一并删除，否则以剩余 target 原子重算计数。操作不可由页面撤销。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{deletedRecordCount:int,deletedJobCount:int}
     */
    public function clearRecords(array $actor, string $requestId): array
    {
        $this->requireRunScrape($actor);
        $manageable = $this->manageableLibraryIds($actor);
        if ($manageable === []) throw new AuthorizationDenied('没有可管理的音乐库。');

        return Db::transaction(function () use ($actor, $manageable, $requestId): array {
            /** @var list<stdClass> $rows */
            $rows = $this->visibleTerminalQuery(array_keys($manageable))
                ->orderBy('targets.job_id')->orderBy('targets.position')
                ->get(['targets.id', 'targets.job_id'])->all();
            $targetIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
            $jobIds = array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->job_id,
                $rows,
            )));
            $deleted = $this->deleteTargetRows($targetIds, false);
            $deletedJobs = $this->rebuildOrDeleteJobs($jobIds);
            if ($deleted > 0) {
                $this->audit->record((string) ($actor['id'] ?? ''), 'metadata.sync_scrape.records_clear',
                    'metadata_sync_scrape_history', null, 'success', $requestId, [
                        'deletedRecordCount' => $deleted,
                        'deletedJobCount' => $deletedJobs,
                    ]);
            }
            return ['deletedRecordCount' => $deleted, 'deletedJobCount' => $deletedJobs];
        });
    }

    /** @return array<string,true> */
    private function normalizeTargetIds(mixed $targetIds): array
    {
        if (!is_array($targetIds) || !array_is_list($targetIds)
            || count($targetIds) < 1 || count($targetIds) > 50) {
            throw new MediaMetadataInvalid('请选择 1 到 50 条刮削历史。');
        }
        $normalized = [];
        foreach ($targetIds as $targetId) {
            if (!is_string($targetId) || $this->invalidUlid($targetId) || isset($normalized[$targetId])) {
                throw new MediaMetadataInvalid('刮削历史选择包含无效或重复标识。');
            }
            $normalized[$targetId] = true;
        }
        return $normalized;
    }

    /**
     * @param list<string> $targetIds
     * @return list<string>
     */
    private function resolveRequeueSongs(array $actor, array $targetIds): array
    {
        $manageable = $this->manageableLibraryIds($actor);
        if ($manageable === []) throw new AuthorizationDenied('没有可管理的音乐库。');
        /** @var list<stdClass> $rows */
        $rows = $this->visibleTerminalQuery(array_keys($manageable))->whereIn('targets.id', $targetIds)
            ->orderBy('targets.created_at')->orderBy('targets.position')->get([
                'targets.id', 'targets.song_id', 'targets.library_id',
                'songs.id as current_song_id', 'songs.library_id as current_library_id',
                'libraries.status as library_status',
            ])->all();
        if (count($rows) !== count($targetIds)) {
            throw new MediaMetadataNotFound('至少一条刮削历史不存在或不可管理。');
        }
        $songs = [];
        foreach ($rows as $row) {
            if ($row->song_id === null || $row->current_song_id === null
                || (string) $row->library_status !== 'active'
                || (string) $row->current_library_id !== (string) $row->library_id) {
                throw new MediaMetadataConflict('至少一首历史歌曲已经删除、移库或停用。');
            }
            $songs[(string) $row->song_id] = true;
        }
        return array_keys($songs);
    }

    /** @param list<string> $songIds */
    private function assertSameSongs(string $jobId, array $songIds): void
    {
        /** @var list<string> $existing */
        $existing = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
            ->orderBy('position')->pluck('song_id')->map('strval')->all();
        if ($existing !== array_values($songIds)) {
            throw new MediaMetadataConflict('幂等键已用于其他刮削历史选择。');
        }
    }

    /**
     * 删除精确 target 及其仅用于刮削记录的子状态。
     *
     * `$queueOnly` 先以条件 UPDATE 写入本事务独有的清理标记，再清理被成功标记的目标。该写入是本事务的
     * 第一个目标副作用：SQLite 会先取得写锁，MySQL 会锁住命中行，因此 Worker 若已完成 CAS 领取，本
     * 方法不会碰它的渠道或资源子状态；若本方法先标记，Worker 的 `worker_id IS NULL` 领取条件必然失败。
     * 图片搜索/导入任务具有独立审计价值，只解除可空关联；渠道响应和派生发布行不是独立业务事实，在
     * SQLite 外键未启用的测试或未来迁移阶段也显式删除，避免孤儿记录。任一后续写入失败会整体回滚。
     *
     * @param list<string> $targetIds
     */
    private function deleteTargetRows(array $targetIds, bool $queueOnly): int
    {
        if ($targetIds === []) return 0;
        $schema = Db::connection()->getSchemaBuilder();
        $deleteTargetIds = $targetIds;
        $queueClearMarker = null;
        if ($queueOnly) {
            $queueClearMarker = 'queue-clear-' . bin2hex(random_bytes(12));
            $reservation = Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)
                ->where('status', 'pending')->whereNull('worker_id');
            if ($this->supportsUnifiedPipeline()) $reservation->where('phase', 'provider_query');
            $reservation->update(['worker_id' => $queueClearMarker]);
            /** @var list<string> $reservedIds */
            $reservedIds = Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)
                ->where('worker_id', $queueClearMarker)->pluck('id')->map('strval')->all();
            $deleteTargetIds = $reservedIds;
            if ($deleteTargetIds === []) return 0;
        }
        foreach (['artwork_provider_search_jobs', 'artwork_provider_import_jobs'] as $table) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'scrape_target_id')) {
                Db::table($table)->whereIn('scrape_target_id', $deleteTargetIds)->update(['scrape_target_id' => null]);
            }
        }
        if ($schema->hasTable('metadata_sync_scrape_channel_results')) {
            Db::table('metadata_sync_scrape_channel_results')->whereIn('target_id', $deleteTargetIds)->delete();
        }
        if ($schema->hasTable('scrape_asset_publications')) {
            Db::table('scrape_asset_publications')->whereIn('scrape_target_id', $deleteTargetIds)->delete();
        }
        $delete = Db::table('metadata_sync_scrape_targets')->whereIn('id', $deleteTargetIds);
        if ($queueOnly) {
            $delete->where('status', 'pending')->where('worker_id', $queueClearMarker);
            if ($this->supportsUnifiedPipeline()) $delete->where('phase', 'provider_query');
        } else {
            $delete->whereIn('status', self::TERMINAL_STATUSES);
            if ($schema->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
                $delete->where('awaiting_confirmation', 0);
            }
        }
        return $delete->delete();
    }

    /**
     * 删除空父任务，或从剩余 target 重新物化父计数。
     *
     * 清队列可能只移除一个批次的后续歌曲，清记录也可能与等待确认 target 共用父任务。父任务不能保留
     * 原 target_count，否则任务中心会永久显示错误进度。方法只处理本次目标原属的明确 job ID。
     *
     * @param list<string> $jobIds
     */
    private function rebuildOrDeleteJobs(array $jobIds): int
    {
        $deletedJobs = 0;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($jobIds as $jobId) {
            /** @var list<string> $statuses */
            $statuses = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
                ->orderBy('position')->pluck('status')->map('strval')->all();
            if ($statuses === []) {
                $deletedJobs += Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->delete();
                continue;
            }
            $counts = array_fill_keys(self::TERMINAL_STATUSES, 0);
            foreach ($statuses as $status) if (isset($counts[$status])) ++$counts[$status];
            $processed = array_sum($counts);
            $targetCount = count($statuses);
            $terminal = $processed === $targetCount;
            $status = !$terminal ? 'running'
                : ($counts['succeeded'] === $targetCount ? 'succeeded'
                    : ($counts['failed'] === $targetCount ? 'failed' : 'partial'));
            Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->update([
                'status' => $status, 'target_count' => $targetCount, 'processed_count' => $processed,
                'succeeded_count' => $counts['succeeded'], 'unmatched_count' => $counts['unmatched'],
                'failed_count' => $counts['failed'], 'version' => Db::raw('version + 1'),
                'finished_at' => $terminal ? $now : null, 'updated_at' => $now,
            ]);
        }
        return $deletedJobs;
    }

    /** @param list<string> $libraryIds */
    private function countActiveTargets(array $libraryIds): int
    {
        return Db::table('metadata_sync_scrape_targets as targets')
            ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
            ->whereIn('targets.library_id', $libraryIds)
            ->whereIn('targets.status', self::ACTIVE_TARGET_STATUSES)
            ->whereIn('jobs.status', self::ACTIVE_JOB_STATUSES)->count('targets.id');
    }

    /**
     * 构造活动记录和逐曲终态的统一授权查询。
     *
     * 活动记录要求 target 与父任务同时处于活动状态，避免异常遗留的 pending target 被长期误报为刮削中；
     * 等待确认的 unmatched target 由全局条件排除。调用方只能追加参数化条件，不能移除库范围。
     */
    private function visibleRecordQuery(array $manageableLibraryIds)
    {
        $query = Db::table('metadata_sync_scrape_targets as targets')
            ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'targets.library_id')
            ->join('users as owners', 'owners.id', '=', 'jobs.requested_by')
            ->leftJoin('media_songs as songs', 'songs.id', '=', 'targets.song_id')
            ->leftJoin('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->whereIn('targets.library_id', $manageableLibraryIds)
            ->where(function ($scope): void {
                $scope->whereIn('targets.status', self::TERMINAL_STATUSES)
                    ->orWhere(function ($active): void {
                        $active->whereIn('targets.status', self::ACTIVE_TARGET_STATUSES)
                            ->whereIn('jobs.status', self::ACTIVE_JOB_STATUSES);
                    });
            });
        if (Db::connection()->getSchemaBuilder()->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
            $query->where('targets.awaiting_confirmation', 0);
        }
        return $query;
    }

    /** 构造可重新入队的逐曲终态查询；活动记录即使被伪造提交也无法通过该服务端边界。 */
    private function visibleTerminalQuery(array $manageableLibraryIds)
    {
        return $this->visibleRecordQuery($manageableLibraryIds)
            ->whereIn('targets.status', self::TERMINAL_STATUSES);
    }

    /** @return list<string> 固定列不包含候选正文、路径、内容摘要、请求 ID 或 Worker 租约。 */
    private function columns(): array
    {
        $columns = [
            'targets.id', 'targets.job_id', 'targets.song_id', 'targets.library_id', 'targets.evidence_json',
            'targets.status', 'targets.selected_source', 'targets.score', 'targets.lyrics_saved',
            'targets.artwork_status', 'targets.error_code', 'targets.created_at', 'targets.finished_at',
            'jobs.requested_by', 'owners.display_name as owner_name', 'libraries.name as library_name',
            'libraries.status as library_status', 'songs.id as current_song_id',
            'songs.library_id as current_library_id', 'songs.title as current_title',
            'albums.title as current_album_title',
        ];
        if ($this->supportsConfirmationMode()) $columns[] = 'jobs.confirmation_required';
        if ($this->supportsUnifiedPipeline()) {
            $columns[] = 'targets.phase';
            $columns[] = 'targets.resource_status_json';
        }
        return $columns;
    }

    /** @return array<string,mixed> */
    private function mapRecord(stdClass $row, array $actor): array
    {
        $evidence = $this->decodeEvidence($row->evidence_json);
        $resources = $this->resourceSummary(property_exists($row, 'resource_status_json')
            ? $row->resource_status_json : null);
        $songAvailable = $row->song_id !== null && $row->current_song_id !== null
            && (string) $row->library_status === 'active'
            && (string) $row->current_library_id === (string) $row->library_id;
        // pending 既可能是尚未领取的查询队列，也可能是进入歌词/图片子任务后释放主租约等待收口。
        // 前者必须展示 queued，后者仍属于实际处理过程，不能仅凭 pending 把两者都标成“刮削中”。
        $status = (string) $row->status;
        if ($status === 'pending') {
            $phase = property_exists($row, 'phase') ? (string) $row->phase : 'provider_query';
            $status = in_array($phase, ['applying', 'completing_resources'], true) ? 'running' : 'queued';
        }
        return [
            'id' => (string) $row->id,
            'jobId' => (string) $row->job_id,
            'song' => [
                'id' => $row->song_id === null ? null : (string) $row->song_id,
                'title' => is_string($row->current_title) && $row->current_title !== ''
                    ? (string) $row->current_title : $evidence['title'],
                'artists' => $evidence['artists'],
                'album' => is_string($row->current_album_title) && $row->current_album_title !== ''
                    ? (string) $row->current_album_title : $evidence['albumTitle'],
                'available' => $songAvailable,
            ],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'requestedBy' => ['id' => (string) $row->requested_by, 'label' => (string) $row->owner_name],
            'mode' => property_exists($row, 'confirmation_required') && (int) $row->confirmation_required === 1
                ? 'manual' : 'automatic',
            'status' => $status,
            'selectedSource' => $row->selected_source === null ? null : (string) $row->selected_source,
            'score' => $row->score === null ? null : (int) $row->score,
            'lyricsSaved' => (int) $row->lyrics_saved === 1,
            'artworkStatus' => (string) $row->artwork_status,
            'resources' => $resources,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'createdAt' => (string) $row->created_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'commands' => [
                'canRequeue' => $songAvailable && in_array((string) $row->status, self::TERMINAL_STATUSES, true),
                'canViewTask' => (string) $row->requested_by === (string) ($actor['id'] ?? ''),
            ],
        ];
    }

    /** @return array{title:string,artists:list<string>,albumTitle:string} */
    private function decodeEvidence(mixed $json): array
    {
        $fallback = ['title' => '歌曲已移除', 'artists' => [], 'albumTitle' => ''];
        if (!is_string($json) || $json === '') return $fallback;
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $fallback;
        }
        if (!is_array($value) || !is_string($value['title'] ?? null)
            || !is_array($value['artists'] ?? null)) return $fallback;
        $artists = array_values(array_filter($value['artists'], 'is_string'));
        return [
            'title' => mb_substr($value['title'], 0, 200, 'UTF-8'),
            'artists' => array_map(static fn (string $artist): string => mb_substr($artist, 0, 120, 'UTF-8'),
                array_slice($artists, 0, 20)),
            'albumTitle' => is_string($value['albumTitle'] ?? null)
                ? mb_substr($value['albumTitle'], 0, 200, 'UTF-8') : '',
        ];
    }

    /** @return array{total:int,succeeded:int,issues:int} */
    private function resourceSummary(mixed $json): array
    {
        $summary = ['total' => 0, 'succeeded' => 0, 'issues' => 0];
        if (!is_string($json) || $json === '') return $summary;
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $summary;
        }
        if (!is_array($value) || array_is_list($value)) return $summary;
        foreach (['relatedArtwork', 'assetPublications'] as $key) {
            if (!is_array($value[$key] ?? null)) continue;
            foreach (array_slice($value[$key], 0, 50) as $resource) {
                if (!is_array($resource) || !is_string($resource['status'] ?? null)) continue;
                ++$summary['total'];
                $status = $resource['status'];
                if (in_array($status, ['succeeded', 'selected', 'preserved', 'skipped'], true)) {
                    ++$summary['succeeded'];
                } elseif (in_array($status, ['failed', 'conflict', 'cancelled', 'unavailable'], true)) {
                    ++$summary['issues'];
                }
            }
        }
        if (is_string($value['coordinatorErrorCode'] ?? null) && $value['coordinatorErrorCode'] !== '') {
            ++$summary['issues'];
        }
        return $summary;
    }

    /** @return array<string,true> */
    private function manageableLibraryIds(array $actor): array
    {
        $result = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && ($library['accessLevel'] ?? null) === 'manage') $result[$library['id']] = true;
        }
        return $result;
    }

    private function requireRunScrape(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('run_scrape', $capabilities, true)) {
            throw new AuthorizationDenied('缺少在线刮削权限。');
        }
    }

    private function supportsConfirmationMode(): bool
    {
        return Db::connection()->getSchemaBuilder()->hasColumn('metadata_sync_scrape_jobs', 'confirmation_required');
    }

    private function supportsUnifiedPipeline(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('metadata_sync_scrape_targets', 'phase')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'next_attempt_at')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'resource_status_json');
    }

    private function invalidUlid(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id) !== 1;
    }

    /** @return array<string,mixed> */
    private function emptyPage(int $limit, int $offset): array
    {
        return [
            'records' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset,
            'filterOptions' => ['libraries' => []],
        ];
    }
}
