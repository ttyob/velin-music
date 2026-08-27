<?php

declare(strict_types=1);

namespace app\application\Metadata;

use Closure;
use app\application\Artist\ArtistProfileScrapeJobService;
use app\application\Artwork\ArtworkCandidateImageNormalizer;
use app\application\Artwork\ArtworkProviderAttribution;
use app\application\Artwork\ArtworkRemoteImageFetcher;
use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use app\application\Lyrics\LyricsFileStore;
use app\application\Notification\NotificationPublisher;
use app\application\Lyrics\LyricsFileUnavailable;
use app\application\Scrape\MetadataEnrichmentProvider;
use app\application\Scrape\PluginMetadataEnrichmentProvider;
use app\application\Scrape\MetadataQueryKeywordService;
use app\application\Scrape\MetadataProviderResult;
use app\application\Scrape\MetadataEnrichmentResult;
use app\application\Scrape\ScrapeGeneratedLyrics;
use app\application\Scrape\ScrapeGeneratedArtwork;
use app\application\Scrape\ScrapeMetadataCandidate;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Database\SqliteTransientRetry;
use app\infrastructure\Database\SqliteWriteGate;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 逐首执行库内歌曲候选搜索或确认后的应用，并把网络阶段与 SQLite 写事务严格分离。
 *
 * 一个进程始终只执行一个目标；进程层可通过有界 drain 在前一步完全释放租约后继续下一步。平台查询
 * 使用创建任务时冻结的无路径证据，网络完成后在短事务内再次
 * 检查账号状态、`edit_metadata`/`run_scrape`、目标库 manage grant 和歌曲证据摘要。新版首次查询
 * 只冻结候选并等待管理员选择；确认后的再次查询还会复验候选摘要，成功才更新
 * scraped 来源层、可选结构化歌词及歌曲封面数据库候选，并在同一事务登记派生资源 outbox；本
 * Worker 不访问音频文件、不写标签、不生成 sidecar、不移动或重命名文件。事务提交后由发布消费者
 * 按库策略写受控缓存或相邻资源，封面网络失败仍按补全项降级。
 */
final class MetadataSyncScrapeWorkerService
{
    // 外部渠道可能串行等待多个平台；十分钟仍是有界上限，最终写入继续使用 worker_id CAS。
    private const LEASE_SECONDS = 600;
    private const MAX_DRAIN_STEPS = 20;
    private const MAX_DRAIN_SECONDS = 10.0;
    private const MAX_PROVIDER_ATTEMPTS = 3;
    private const PROVIDER_RETRY_BASE_SECONDS = 5;
    private const MAX_STALE_LEASE_RECOVERIES = 100;

    public function __construct(
        private readonly MetadataSyncScrapeService $jobs = new MetadataSyncScrapeService(),
        private readonly MetadataFieldStateRepository $states = new MetadataFieldStateRepository(),
        private readonly AlbumMetadataScrapeJobService $albumScrapes = new AlbumMetadataScrapeJobService(),
        private readonly MetadataEnrichmentProvider $provider = new PluginMetadataEnrichmentProvider(),
        private readonly ArtworkRemoteImageFetcher $artworkImages = new ArtworkRemoteImageFetcher(),
        private readonly ArtworkCandidateImageNormalizer $artworkNormalizer = new ArtworkCandidateImageNormalizer(),
        private readonly MetadataQueryKeywordService $keywords = new MetadataQueryKeywordService(),
        private readonly CapabilityResolver $capabilities = new CapabilityResolver(),
        private readonly LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ScrapeAssetPublicationService $assetPublications = new ScrapeAssetPublicationService(),
        private readonly LyricsFileStore $lyricsFiles = new LyricsFileStore(),
        private readonly NotificationPublisher $notifications = new NotificationPublisher(),
        private readonly SongScrapeRelatedArtworkService $relatedArtwork = new SongScrapeRelatedArtworkService(),
        private readonly ArtistProfileScrapeJobService $artistProfiles = new ArtistProfileScrapeJobService(),
        private readonly SongMetadataScrapePolicy $songScrapePolicy = new SongMetadataScrapePolicy(),
        private readonly SqliteTransientRetry $sqliteRetry = new SqliteTransientRetry(),
        private readonly SqliteWriteGate $sqliteWriteGate = new SqliteWriteGate(),
        private readonly MetadataEntityScrapeIntentService $entityIntents = new MetadataEntityScrapeIntentService(),
    ) {}

    /**
     * 条件领取最早的 pending 目标，并把父任务推进到 running。
     *
     * SQLite 短事务使用目标状态、空 worker_id 和同父任务前序条件形成领取 CAS；失败返回 null，不产生
     * 网络或文件副作用。首次领取写 started_at，资源阶段释放租约或崩溃恢复后的重领只增加 attempt 并
     * 保留首次时间，保证完整逐曲耗时可观测。父任务开始时间使用同样的 COALESCE 语义；事务回滚时目标
     * 与父任务都不留下半领取状态，单消费者之外的误启动实例也不能越过 position 顺序屏障。
     */
    public function claimNext(string $workerId, ?string $jobId = null): ?array
    {
        return $this->writeTransaction(function () use ($workerId, $jobId): ?array {
            $unified = $this->supportsUnifiedPipeline();
            /** @var stdClass|null $row */
            $query = Db::table('metadata_sync_scrape_targets as targets')
                ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
                ->where('targets.status', 'pending')->whereNull('targets.worker_id')
                ->whereIn('jobs.status', ['queued', 'running']);
            if ($jobId !== null) $query->where('targets.job_id', $jobId);
            if ($unified) {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $query->where(function ($next) use ($now): void {
                    $next->whereNull('targets.next_attempt_at')->orWhere('targets.next_attempt_at', '<=', $now);
                })->whereNotExists(function ($prior): void {
                    $prior->selectRaw('1')->from('metadata_sync_scrape_targets as prior_targets')
                        ->whereColumn('prior_targets.job_id', 'targets.job_id')
                        ->whereColumn('prior_targets.position', '<', 'targets.position')
                        ->whereIn('prior_targets.status', ['pending', 'running']);
                });
            }
            $row = $query->orderBy('targets.created_at')->orderBy('targets.position')
                ->first(['targets.id', 'targets.job_id']);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $row->id)
                ->where('status', 'pending')->whereNull('worker_id')->update([
                    'status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                    'attempt' => Db::raw('attempt + 1'),
                    'started_at' => Db::raw('COALESCE(started_at, '
                        . Db::connection()->getPdo()->quote($now) . ')'),
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            Db::table('metadata_sync_scrape_jobs')->where('id', (string) $row->job_id)
                ->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'running', 'started_at' => Db::raw('COALESCE(started_at, ' . Db::connection()->getPdo()->quote($now) . ')'),
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            return ['id' => (string) $row->id, 'jobId' => (string) $row->job_id, 'workerId' => $workerId];
        });
    }

    /**
     * 在一个进程 tick 内有界交替推进逐曲目标和歌曲封面发布。
     *
     * 每次目标领取、执行和资源发布都完整结束后才进入下一步，SQLite 仍只有当前 Metadata Worker 一个
     * 消费者，不并行歌曲，也不绕过同父任务 position 屏障。典型自动任务第一次执行提交核心元数据并
     * 登记资源，发布步骤随后在同一循环完成，第二次领取即可汇总资源并立即开始下一首，从而消除固定
     * 两秒轮询间隙。若图片子任务仍活动、next_attempt_at 尚未到期或人工确认正在等待，claimNext 返回
     * null，循环自然停止，其他进程完成资源后仍由 Redis 唤醒或周期轮询恢复。人工模式已经冻结候选的
     * target 不会被重复执行；同批次其他尚未查询歌曲仍沿用既有 position 规则推进候选搜索。
     *
     * maximumSteps 限制目标与资源发布步骤合计最多 20 次；maximumSeconds 最多 10 秒，并在每
     * 个完整步骤后检查。单次外部查询本身可能超过预算，但完成后不会再领取新工作。shouldStop 在领取
     * 前复验停机状态，已经领取的 Durable 步骤仍按原状态机收口，不留下不确定写入。返回值只统计目标
     * 执行次数，便于测试和运行指标判断是否命中上限；重复调用保持幂等。
     *
     * @param null|callable():bool $shouldStop
     */
    public function drain(
        string $workerId,
        int $maximumSteps,
        float $maximumSeconds = self::MAX_DRAIN_SECONDS,
        ?callable $shouldStop = null,
    ): int {
        $limit = min(self::MAX_DRAIN_STEPS, max(0, $maximumSteps));
        if ($limit === 0) return 0;
        $budget = min(self::MAX_DRAIN_SECONDS, max(0.1, $maximumSeconds));
        $deadline = hrtime(true) + (int) round($budget * 1_000_000_000);
        $targetSteps = 0;
        $operationSteps = 0;
        $drainJobId = null;
        $drainTargetId = null;
        while ($operationSteps < $limit) {
            if ($shouldStop !== null && $shouldStop()) break;
            $advanced = false;
            $target = $this->claimNext($workerId, $drainJobId);
            if ($target !== null) {
                $drainJobId ??= $target['jobId'];
                $drainTargetId = $target['id'];
                $this->execute($target);
                ++$targetSteps;
                ++$operationSteps;
                $advanced = true;
            }
            if (hrtime(true) >= $deadline) break;
            if (($shouldStop === null || !$shouldStop()) && $operationSteps < $limit) {
                $publication = $this->assetPublications->claimNext(
                    $workerId . ':scrape-assets',
                    $drainTargetId,
                );
                if ($publication !== null) {
                    $this->assetPublications->execute($publication);
                    ++$operationSteps;
                    $advanced = true;
                }
            }
            if (!$advanced || hrtime(true) >= $deadline) break;
        }
        return $targetSteps;
    }

    /**
     * 查询歌曲元数据插件并终结一个目标。插件自身会隔离其内部来源；没有可靠插件候选时只保留本地文件元数据。
     *
     * SQLite 的 BUSY/LOCKED 只代表其他 Worker 的短写事务暂时持有写锁；整个逐曲流程依赖冻结证据和
     * 幂等业务键，可以在无外层事务的边界从头重放。因此网络查询、资源入队和最终状态提交统一经过
     * 有界退避重试，避免一次扫描竞争把本可恢复的歌曲永久写成内部失败。重试耗尽后才收口固定错误码，
     * 不把异常正文、SQL、路径或第三方响应写入任务投影。
     *
     * @param array{id:string,workerId:string} $claimed
     */
    public function execute(array $claimed): void
    {
        /** @var stdClass|null $target */
        $columns = ['targets.*', 'jobs.requested_by', 'jobs.request_id'];
        if (Db::connection()->getSchemaBuilder()->hasColumn('metadata_sync_scrape_jobs', 'confirmation_required')) {
            $columns[] = 'jobs.confirmation_required';
        }
        $target = Db::table('metadata_sync_scrape_targets as targets')
            ->join('metadata_sync_scrape_jobs as jobs', 'jobs.id', '=', 'targets.job_id')
            ->where('targets.id', $claimed['id'])->where('targets.status', 'running')
            ->where('targets.worker_id', $claimed['workerId'])->first($columns);
        if (!$target instanceof stdClass) return;
        try {
            $this->sqliteRetry->run(function () use ($target): void {
            if (property_exists($target, 'phase') && (string) $target->phase === 'completing_resources') {
                $this->continueResourceStage($target);
                return;
            }
            if (property_exists($target, 'phase') && (string) $target->phase === 'applying'
                && property_exists($target, 'confirmation_required') && (int) $target->confirmation_required === 1
                && $this->waitForSelectedArtworkImports($target)) {
                return;
            }
            $frozen = $this->jobs->decodeFrozenEvidence((string) $target->evidence_json);
            $this->assertLiveAuthorization((string) $target->requested_by, (string) $target->library_id);
            $current = $target->song_id === null ? null : $this->jobs->currentEvidence((string) $target->song_id);
            if ($current === null || !$this->matchesEvidence($target, $current)) {
                $this->finishFailure($target, 'METADATA_SYNC_EVIDENCE_STALE');
                return;
            }
            $confirmationRequired = property_exists($target, 'confirmation_required')
                && (int) $target->confirmation_required === 1;
            if (!$confirmationRequired && ($frozen['providerQueryRequired'] ?? true) === false
                && !$this->songScrapePolicy->shouldQuery($current)) {
                $this->persistProviderSkipped($target);
                if ($this->supportsUnifiedPipeline()) {
                    $this->relatedArtwork->enqueueQueries($target, true, false);
                }
                return;
            }
            $selection = null;
            $selectedSources = null;
            if ($confirmationRequired && is_string($target->selection_json ?? null)
                && $target->selection_json !== '') {
                $selection = MetadataSyncScrapeSelection::fromJson((string) $target->selection_json);
                $selectedSources = $selection->sources();
            } elseif ($confirmationRequired && is_string($target->selected_source ?? null)
                && $target->selected_source !== '') {
                // 滚动部署期间旧确认接口只冻结单一渠道；插件 Provider 会把旧键按不可用收口，绝不恢复核心查询。
                $selectedSources = [(string) $target->selected_source];
            }
            $enrichment = $this->enrichFromFrozenEvidence($frozen, (string) $target->id, $selectedSources);
            // 网络阶段结束后刷新一次租约，避免刚完成长查询就被恢复器误判为孤儿；迟到 Worker 仍受最终 CAS 拒绝。
            $this->refreshLease($target);
            $relatedArtworkOnly = $selection instanceof MetadataSyncScrapeSelection
                && $selection->sources() === []
                && $selection->relatedArtwork !== [];
            $matched = false;
            foreach ($enrichment->channels as $channel) {
                if ($channel->channelKey !== 'local' && $channel->status === 'matched' && $channel->candidate !== null) {
                    $matched = true;
                    break;
                }
            }
            if (!$relatedArtworkOnly && (!$matched || $enrichment->finalCandidate->source === 'catalog')) {
                if ($this->hasUnavailableExternalChannel($enrichment->channels)) {
                    $this->retryOrFailProviderUnavailable($target, $enrichment->channels);
                } else {
                    $this->finishUnmatched($target, $enrichment->channels);
                }
                return;
            }
            if ($confirmationRequired && $target->selected_source === null) {
                if ($this->supportsUnifiedPipeline()) {
                    $this->relatedArtwork->enqueueQueries($target, false, true);
                }
                $this->persistSearch($target, $enrichment->channels, (string) $frozen['title']);
                return;
            }
            $candidate = $enrichment->finalCandidate;
            $lyricsCandidates = $enrichment->generatedLyricsCandidates;
            $artworkCandidates = $enrichment->generatedArtworkCandidates;
            $selectedLyricsSource = null;
            if ($confirmationRequired) {
                if ($selection instanceof MetadataSyncScrapeSelection) {
                    $channelCandidates = [];
                    foreach ($enrichment->channels as $channel) {
                        if ($channel->status === 'matched' && $channel->candidate !== null) {
                            $channelCandidates[$channel->channelKey] = $channel->candidate;
                        }
                    }
                    try {
                        $candidateDigest = $selection->candidateDigest($channelCandidates);
                    } catch (MediaMetadataConflict) {
                        $this->finishFailure($target, 'METADATA_SYNC_CANDIDATE_STALE');
                        return;
                    }
                    if (!is_string($target->selected_candidate_sha256 ?? null)
                        || !hash_equals((string) $target->selected_candidate_sha256, $candidateDigest)) {
                        $this->finishFailure($target, 'METADATA_SYNC_CANDIDATE_STALE');
                        return;
                    }
                    try {
                        $candidate = $this->selectedCandidate($frozen, $selection, $channelCandidates);
                    } catch (MediaMetadataConflict) {
                        $this->finishFailure($target, 'METADATA_SYNC_CANDIDATE_STALE');
                        return;
                    }
                    $lyricsCandidates = array_values(array_filter($lyricsCandidates,
                        static fn (ScrapeGeneratedLyrics $lyrics): bool => $lyrics->source === $selection->lyrics));
                    $selectedLyricsSource = $selection->lyrics;
                    $artworkCandidates = array_values(array_filter($artworkCandidates,
                        static fn (ScrapeGeneratedArtwork $artwork): bool => $artwork->source === $selection->artwork));
                    if (($selection->lyrics !== null && $lyricsCandidates === [])
                        || ($selection->artwork !== null && $artworkCandidates === [])) {
                        $this->finishFailure($target, 'METADATA_SYNC_CANDIDATE_STALE');
                        return;
                    }
                } else {
                    // 滚动部署期间已经按旧接口确认的目标仍使用单渠道摘要；新任务一律写 selection_json。
                    $selectedSource = (string) $target->selected_source;
                    $selectedChannel = null;
                    foreach ($enrichment->channels as $channel) {
                        if ($channel->channelKey === $selectedSource && $channel->status === 'matched'
                            && $channel->candidate !== null) $selectedChannel = $channel;
                    }
                    if ($selectedChannel === null || !is_string($target->selected_candidate_sha256 ?? null)
                        || !hash_equals((string) $target->selected_candidate_sha256,
                            hash('sha256', $selectedChannel->candidate->toJson()))) {
                        $this->finishFailure($target, 'METADATA_SYNC_CANDIDATE_STALE');
                        return;
                    }
                    $candidate = $selectedChannel->candidate;
                    $selectedLyricsSource = $selectedSource;
                    $lyricsCandidates = array_values(array_filter($lyricsCandidates,
                        static fn (ScrapeGeneratedLyrics $lyrics): bool => $lyrics->source === $selectedSource));
                    $artworkCandidates = array_values(array_filter($artworkCandidates,
                        static fn (ScrapeGeneratedArtwork $artwork): bool => $artwork->source === $selectedSource));
                }
            }
            $artwork = $this->prepareArtwork($artworkCandidates);
            $preparedLyrics = $this->prepareLyrics(
                (string) $target->song_id,
                $lyricsCandidates,
                $enrichment->channels,
                $candidate->confidence,
                $selectedLyricsSource !== null,
            );
            $this->persistSuccess(
                $target,
                $candidate,
                $preparedLyrics,
                $enrichment->channels,
                $artwork,
                $selectedLyricsSource,
            );
            if ($this->supportsUnifiedPipeline()) {
                if (!$confirmationRequired) {
                    $this->relatedArtwork->enqueueQueries($target, true, false);
                } elseif (is_string($target->selection_json ?? null) && $target->selection_json !== '') {
                    $selection = MetadataSyncScrapeSelection::fromJson((string) $target->selection_json);
                    $this->relatedArtwork->enqueueSelections($target, $selection->relatedArtwork);
                }
            }
            }, function (int $retry, int $delayMs) use ($target): void {
                Log::warning('Metadata sync scrape SQLite write conflict will be retried.', [
                    'job_id' => (string) $target->job_id,
                    'target_id' => (string) $target->id,
                    'reason_code' => 'SQLITE_TRANSIENT_WRITE_CONFLICT',
                    'retry_number' => $retry,
                    'delay_ms' => $delayMs,
                ]);
            });
        } catch (Throwable $throwable) {
            // 异常对象可能包含平台进程或协议细节。日志只保存异常类和内部任务标识，由全局错误记录器
            // 聚合排障；任务投影仍只保存固定错误码，不泄露正文、路径、URL 或第三方原始响应。
            if (is_array(config('log'))) {
                try {
                    Log::error('Metadata sync scrape target failed.', [
                        'job_id' => (string) $target->job_id,
                        'target_id' => (string) $target->id,
                        'exception_class' => $throwable::class,
                    ]);
                } catch (Throwable) {
                    // 日志基础设施不是任务状态机的提交前置条件。生产日志临时不可写时，仍必须把已领取
                    // 目标可靠收口，避免永久停在 running 等待租约恢复。
                }
            }
            if (property_exists($target, 'phase') && (string) $target->phase === 'completing_resources') {
                $this->completeResourceStage($target, [
                    'version' => 1,
                    'relatedArtwork' => [],
                    'assetPublications' => [],
                    'coordinatorErrorCode' => 'METADATA_SYNC_RESOURCE_COORDINATOR_FAILED',
                ]);
            } else {
                $this->finishFailure($target, 'METADATA_SYNC_INTERNAL_FAILED');
            }
        }
    }

    /**
     * 冻结渠道候选并把目标停在人工确认边界，不修改任何媒体业务数据。
     *
     * 候选快照和等待标记在同一短事务提交。目标使用历史约束允许的 unmatched 作为非执行终态，真正
     * 的产品阶段由 awaiting_confirmation 投影；确认接口会把它原子恢复为 pending。这样迁移不必重建
     * 被多张表引用的 SQLite 任务表。重复 Worker 租约只能有一个条件更新成功。
     */
    private function persistSearch(stdClass $target, array $channels, string $songTitle): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($target, $channels, $songTitle, $now): void {
            $this->replaceChannelResults((string) $target->id, $channels, $now);
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update([
                    'status' => 'unmatched', 'awaiting_confirmation' => 1, 'selected_source' => null,
                    'selected_candidate_sha256' => null, 'selection_json' => null,
                    'score' => null, 'lyrics_saved' => 0,
                    'artwork_status' => 'pending', 'error_code' => null, 'worker_id' => null,
                    'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
                ]);
            if ($this->supportsUnifiedPipeline()) {
                Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)->update([
                    'phase' => 'awaiting_confirmation', 'next_attempt_at' => null,
                ]);
            }
            if ($changed !== 1) throw new MediaMetadataConflict('候选搜索任务租约已经变化。');
            $this->synchronizeJob((string) $target->job_id, $now);
            $matchedCandidates = count(array_filter(
                $channels,
                static fn (MetadataProviderResult $channel): bool => $channel->status === 'matched'
                    && $channel->candidate !== null,
            ));
            $this->notifications->publishScrapeAwaitingConfirmation(
                (string) $target->requested_by,
                (string) $target->library_id,
                (string) $target->job_id,
                $songTitle,
                $matchedCandidates,
                $now,
            );
        });
    }

    /**
     * 有界恢复超时租约，避免异常积压把一次 Worker tick 变成长事务。
     *
     * 每轮最多按心跳和 ID 稳定恢复一百行，并在 SQLite 写闸门保护的短事务内逐行执行心跳 CAS；恢复器
     * 不接管仍有新心跳的目标，也不修改媒体业务数据。剩余过期行由后续两秒轮询继续处理，因此大量进程
     * 崩溃记录会渐进收敛，而不会一次占满事件循环或制造无界写锁竞争。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        $this->writeTransaction(function () use ($threshold): void {
            /** @var list<stdClass> $rows */
            $rows = Db::table('metadata_sync_scrape_targets')->where('status', 'running')
                ->whereNotNull('worker_id')->where('heartbeat_at', '<', $threshold)
                ->orderBy('heartbeat_at')->orderBy('id')->limit(self::MAX_STALE_LEASE_RECOVERIES)
                ->get(['id', 'heartbeat_at'])->all();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            foreach ($rows as $row) {
                Db::table('metadata_sync_scrape_targets')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                        'status' => 'pending', 'worker_id' => null, 'heartbeat_at' => null,
                        'error_code' => null, 'updated_at' => $now,
                    ]);
            }
        });
    }

    /**
     * 为仍持有的歌曲目标续租一次。
     *
     * 只更新当前 running + worker_id 的行；返回 0 表示另一个 Worker 已恢复或终结目标，调用方必须停止
     * 后续写入。该检查点不延长已丢失租约，也不改变任务状态机，因此网络查询迟到时不会覆盖新结果。
     */
    private function refreshLease(stdClass $target): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
            ->where('status', 'running')->where('worker_id', (string) $target->worker_id)
            ->update(['heartbeat_at' => $now, 'updated_at' => $now]);
        if ($changed !== 1) throw new MediaMetadataConflict('同步刮削任务租约已经变化。');
    }

    /**
     * 在事务内二次复权、应用跨渠道 scraped 候选、登记已发布歌词文件并终结目标。
     *
     * 歌词文件已经在 SQLite 事务外完成解析和非覆盖原子发布。这里只登记首选渠道的文件索引，避免
     * 相邻模式下多个 Provider 争用同一标准 `.lrc`。数据库失败可能留下无引用的内容寻址缓存，但不会
     * 留下指向未发布文件的歌词记录，也不会猜测性删除用户相邻文件。
     *
     * @param null|array{lyrics:ScrapeGeneratedLyrics,file:array<string,mixed>,score:int} $preparedLyrics
     * @param array{status:'ready'|'unavailable'|'failed',candidate:?array<string,mixed>} $artwork
     */
    private function persistSuccess(
        stdClass $target,
        ScrapeMetadataCandidate $candidate,
        ?array $preparedLyrics,
        array $channels,
        array $artwork,
        ?string $selectedLyricsSource = null,
    ): void
    {
        $this->writeTransaction(function () use (
            $target, $candidate, $preparedLyrics, $channels, $artwork, $selectedLyricsSource,
        ): void {
            $this->assertLiveAuthorization((string) $target->requested_by, (string) $target->library_id);
            $current = $target->song_id === null ? null : $this->jobs->currentEvidence((string) $target->song_id);
            if ($current === null || !$this->matchesEvidence($target, $current)) {
                throw new MediaMetadataConflict('歌曲证据已经变化。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $providedMetadata = $this->providedCandidateMetadata($candidate);
            if ($providedMetadata !== []) {
                $this->states->applyScrapedCandidate((string) $target->song_id, $providedMetadata, $now);
            }
            $selectedLyricId = $preparedLyrics === null ? null : $this->persistLyrics(
                (string) $target->song_id,
                $preparedLyrics['score'],
                $preparedLyrics['lyrics'],
                $preparedLyrics['file'],
                $now,
            );
            $lyricsSaved = $selectedLyricId !== null;
            if ($selectedLyricId !== null && $selectedLyricsSource !== null) {
                $this->setPrimaryLyrics((string) $target->song_id, $selectedLyricId, (string) $target->requested_by, $now);
            }
            $artwork = $this->persistArtwork($target, $artwork, $now);
            $artworkStatus = $artwork['status'];
            $this->assetPublications->enqueue(
                (string) $target->id,
                null,
                $artwork['candidateId'],
                $now,
            );
            $this->queueRelatedEntityMetadata($target, $now);
            // 平台结果与目标终态在同一事务内发布。弹窗永远不会看到“已成功”却缺少渠道结果的半成品，
            // Worker 租约超时重试时也会完整替换同一目标的旧快照，而不是累积重复平台行。
            $this->replaceChannelResults((string) $target->id, $channels, $now);
            $targetUpdate = [
                'status' => 'succeeded', 'selected_source' => $candidate->source,
                'score' => $candidate->confidence, 'lyrics_saved' => $lyricsSaved ? 1 : 0,
                'artwork_status' => $artworkStatus,
                'error_code' => null, 'worker_id' => null, 'heartbeat_at' => null,
                'finished_at' => $now, 'updated_at' => $now,
            ];
            if ($this->supportsUnifiedPipeline()) {
                $targetUpdate['status'] = 'pending';
                $targetUpdate['phase'] = 'completing_resources';
                $targetUpdate['next_attempt_at'] = $now;
                $targetUpdate['resource_status_json'] = null;
                $targetUpdate['finished_at'] = null;
            }
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update([
                    ...$targetUpdate,
                ]);
            if ($changed !== 1) throw new MediaMetadataConflict('同步刮削任务租约已经变化。');
            $this->audit->record((string) $target->requested_by, $this->supportsUnifiedPipeline()
                ? 'metadata.sync_scrape.target.core_complete' : 'metadata.sync_scrape.target.complete',
                'song', (string) $target->song_id, 'success', (string) $target->request_id, [
                    'jobId' => (string) $target->job_id, 'libraryId' => (string) $target->library_id,
                    'source' => $candidate->source, 'score' => $candidate->confidence,
                    'lyricsSaved' => $lyricsSaved, 'artworkStatus' => $artworkStatus,
                ]);
            if (!$this->supportsUnifiedPipeline()) $this->synchronizeJob((string) $target->job_id, $now);
        });
    }

    /**
     * 在歌曲标签、歌词和歌曲图片均完整时跳过歌曲插件，但继续统一实体资源阶段。
     *
     * 该分支只用于创建 target 时已经冻结为无需查询的自动任务；手工刮削和全量刷新永不进入。目标仍
     * 保留图片与派生资源的逐曲顺序屏障；专辑/艺人资料只登记到各自异步队列，不阻塞下一首歌曲。提交
     * 只更新任务与脱敏审计，不写 scraped 字段、不发布歌词或歌曲封面；重试由 target 租约 CAS 保证幂等。
     */
    private function persistProviderSkipped(stdClass $target): void
    {
        $this->writeTransaction(function () use ($target): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $update = [
                'status' => 'succeeded', 'selected_source' => 'local', 'score' => null,
                'lyrics_saved' => 0, 'artwork_status' => 'preserved', 'error_code' => null,
                'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
            ];
            if ($this->supportsUnifiedPipeline()) {
                $update['status'] = 'pending';
                $update['phase'] = 'completing_resources';
                $update['next_attempt_at'] = $now;
                $update['resource_status_json'] = null;
                $update['finished_at'] = null;
            }
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update($update);
            if ($changed !== 1) throw new MediaMetadataConflict('同步刮削任务租约已经变化。');
            $this->queueRelatedEntityMetadata($target, $now);
            $this->audit->record((string) $target->requested_by, 'metadata.sync_scrape.target.provider_skipped',
                'song', (string) $target->song_id, 'success', (string) $target->request_id, [
                    'jobId' => (string) $target->job_id,
                    'libraryId' => (string) $target->library_id,
                    'reasonCode' => 'SONG_FACTS_COMPLETE',
                ]);
            if (!$this->supportsUnifiedPipeline()) $this->synchronizeJob((string) $target->job_id, $now);
        });
    }

    /**
     * 在歌曲核心阶段结束后分别登记艺人和专辑资料任务。
     *
     * 两个服务会使用各自业务完整度策略，并独立应用唯一键、活动租约和失败冷却；本方法不把歌曲插件
     * 结果传给实体插件。任一入队失败只记录异常类型，不能回滚歌曲目标或阻止另一个实体类型继续判断。
     */
    private function enqueueRelatedEntityMetadata(stdClass $target): void
    {
        try {
            $this->artistProfiles->enqueueForSong((string) $target->song_id);
        } catch (Throwable $throwable) {
            if (is_array(config('log'))) {
                try {
                    Log::warning('Artist profile enqueue skipped after song scrape.', [
                        'exception_class' => $throwable::class,
                    ]);
                } catch (Throwable) {
                    // 补全队列日志与队列本身都不是歌曲成功提交的前置条件。
                }
            }
        }
        try {
            $this->albumScrapes->enqueueForSong((string) $target->song_id);
        } catch (Throwable $throwable) {
            if (is_array(config('log'))) {
                try {
                    Log::warning('Album metadata scrape enqueue skipped after song scrape.', [
                        'exception_class' => $throwable::class,
                    ]);
                } catch (Throwable) {
                    // 专辑补全是可恢复的后续任务，不是歌曲成功提交的前置条件。
                }
            }
        }
    }

    /**
     * 新 schema 使用事务性意图；旧滚动部署没有意图表时保留直接入队兼容行为。
     *
     * 意图写入必须和歌曲目标终态处于同一 SQLite 短事务；旧 schema 无法提供该保证，因此仅在兼容
     * 分支调用原有的独立幂等服务，并把异常限制在日志，不影响歌曲核心结果。
     */
    private function queueRelatedEntityMetadata(stdClass $target, string $now): void
    {
        if (Db::connection()->getSchemaBuilder()->hasTable('metadata_entity_scrape_intents')) {
            $this->entityIntents->enqueue((string) $target->song_id, $now);
            return;
        }
        $this->enqueueRelatedEntityMetadata($target);
    }

    /**
     * 在数据库事务外发布最高优先级歌词文件。
     *
     * 候选顺序已经由 Provider 聚合器按匹配质量确定。自动任务遇到已有歌词会保留现值；人工明确选择
     * 来源时则必须继续发布并绑定主歌词。文件冲突、缓存根不可用或解析失败只让本次歌词补全缺席，
     * 歌曲元数据与封面仍可提交；既有相邻歌词绝不被自动覆盖。
     *
     * @param list<ScrapeGeneratedLyrics> $candidates
     * @return null|array{lyrics:ScrapeGeneratedLyrics,file:array<string,mixed>,score:int}
     */
    private function prepareLyrics(
        string $songId,
        array $candidates,
        array $channels,
        int $fallbackScore,
        bool $explicitSelection = false,
    ): ?array
    {
        // 自动任务保留已有歌词；人工选择必须继续处理，不能因历史行存在而静默丢弃管理员决定。
        if (!$explicitSelection && Db::table('media_lyrics')->where('song_id', $songId)->exists()) return null;
        $lyrics = $candidates[0] ?? null;
        if (!$lyrics instanceof ScrapeGeneratedLyrics) return null;
        $scores = [];
        foreach ($channels as $channel) {
            if ($channel->candidate !== null) $scores[$channel->channelKey] = $channel->candidate->confidence;
        }
        try {
            $file = $this->lyricsFiles->publishForSong($songId, $lyrics->lrc, 'scrape');
        } catch (LyricsFileUnavailable) {
            return null;
        }
        return ['lyrics' => $lyrics, 'file' => $file, 'score' => $scores[$lyrics->source] ?? $fallbackScore];
    }

    /**
     * 在歌曲成功事务内把确认的歌词绑定为主版本。
     *
     * 人工选择必须留下可读取的主歌词关系，否则候选虽然写入数据库，播放和管理页仍会继续展示旧歌词。
     * 选择表缺失表示迁移未完成，直接抛出冲突并回滚本次歌曲提交；已有选择按版本递增更新，重复执行保持
     * 同一 lyric_id 幂等。该方法不删除其他来源歌词，也不写音频标签。
     */
    private function setPrimaryLyrics(string $songId, string $lyricId, string $updatedBy, string $now): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('media_lyrics_primary_selections')) {
            throw new MediaMetadataConflict('主歌词选择表尚未迁移。');
        }
        /** @var stdClass|null $current */
        $current = Db::table('media_lyrics_primary_selections')->where('song_id', $songId)->first(['version']);
        if ($current instanceof stdClass) {
            Db::table('media_lyrics_primary_selections')->where('song_id', $songId)->update([
                'lyric_id' => $lyricId, 'version' => ((int) $current->version) + 1,
                'updated_by' => $updatedBy, 'updated_at' => $now,
            ]);
            return;
        }
        Db::table('media_lyrics_primary_selections')->insert([
            'song_id' => $songId, 'lyric_id' => $lyricId, 'version' => 1,
            'updated_by' => $updatedBy, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * 按分数顺序尝试可靠歌曲封面，并在数据库事务外完成全部网络和图片处理。
     *
     * 候选已经通过标题/艺术家硬匹配，但 URL 仍不可信；下载器会重新执行固定 CDN、公开 DNS、HTTPS、
     * 禁止重定向和图片事实校验。某个平台失败后继续尝试下一平台，实现多渠道补全。成功结果只携带
     * 规范化 WebP、来源和不可逆内容摘要进入短事务；原始 URL、原图和异常均不会持久化。全部失败返回
     * `failed`，没有可靠地址返回 `unavailable`，两者都不影响元数据和歌词提交。
     *
     * @param list<ScrapeGeneratedArtwork> $candidates
     * @return array{status:'ready'|'unavailable'|'failed',candidate:?array<string,mixed>}
     */
    private function prepareArtwork(array $candidates): array
    {
        if ($candidates === []) return ['status' => 'unavailable', 'candidate' => null];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof ScrapeGeneratedArtwork) continue;
            $attribution = ArtworkProviderAttribution::for($candidate->source);
            if ($attribution === null) continue;
            try {
                $image = $this->artworkImages->fetch($candidate->source, $candidate->url);
                $normalized = $this->artworkNormalizer->centered($image['bytes'], $image['mimeType']);
                $contentSha = hash('sha256', $normalized);
                return ['status' => 'ready', 'candidate' => [
                    'source' => $candidate->source,
                    'bytes' => $normalized,
                    'contentSha256' => $contentSha,
                    'assetDigest' => hash('sha256', 'metadata-sync-song-artwork:' . $candidate->source . ':' . $contentSha),
                    'attributionText' => $attribution['text'],
                    'attributionUrl' => $attribution['url'],
                ]];
            } catch (Throwable) {
                // 异常可能含网络实现细节；这里只尝试下一可靠渠道，最终任务仅保存固定状态。
            }
        }
        return ['status' => 'failed', 'candidate' => null];
    }

    /**
     * 幂等导入已规范化歌曲封面，并只在歌曲尚无独立选择时自动启用。
     *
     * 前置条件：调用方处于本首歌曲成功事务中，已经完成实时账号、能力、库授权和歌曲证据复验；图片
     * 网络与编码必须在事务外完成。相同平台和规范化内容复用候选。若并发或历史操作已经存在歌曲选择，
     * 新候选仍可入库但返回 `preserved`，绝不推进或替换原选择版本。候选、选择、元数据、歌词和任务
     * 终态共享事务，任一数据库失败全部回滚；不会修改音频文件，也不会把远端 URL 写入数据库。
     *
     * @param array{status:'ready'|'unavailable'|'failed',candidate:?array<string,mixed>} $prepared
     * @return array{status:'selected'|'preserved'|'unavailable'|'failed',candidateId:?string}
     */
    private function persistArtwork(stdClass $target, array $prepared, string $now): array
    {
        if ($prepared['status'] !== 'ready' || !is_array($prepared['candidate'])) {
            return ['status' => $prepared['status'], 'candidateId' => null];
        }
        $artwork = $prepared['candidate'];
        /** @var stdClass|null $existing */
        $existing = Db::table('media_manual_artwork_candidates')->where('song_id', (string) $target->song_id)
            ->where('library_id', (string) $target->library_id)->where('origin_kind', 'provider')
            ->where('provider_asset_digest', (string) $artwork['assetDigest'])->first(['id', 'content_sha256']);
        if ($existing instanceof stdClass
            && !hash_equals((string) $existing->content_sha256, (string) $artwork['contentSha256'])) {
            throw new MediaMetadataConflict('歌曲封面候选身份已经变化。');
        }
        $candidateId = $existing instanceof stdClass ? (string) $existing->id : (string) new Ulid();
        if (!$existing instanceof stdClass) {
            Db::table('media_manual_artwork_candidates')->insert([
                'id' => $candidateId, 'song_id' => (string) $target->song_id,
                'album_id' => null, 'artist_id' => null, 'library_id' => (string) $target->library_id,
                'created_by' => (string) $target->requested_by, 'mime_type' => 'image/webp',
                'image_bytes' => (string) $artwork['bytes'], 'byte_size' => strlen((string) $artwork['bytes']),
                'width' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                'height' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                'content_sha256' => (string) $artwork['contentSha256'],
                'crop_x' => 0, 'crop_y' => 0, 'crop_width' => 10_000, 'crop_height' => 10_000,
                'origin_kind' => 'provider', 'provider_key' => (string) $artwork['source'],
                'provider_asset_digest' => (string) $artwork['assetDigest'], 'attribution_required' => 1,
                'attribution_text' => (string) $artwork['attributionText'],
                'attribution_url' => (string) $artwork['attributionUrl'], 'created_at' => $now,
            ]);
        }
        /** @var stdClass|null $selection */
        $selection = Db::table('media_artwork_selection_overrides')->where('song_id', (string) $target->song_id)
            ->where('library_id', (string) $target->library_id)->first(['candidate_id']);
        $status = 'preserved';
        if (!$selection instanceof stdClass) {
            Db::table('media_artwork_selection_overrides')->insert([
                'id' => (string) new Ulid(), 'song_id' => (string) $target->song_id,
                'album_id' => null, 'artist_id' => null, 'library_id' => (string) $target->library_id,
                'candidate_id' => $candidateId, 'version' => 1, 'updated_by' => (string) $target->requested_by,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $status = 'selected';
        } elseif (hash_equals((string) $selection->candidate_id, $candidateId)) {
            $status = 'selected';
        }
        $this->audit->record((string) $target->requested_by, 'metadata.sync_scrape.artwork.import',
            'song', (string) $target->song_id, 'success', (string) $target->request_id, [
                'jobId' => (string) $target->job_id, 'libraryId' => (string) $target->library_id,
                'candidateId' => $candidateId, 'providerKey' => (string) $artwork['source'],
                'selectionStatus' => $status,
            ]);
        return ['status' => $status, 'candidateId' => $status === 'selected' ? $candidateId : null];
    }

    /**
     * 将可靠平台歌词解析成结构化版本并返回稳定记录 ID。
     *
     * identity 不含正文，相同来源或内容重试复用原记录，且不会重复发布同一文件。内置平台歌词统一
     * 保存为 `cache_allowed`：允许按音乐库策略写入 Velin 私有缓存或音频相邻 sidecar，但仍禁止通过
     * 该许可直接写入音频标签。旧正文记录已由迁移直接清理，不在这里提供旧许可或旧存储兼容分支。
     */
    private function persistLyrics(
        string $songId,
        int $score,
        ScrapeGeneratedLyrics $lyrics,
        array $file,
        string $now,
    ): ?string
    {
        $locator = hash('sha256', 'metadata-sync:' . $lyrics->source . ':' . hash('sha256', $lyrics->lrc));
        /** @var stdClass|null $existing */
        $existing = Db::table('media_lyrics')->where('song_id', $songId)->where('source_kind', 'provider')
            ->where('content_sha256', $file['contentSha256'])->first(['id']);
        if ($existing instanceof stdClass) return (string) $existing->id;
        /** @var stdClass|null $existingByLocator */
        $existingByLocator = Db::table('media_lyrics')->where('song_id', $songId)->where('source_kind', 'provider')
            ->where('source_locator_digest', $locator)->first(['id', 'content_sha256']);
        if ($existingByLocator instanceof stdClass) {
            if (hash_equals((string) $existingByLocator->content_sha256, $file['contentSha256'])) {
                return (string) $existingByLocator->id;
            }
            throw new MediaMetadataConflict('歌词来源身份已经变化。');
        }
        $lyricId = (string) new Ulid();
        Db::table('media_lyrics')->insert([
            'id' => $lyricId, 'song_id' => $songId, 'source_kind' => 'provider',
            'source_locator_digest' => $locator, 'storage_kind' => $file['storageKind'],
            'storage_locator' => $file['storageLocator'], 'language' => 'und',
            'lyric_kind' => $file['parsed']->kind, 'source_format' => 'lrc',
            'content_sha256' => $file['contentSha256'],
            'priority' => 400, 'match_score' => max(0, min(100, $score)) / 100,
            'license_policy' => 'cache_allowed', 'source_size_bytes' => $file['sourceSizeBytes'],
            'source_modified_at' => $file['sourceModifiedAt'], 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $lyricId;
    }

    /**
     * 推进当前歌曲的图片子任务和歌曲封面发布，未到终态时释放租约但保持批次顺序屏障。
     *
     * 此阶段不再重复平台元数据查询，也不重新应用 scraped 字段。自动模式幂等确保专辑/艺人缺图查询，
     * 手动模式只确保确认中明确选择的 opaque 候选已进入导入队列。任何子资源 queued/running 都使当前
     * target 回到 pending；claimNext 的同 job 前序条件会阻止下一首越过它。子资源 failed/conflict 是
     * 可观察终态，不回滚已成功的元数据和歌词。
     */
    private function continueResourceStage(stdClass $target): void
    {
        $this->assertLiveAuthorization((string) $target->requested_by, (string) $target->library_id);
        $manual = property_exists($target, 'confirmation_required') && (int) $target->confirmation_required === 1;
        if ($manual && is_string($target->selection_json ?? null) && $target->selection_json !== '') {
            $selection = MetadataSyncScrapeSelection::fromJson((string) $target->selection_json);
            $this->relatedArtwork->enqueueSelections($target, $selection->relatedArtwork);
        } elseif (!$manual) {
            $this->relatedArtwork->enqueueQueries($target, true, false);
        }

        $related = $this->relatedArtwork->status((string) $target->id);
        /** @var list<stdClass> $publicationRows */
        $publicationRows = Db::table('scrape_asset_publications')
            ->where('scrape_target_id', (string) $target->id)->orderBy('resource_kind')->get([
                'resource_kind', 'storage_mode', 'status', 'error_code',
            ])->all();
        $publications = array_map(static fn (stdClass $row): array => [
            'kind' => (string) $row->resource_kind,
            'storageMode' => (string) $row->storage_mode,
            'status' => (string) $row->status,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
        ], $publicationRows);
        $publicationActive = count(array_filter($publicationRows, static fn (stdClass $row): bool =>
            in_array((string) $row->status, ['queued', 'running'], true))) > 0;
        $summary = [
            'version' => 1,
            'relatedArtwork' => array_slice($related['items'], 0, 50),
            'assetPublications' => array_slice($publications, 0, 10),
            'coordinatorErrorCode' => null,
        ];
        if ($related['active'] || $publicationActive) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update([
                    'status' => 'pending', 'phase' => 'completing_resources',
                    'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 2),
                    'resource_status_json' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => null, 'updated_at' => $now,
                ]);
            return;
        }
        $this->completeResourceStage($target, $summary);
    }

    /**
     * 手动模式先完成已确认的专辑/艺人图片导入，再应用可能改变实体证据的 scraped 元数据。
     *
     * 图片搜索冻结的是确认时实体证据；若先修改专辑名或艺人关系，图片 Worker 必须按安全规则判定证据
     * 过期。这里仅等待已经随确认事务创建的关联任务，不会自动选择新候选。失败子任务也是终态，随后
     * 仍继续歌词、歌曲封面和元数据，错误最终进入同一资源摘要。
     */
    private function waitForSelectedArtworkImports(stdClass $target): bool
    {
        $status = $this->relatedArtwork->status((string) $target->id);
        if (!$status['active']) return false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
            ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update([
                'status' => 'pending', 'phase' => 'applying',
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 2),
                'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => null, 'updated_at' => $now,
            ]);
        return true;
    }

    /**
     * 只在全部资源已到终态后完成逐曲目标并重算父任务。
     *
     * 资源失败保存在 resource_status_json，目标仍为 succeeded，表示该歌曲整体流程已经执行完而非所有
     * 可选补全项都成功。CAS 绑定当前租约，迟到 Worker 不能覆盖重试结果；终态提交不执行文件或网络
     * 操作，父任务计数与目标状态在同一短事务内发布。
     *
     * @param array<string,mixed> $summary 已脱敏且有界的资源终态。
     */
    private function completeResourceStage(stdClass $target, array $summary): void
    {
        $this->writeTransaction(function () use ($summary, $target): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update([
                    'status' => 'succeeded', 'phase' => 'completed', 'next_attempt_at' => null,
                    'resource_status_json' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                    'finished_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) return;
            $this->audit->record((string) $target->requested_by, 'metadata.sync_scrape.target.complete',
                'song', (string) $target->song_id, 'success', (string) $target->request_id, [
                    'jobId' => (string) $target->job_id,
                    'libraryId' => (string) $target->library_id,
                    'relatedArtworkCount' => count($summary['relatedArtwork'] ?? []),
                    'assetPublicationCount' => count($summary['assetPublications'] ?? []),
                    'resourceCoordinatorErrorCode' => $summary['coordinatorErrorCode'] ?? null,
                ]);
            $this->synchronizeJob((string) $target->job_id, $now);
        });
    }

    /** 无可靠候选是正常终态，不把本地目录值伪装成第三方匹配。 */
    private function finishUnmatched(stdClass $target, array $channels): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($target, $channels, $now): void {
            $this->replaceChannelResults((string) $target->id, $channels, $now);
            $update = [
                    'status' => 'unmatched', 'selected_source' => null, 'score' => null,
                    'lyrics_saved' => 0, 'artwork_status' => 'unavailable', 'error_code' => null, 'worker_id' => null,
                    'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
                ];
            if ($this->supportsUnifiedPipeline()) {
                $update['phase'] = 'completed';
                $update['next_attempt_at'] = null;
            }
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update($update);
            if ($changed !== 1) throw new MediaMetadataConflict('无匹配任务租约已经变化。');
            $this->queueRelatedEntityMetadata($target, $now);
            $this->synchronizeJob((string) $target->job_id, $now);
        });
    }

    /** @param list<MetadataProviderResult> $channels */
    private function hasUnavailableExternalChannel(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel->channelKey !== 'local' && $channel->status === 'unavailable') return true;
        }
        return false;
    }

    /**
     * 保存本轮脱敏诊断并对平台不可用执行有界重试，避免把网络故障伪装成“没有匹配”。
     *
     * attempt 在领取时递增，因此 1、2 次失败分别延迟 5、10 秒后释放为 pending；第 3 次失败终结为
     * 稳定错误码。渠道快照与目标 CAS 位于同一事务，写入失败会整体回滚；释放租约不增加父任务终态
     * 计数，后续歌曲仍受 position 屏障约束。重试复用冻结证据，不改媒体数据，也不会重复发布资源。
     * 旧 schema 没有 phase/next_attempt_at 时无法表达延迟，直接失败而不进行紧密循环。返回 true 表示
     * 歌曲阶段已经进入最终失败，调用方此时仍需独立判断专辑和艺人资料，不能把歌曲插件故障传播给其他
     * 实体插件；返回 false 表示歌曲仍将重试，暂不越过歌曲阶段。
     *
     * @param list<MetadataProviderResult> $channels
     */
    private function retryOrFailProviderUnavailable(stdClass $target, array $channels): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $attempt = property_exists($target, 'attempt') ? (int) $target->attempt : self::MAX_PROVIDER_ATTEMPTS;
        $retry = $this->supportsUnifiedPipeline() && $attempt < self::MAX_PROVIDER_ATTEMPTS;
        $this->writeTransaction(function () use ($attempt, $channels, $now, $retry, $target): void {
            $this->replaceChannelResults((string) $target->id, $channels, $now);
            $update = $retry ? [
                'status' => 'pending',
                'phase' => (string) ($target->phase ?? 'provider_query'),
                'next_attempt_at' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    time() + self::PROVIDER_RETRY_BASE_SECONDS * (2 ** max(0, $attempt - 1)),
                ),
                'error_code' => null,
                'worker_id' => null,
                'heartbeat_at' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ] : [
                'status' => 'failed',
                'selected_source' => null,
                'score' => null,
                'lyrics_saved' => 0,
                'artwork_status' => 'pending',
                'error_code' => 'METADATA_SYNC_PROVIDER_UNAVAILABLE',
                'worker_id' => null,
                'heartbeat_at' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ];
            if (!$retry && $this->supportsUnifiedPipeline()) {
                $update['phase'] = 'completed';
                $update['next_attempt_at'] = null;
            }
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update($update);
            if ($changed !== 1) throw new MediaMetadataConflict('平台查询任务租约已经变化。');
            if (!$retry) {
                $this->queueRelatedEntityMetadata($target, $now);
                $this->synchronizeJob((string) $target->job_id, $now);
            }
        });
        return !$retry;
    }

    /**
     * 用本次查询结果替换一个目标的逐平台快照。
     *
     * local 只是构造查询使用的目录证据，不属于用户要求查看的第三方平台，因此不入表。候选和值对象
     * 已经过严格 Schema 校验，仍使用其版本化 JSON 保存以便读取时再次校验；任何平台都不允许把歌词
     * 正文、外部 URL 或原始响应带入这个任务表。
     *
     * @param list<MetadataProviderResult> $channels
     */
    private function replaceChannelResults(string $targetId, array $channels, string $now): void
    {
        Db::table('metadata_sync_scrape_channel_results')->where('target_id', $targetId)->delete();
        $position = 0;
        foreach ($channels as $channel) {
            if ($channel->channelKey === 'local') continue;
            Db::table('metadata_sync_scrape_channel_results')->insert([
                'id' => (string) new Ulid(),
                'target_id' => $targetId,
                'position' => $position++,
                'channel_key' => $channel->channelKey,
                'display_name' => $channel->displayName,
                'status' => $channel->status,
                'has_lyrics' => $channel->hasLyrics ? 1 : 0,
                'has_artwork' => $channel->hasArtwork ? 1 : 0,
                'candidate_json' => $channel->candidate?->toJson(),
                'diagnostics_json' => $channel->diagnostics?->toJson(),
                'created_at' => $now,
            ]);
        }
    }

    /**
     * 未知异常、撤权或证据冲突只发布固定错误码，并可靠重算父任务计数。
     *
     * 失败可能发生在候选查询、确认、元数据事务或封面处理之外，不能统一伪装成“封面下载失败”。由于
     * persistSuccess 的数据库事务会整体回滚，失败目标没有已提交的新封面，artwork_status 保持 pending
     * 表示未完成；真正“元数据成功但封面候选下载失败”只由 prepareArtwork 返回 failed 并随 succeeded
     * 目标提交。重复失败收口仍依赖 Worker 租约条件，不能覆盖已被其他进程终结的目标。
     */
    private function finishFailure(stdClass $target, string $errorCode): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeTransaction(function () use ($target, $errorCode, $now): void {
            $update = [
                    'status' => 'failed', 'selected_source' => null, 'score' => null,
                    'lyrics_saved' => 0, 'artwork_status' => 'pending', 'error_code' => $errorCode, 'worker_id' => null,
                    'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
                ];
            if ($this->supportsUnifiedPipeline()) {
                $update['phase'] = 'completed';
                $update['next_attempt_at'] = null;
            }
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('status', 'running')->where('worker_id', (string) $target->worker_id)->update($update);
            if ($changed !== 1) throw new MediaMetadataConflict('失败任务租约已经变化。');
            $this->synchronizeJob((string) $target->job_id, $now);
        });
    }

    /** 只从目标终态重建父计数，避免进程退出或重复执行造成自增漂移。 */
    private function synchronizeJob(string $jobId, string $now): void
    {
        /** @var stdClass $aggregate */
        $aggregate = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->selectRaw(
            "COUNT(*) AS target_count, "
            . "SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_count, "
            . "SUM(CASE WHEN status = 'unmatched' THEN 1 ELSE 0 END) AS unmatched_count, "
            . "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count",
        )->first();
        $counts = [
            'succeeded' => (int) $aggregate->succeeded_count,
            'unmatched' => (int) $aggregate->unmatched_count,
            'failed' => (int) $aggregate->failed_count,
        ];
        $processed = array_sum($counts);
        $targetCount = (int) $aggregate->target_count;
        $terminal = $processed === $targetCount;
        $status = !$terminal ? 'running'
            : ($counts['succeeded'] === $targetCount ? 'succeeded'
                : ($counts['failed'] === $targetCount ? 'failed' : 'partial'));
        Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->update([
            'status' => $status, 'processed_count' => $processed,
            'succeeded_count' => $counts['succeeded'], 'unmatched_count' => $counts['unmatched'],
            'failed_count' => $counts['failed'], 'version' => Db::raw('version + 1'),
            'finished_at' => $terminal ? $now : null, 'updated_at' => $now,
        ]);
    }

    /** 统一状态列必须整体存在；旧测试 schema 和滚动部署节点继续按历史单阶段语义收口。 */
    private function supportsUnifiedPipeline(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('metadata_sync_scrape_targets', 'phase')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'next_attempt_at')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'resource_status_json');
    }

    /** Worker 权限永远从当前账号、角色和库 grant 重建，创建时快照不能授权执行。 */
    private function assertLiveAuthorization(string $userId, string $libraryId): void
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)->where('status', 'active')
            ->whereNull('deleted_at')->first(['id', 'is_super_admin']);
        if (!$user instanceof stdClass) throw new MediaMetadataNotFound('任务账号不可用。');
        $super = (int) $user->is_super_admin === 1;
        $capabilities = $this->capabilities->resolve($userId, $super);
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) {
            throw new MediaMetadataNotFound('任务权限已撤销。');
        }
        foreach ($this->libraries->resolve($userId, $super) as $library) {
            if ($library['id'] === $libraryId && $library['accessLevel'] === 'manage') return;
        }
        throw new MediaMetadataNotFound('音乐库授权已撤销。');
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function candidateMetadata(array $evidence): array
    {
        return [
            'title' => $evidence['title'], 'artists' => $evidence['artists'],
            'albumArtists' => $evidence['albumArtists'], 'albumTitle' => $evidence['albumTitle'],
            'trackNumber' => $evidence['trackNumber'], 'trackTotal' => $evidence['trackTotal'],
            'discNumber' => $evidence['discNumber'], 'discTotal' => $evidence['discTotal'],
            'releaseDate' => $evidence['releaseDate'],
            'releaseYear' => is_string($evidence['releaseDate']) && preg_match('/^(\d{4})/', $evidence['releaseDate'], $match) === 1
                ? (int) $match[1] : null,
            'genres' => $evidence['genres'], 'composer' => $evidence['composer'], 'isrc' => $evidence['isrc'],
            'musicbrainzTrackId' => $evidence['musicbrainzTrackId'], 'musicbrainzArtistId' => null,
            'musicbrainzReleaseId' => $evidence['musicbrainzReleaseId'],
            'musicbrainzReleaseGroupId' => $evidence['musicbrainzReleaseGroupId'],
            'durationMs' => $evidence['durationMs'],
        ];
    }

    /**
     * 把冻结的两种输入模式交给插件。核心只复制名称片段，不解析文件名、目录或生成查询变体。
     * 插件不可用时由 PluginMetadataEnrichmentProvider 只返回冻结的本地文件元数据并标记 unavailable；
     * 核心不会访问固定音乐平台。调用发生在事务外，重试使用同一冻结证据。
     *
     * @param array<string,mixed> $frozen
     * @param null|list<string> $selectedSources 人工确认后实际采用的渠道，null 表示首次搜索全部启用渠道。
     */
    private function enrichFromFrozenEvidence(
        array $frozen,
        string $targetId,
        ?array $selectedSources = null,
    ): MetadataEnrichmentResult
    {
        $metadata = $this->candidateMetadata($frozen);
        $context = is_array($frozen['filenameContext'] ?? null) ? $frozen['filenameContext'] : null;
        $filenameMode = ($frozen['scrapeInputMode'] ?? null) === 'filename' && $context !== null;
        if ($filenameMode) {
            $metadata['scrapeInputMode'] = 'filename';
            $metadata['filenameContext'] = $context;
        }
        $local = new ScrapeMetadataCandidate($metadata, 0, 'catalog', ['catalog_snapshot']);
        $keywords = $this->keywords->generate((string) $frozen['title']);
        return $this->provider->enrich($local, array_slice($keywords, 0, 12),
            'metadata-sync:' . $targetId, $selectedSources);
    }

    /**
     * 从完整渠道快照中提取该渠道真实提供的稀疏字段。
     *
     * `ScrapeMetadataCandidate` 为了审核对照会在平台值之下保留本地字段，但写入 scraped 来源层时只能
     * 接受 `music_source_field_*` 证据明确声明的键。这样旧标签不会仅因重新刮削就被改记为第三方来源，
     * 平台缺失字段也不会清空现值；本方法只转换内存对象，没有数据库或文件副作用。
     *
     * @return array<string,mixed>
     */
    private function providedCandidateMetadata(ScrapeMetadataCandidate $candidate): array
    {
        $fields = [
            'title' => 'title', 'artists' => 'artists', 'albumArtists' => 'album_artists',
            'albumTitle' => 'album_title', 'trackNumber' => 'track_number', 'trackTotal' => 'track_total',
            'discNumber' => 'disc_number', 'discTotal' => 'disc_total', 'releaseDate' => 'release_date',
            'genres' => 'genres', 'isrc' => 'isrc',
            'musicbrainzTrackId' => 'musicbrainz_track_id',
            'musicbrainzArtistId' => 'musicbrainz_artist_id',
            'musicbrainzReleaseId' => 'musicbrainz_release_id',
            'musicbrainzReleaseGroupId' => 'musicbrainz_release_group_id',
        ];
        $provided = [];
        foreach ($fields as $field => $evidenceKey) {
            if (in_array('music_source_field_' . $evidenceKey, $candidate->evidence, true)
                && array_key_exists($field, $candidate->metadata)) {
                $provided[$field] = $candidate->metadata[$field];
            }
        }
        return $provided;
    }

    /**
     * 按管理员逐字段选择合成一份稀疏可应用候选。
     *
     * 基础对象只用于满足候选 Schema 和保留本地技术事实；每个真正写入 scraped 的字段都必须来自选择
     * 对应渠道并携带原候选的 `music_source_field_*` 证据。渠道候选已通过聚合摘要复验，仍逐字段检查
     * 提供能力，防止协议升级或损坏快照把本地回退当成第三方值。方法不访问数据库或文件。
     *
     * @param array<string,mixed> $frozen 创建任务时的路径无关证据。
     * @param array<string,ScrapeMetadataCandidate> $candidates 当前重新查询得到的 matched 候选。
     */
    private function selectedCandidate(
        array $frozen,
        MetadataSyncScrapeSelection $selection,
        array $candidates,
    ): ScrapeMetadataCandidate {
        $metadata = $this->candidateMetadata($frozen);
        $evidence = [];
        $confidence = 0;
        foreach ($selection->metadata as $field => $source) {
            $candidate = $candidates[$source] ?? null;
            if (!$candidate instanceof ScrapeMetadataCandidate
                || !MetadataSyncScrapeSelection::candidateProvides($candidate, $field)) {
                throw new MediaMetadataConflict('所选字段候选已经变化。');
            }
            $metadata[$field] = $candidate->metadata[$field];
            $evidence[] = 'music_source_field_' . MetadataSyncScrapeSelection::METADATA_FIELDS[$field];
            $evidence[] = 'manual_source_' . $field . '_' . $source;
            $confidence = max($confidence, $candidate->confidence);
        }
        foreach ($selection->sources() as $source) {
            if (isset($candidates[$source])) $confidence = max($confidence, $candidates[$source]->confidence);
        }
        return new ScrapeMetadataCandidate(
            $metadata,
            $confidence,
            $selection->primarySource(),
            array_values(array_unique($evidence)),
        );
    }

    /**
     * 以创建时完整冻结 JSON 摘要比较当前规范证据。
     *
     * providerQueryRequired 是创建时资源事实形成的不可变执行计划；复验把原布尔值加回当前媒体事实，
     * 保证摘要覆盖整份 evidence_json，同时避免资源查询计划被误当成扫描字段自行重算。
     */
    private function matchesEvidence(stdClass $target, array $current): bool
    {
        $frozen = json_decode((string) $target->evidence_json, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($frozen) && array_key_exists('providerQueryRequired', $frozen)) {
            $current['providerQueryRequired'] = (bool) $frozen['providerQueryRequired'];
        }
        $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_equals((string) $target->evidence_sha256, hash('sha256', $json));
    }

    /**
     * 在 SQLite 写入闸门内执行一个短事务。
     *
     * 外部平台查询、歌词/封面下载和候选解析都发生在闸门外；只有状态快照、元数据和资源队列提交
     * 排队。这样扫描和刮削两个 Worker 仍可进行非数据库工作，但它们不会同时提交 SQLite 写事务。
     * 闸门或数据库异常原样抛出，由上层已有的瞬时重试和固定错误码收口逻辑处理。
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
