<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Auth\AuthorizationDenied;
use app\application\Library\LibraryAccessResolver;
use app\application\Scrape\ScrapeMetadataCandidate;
use app\application\Scrape\ScrapeProviderDiagnostics;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/** 创建、确认并查询库内单曲重新刮削与受控自动批量刮削任务。 */
final class MetadataSyncScrapeService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ScrapeAssetPublicationService $assetPublications = new ScrapeAssetPublicationService(),
        private readonly LibraryAccessResolver $libraryAccess = new LibraryAccessResolver(),
        private readonly SongScrapeRelatedArtworkService $relatedArtwork = new SongScrapeRelatedArtworkService(),
        private readonly SongMetadataScrapePolicy $songScrapePolicy = new SongMetadataScrapePolicy(),
    ) {}

    /**
     * 冻结明确选择歌曲的无路径查询证据并排队；新 schema 先进入候选搜索阶段。
     *
     * `edit_metadata` 由 Controller 校验；这里额外要求 `run_scrape`，并要求每首歌所属库当前都是
     * manage。任何一个 ID 无效、重复、越权或不存在都会让整批失败，不能悄悄只创建部分目标。
     *
     * @param array<string,mixed> $actor 当前认证身份
     * @param mixed $songIds 原始 JSON songIds
     * @return array<string,mixed>
     */
    public function create(array $actor, mixed $songIds, string $requestId): array
    {
        return $this->createJob($actor, $songIds, $requestId, true, true);
    }

    /**
     * 为扫描或本机管理命令创建无需逐首人工确认的自动批次。
     *
     * 该入口不暴露给浏览器，只允许已经取得 `run_scrape` 且对全部目标库拥有 manage 权限的受控调用方
     * 使用。它只跳过候选确认，不放宽字段锁、证据复验、派生资源非覆盖、音频文件只读或每批 50 首的
     * 不变量。普通增量批次会根据歌曲标签、歌词和歌曲自身图片冻结是否调用歌曲插件；全量刷新通过
     * `$forceProviderQuery` 强制重新查询，但仍保留现有业务事实直至新结果成功。任务创建仍是单批原子
     * 事务；Worker 失败时按歌曲记录终态，不回滚其他已经成功的歌曲。
     *
     * @param array<string,mixed> $actor 当前认证身份
     * @param mixed $songIds 原始歌曲 ID 列表
     * @param bool $forceProviderQuery 是否忽略歌曲完整度并强制调用歌曲插件，仅供全量刷新使用
     * @return array<string,mixed>
     */
    public function createAutomaticBatch(
        array $actor,
        mixed $songIds,
        string $requestId,
        bool $forceProviderQuery = false,
    ): array
    {
        return $this->createJob($actor, $songIds, $requestId, false, $forceProviderQuery);
    }

    /**
     * 冻结一批歌曲证据并按调用边界决定是否要求候选确认。
     *
     * `$confirmationRequired` 只影响 Worker 在可靠候选产生后是否暂停；`$forceProviderQuery` 与歌曲完整度
     * 策略形成 `providerQueryRequired` 执行计划，并与媒体证据一起写入 JSON 及 SHA-256。Worker 执行前
     * 复验完整摘要，同时沿用创建时的计划布尔值，避免歌词或图片竞态改变已冻结意图。授权、目标完整性
     * 和事务行为在两种确认模式下完全相同。旧 schema 没有确认列时继续保持迁移前的自动执行语义，便于
     * 滚动部署；创建事务失败时任务、target 与审计整体回滚。
     *
     * @param array<string,mixed> $actor 当前认证身份
     * @param mixed $songIds 原始歌曲 ID 列表
     * @return array<string,mixed>
     */
    private function createJob(
        array $actor,
        mixed $songIds,
        string $requestId,
        bool $confirmationRequired,
        bool $forceProviderQuery,
    ): array
    {
        $this->requireRunScrape($actor);
        if (!is_array($songIds) || !array_is_list($songIds) || count($songIds) < 1 || count($songIds) > 50) {
            throw new MediaMetadataInvalid('同步刮削必须明确选择 1 到 50 首歌曲。');
        }
        $normalized = [];
        foreach ($songIds as $songId) {
            if (!is_string($songId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1
                || isset($normalized[$songId])) throw new MediaMetadataInvalid('歌曲选择包含无效或重复标识。');
            $normalized[$songId] = true;
        }
        $manageable = $this->manageableLibraryIds($actor);
        if ($manageable === []) throw new AuthorizationDenied('没有可管理的音乐库。');

        return Db::transaction(function () use (
            $actor,
            $normalized,
            $manageable,
            $requestId,
            $confirmationRequired,
            $forceProviderQuery,
        ): array {
            $evidence = [];
            foreach (array_keys($normalized) as $songId) {
                $facts = $this->currentEvidence($songId);
                if ($facts === null || !isset($manageable[$facts['libraryId']])) {
                    throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
                }
                // 执行计划随 target 冻结；复验会把同一布尔值加回当前媒体事实后再比较完整 JSON 摘要。
                $facts['providerQueryRequired'] = $confirmationRequired || $forceProviderQuery
                    || $this->songScrapePolicy->shouldQuery($facts);
                $evidence[] = $facts;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $jobId = (string) new Ulid();
            $jobRow = [
                'id' => $jobId, 'requested_by' => (string) $actor['id'], 'request_id' => $requestId,
                'status' => 'queued', 'target_count' => count($evidence), 'processed_count' => 0,
                'succeeded_count' => 0, 'unmatched_count' => 0, 'failed_count' => 0, 'version' => 1,
                'created_at' => $now, 'started_at' => null, 'finished_at' => null, 'updated_at' => $now,
            ];
            if ($this->supportsConfirmation()) $jobRow['confirmation_required'] = $confirmationRequired ? 1 : 0;
            Db::table('metadata_sync_scrape_jobs')->insert($jobRow);
            foreach ($evidence as $position => $facts) {
                $json = $this->encode($facts);
                $targetRow = [
                    'id' => (string) new Ulid(), 'job_id' => $jobId, 'position' => $position,
                    'song_id' => $facts['songId'], 'library_id' => $facts['libraryId'],
                    'evidence_json' => $json, 'evidence_sha256' => hash('sha256', $json),
                    'status' => 'pending', 'selected_source' => null, 'score' => null,
                    'lyrics_saved' => 0, 'artwork_status' => 'pending', 'error_code' => null, 'attempt' => 0,
                    'worker_id' => null, 'heartbeat_at' => null, 'created_at' => $now,
                    'started_at' => null, 'finished_at' => null, 'updated_at' => $now,
                ];
                if ($this->supportsConfirmation()) {
                    $targetRow['awaiting_confirmation'] = 0;
                    $targetRow['selected_candidate_sha256'] = null;
                    $targetRow['selection_json'] = null;
                }
                if ($this->supportsUnifiedPipeline()) {
                    $targetRow['phase'] = 'provider_query';
                    $targetRow['next_attempt_at'] = $now;
                    $targetRow['resource_status_json'] = null;
                }
                Db::table('metadata_sync_scrape_targets')->insert($targetRow);
            }
            $this->audit->record((string) $actor['id'], 'metadata.sync_scrape.create',
                'metadata_sync_scrape_job', $jobId, 'success', $requestId, ['targetCount' => count($evidence)]);
            return $this->project($jobId, $actor);
        });
    }

    /** 查询任务时重新应用发起身份和全部目标库的实时 manage 授权。 */
    public function show(array $actor, string $jobId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) throw new MediaMetadataInvalid('任务标识无效。');
        $this->requireRunScrape($actor);
        return $this->project($jobId, $actor);
    }

    /**
     * 确认单曲候选并重新排队应用。
     *
     * 只接受本任务已冻结的 matched 渠道。候选摘要、任务版本、发起身份、实时库授权和歌曲证据都在
     * 短事务内复验；重复提交或候选已变化返回冲突，不会创建第二个应用任务。HTTP 进程不访问平台，
     * Worker 重新查询后必须得到同一标准化候选摘要才允许写 scraped 层。
     */
    public function confirm(array $actor, string $jobId, mixed $selections, mixed $expectedVersion, string $requestId): array
    {
        $this->requireRunScrape($actor);
        if (!$this->supportsConfirmation() || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1
            || !is_int($expectedVersion) || $expectedVersion < 1) {
            throw new MediaMetadataInvalid('候选确认参数无效。');
        }
        $selection = MetadataSyncScrapeSelection::fromPayload($selections);
        return Db::transaction(function () use ($actor, $jobId, $selection, $expectedVersion, $requestId): array {
            /** @var stdClass|null $job */
            $job = Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)
                ->where('requested_by', (string) $actor['id'])->where('confirmation_required', 1)->first();
            if (!$job instanceof stdClass) throw new MediaMetadataNotFound('重新刮削任务不存在。');
            if ((int) $job->version !== $expectedVersion) throw new MediaMetadataConflict('任务版本已经变化。');
            /** @var stdClass|null $target */
            $target = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->first();
            if (!$target instanceof stdClass || (int) $target->awaiting_confirmation !== 1) {
                throw new MediaMetadataConflict('任务不再等待确认。');
            }
            $manageable = $this->manageableLibraryIds($actor);
            if (!isset($manageable[(string) $target->library_id])) throw new AuthorizationDenied('音乐库授权已变化。');
            $current = $target->song_id === null ? null : $this->currentEvidence((string) $target->song_id);
            if ($current === null) throw new MediaMetadataConflict('歌曲证据已经变化。');
            $frozen = $this->decodeFrozenEvidence((string) $target->evidence_json);
            if (array_key_exists('providerQueryRequired', $frozen)) {
                $current['providerQueryRequired'] = (bool) $frozen['providerQueryRequired'];
            }
            if (!hash_equals((string) $target->evidence_sha256,
                hash('sha256', $this->encode($current)))) throw new MediaMetadataConflict('歌曲证据已经变化。');
            /** @var list<stdClass> $rows */
            $rows = Db::table('metadata_sync_scrape_channel_results')->where('target_id', (string) $target->id)
                ->whereIn('channel_key', $selection->sources())->where('status', 'matched')
                ->whereNotNull('candidate_json')->get()->all();
            $candidates = [];
            $availability = [];
            foreach ($rows as $row) {
                $source = (string) $row->channel_key;
                $candidates[$source] = ScrapeMetadataCandidate::fromJson((string) $row->candidate_json);
                $availability[$source] = [
                    'lyrics' => property_exists($row, 'has_lyrics') && (int) $row->has_lyrics === 1,
                    'artwork' => property_exists($row, 'has_artwork') && (int) $row->has_artwork === 1,
                ];
            }
            foreach ($selection->metadata as $field => $source) {
                $candidate = $candidates[$source] ?? null;
                if (!$candidate instanceof ScrapeMetadataCandidate
                    || !MetadataSyncScrapeSelection::candidateProvides($candidate, $field)) {
                    throw new MediaMetadataInvalid('所选渠道没有提供该元数据字段。');
                }
            }
            if ($selection->lyrics !== null && !($availability[$selection->lyrics]['lyrics'] ?? false)) {
                throw new MediaMetadataInvalid('所选渠道没有可用歌词。');
            }
            if ($selection->artwork !== null && !($availability[$selection->artwork]['artwork'] ?? false)) {
                throw new MediaMetadataInvalid('所选渠道没有可用歌曲封面。');
            }
            $this->relatedArtwork->validateSelections((string) $target->id, $selection->relatedArtwork);
            // 图片导入必须在 scraped 字段改变实体证据前入队；导入 Worker 仍会按搜索时冻结证据和当前
            // 授权复验。目标与导入同在当前确认事务，后续失败不会留下“已确认但没有子任务”的半状态。
            $target->requested_by = (string) $actor['id'];
            $this->relatedArtwork->enqueueSelections($target, $selection->relatedArtwork, true);
            $candidateDigest = $selection->candidateDigest($candidates);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)
                ->where('awaiting_confirmation', 1)->update([
                    'status' => 'pending', 'awaiting_confirmation' => 0,
                    'selected_source' => $selection->primarySource(),
                    'selected_candidate_sha256' => $candidateDigest,
                    'selection_json' => $selection->toJson(),
                    'score' => null, 'error_code' => null, 'worker_id' => null, 'heartbeat_at' => null,
                    'started_at' => null, 'finished_at' => null, 'updated_at' => $now,
                ]);
            if ($this->supportsUnifiedPipeline()) {
                Db::table('metadata_sync_scrape_targets')->where('id', (string) $target->id)->update([
                    'phase' => 'applying', 'next_attempt_at' => $now, 'resource_status_json' => null,
                ]);
            }
            if ($changed !== 1) throw new MediaMetadataConflict('候选已被其他请求确认。');
            $jobChanged = Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->where('version', $expectedVersion)->update([
                'status' => 'queued', 'processed_count' => 0, 'succeeded_count' => 0, 'unmatched_count' => 0,
                'failed_count' => 0, 'version' => Db::raw('version + 1'), 'started_at' => null,
                'finished_at' => null, 'updated_at' => $now,
            ]);
            if ($jobChanged !== 1) throw new MediaMetadataConflict('任务版本已经变化。');
            $this->audit->record((string) $actor['id'], 'metadata.rescrape.confirm',
                'metadata_sync_scrape_job', $jobId, 'success', $requestId, [
                    'metadataFieldCount' => count($selection->metadata),
                    'sources' => $selection->sources(),
                    'lyricsSelected' => $selection->lyrics !== null,
                    'artworkSelected' => $selection->artwork !== null,
                    'relatedArtworkSelected' => count($selection->relatedArtwork),
                ]);
            return $this->project($jobId, $actor);
        });
    }

    /**
     * 忽略当前管理员仍有权处理的全部待确认逐曲刮削任务。
     *
     * 本命令只清除 `awaiting_confirmation`，目标继续保留为 `unmatched`，渠道候选快照、诊断和歌曲证据
     * 都作为历史保留；它不会选择候选、写入元数据、歌词或封面，也不会重新排队 Worker。事务开始后会
     * 从当前账号与库授权重新解析 manage 范围，并且只处理本人发起且全部目标库仍可管理的父任务；包含
     * 任一失权目标的任务整体跳过，不能产生部分授权写入。
     *
     * 每个目标使用 `awaiting_confirmation = 1` 条件更新，因此与单项确认并发时只有一个命令能改变该
     * 目标。父任务计数由目标终态重新计算，对应未读提醒在同一短事务标为已读。重复调用返回零计数，
     * 不重复写审计，也不会删除候选历史。
     *
     * @param array<string,mixed> $actor 当前认证身份
     * @return array{ignoredTaskCount:int,ignoredTargetCount:int}
     */
    public function ignoreAllAwaitingConfirmations(array $actor, string $requestId): array
    {
        $this->requireRunScrape($actor);
        if (!$this->supportsConfirmation()) {
            return ['ignoredTaskCount' => 0, 'ignoredTargetCount' => 0];
        }

        return Db::transaction(function () use ($actor, $requestId): array {
            $actorId = (string) ($actor['id'] ?? '');
            $isSuperAdmin = ($actor['isSuperAdmin'] ?? false) === true;
            $manageable = [];
            foreach ($this->libraryAccess->resolve($actorId, $isSuperAdmin) as $library) {
                if (($library['accessLevel'] ?? null) === 'manage') $manageable[] = (string) $library['id'];
            }

            $query = Db::table('metadata_sync_scrape_jobs as jobs')
                ->where('jobs.requested_by', $actorId)
                ->where('jobs.confirmation_required', 1)
                ->whereExists(function ($targets): void {
                    $targets->selectRaw('1')->from('metadata_sync_scrape_targets as awaiting_targets')
                        ->whereColumn('awaiting_targets.job_id', 'jobs.id')
                        ->where('awaiting_targets.awaiting_confirmation', 1);
                });
            if (!$isSuperAdmin) {
                $query->whereNotExists(function ($targets) use ($manageable): void {
                    $targets->selectRaw('1')->from('metadata_sync_scrape_targets as denied_targets')
                        ->whereColumn('denied_targets.job_id', 'jobs.id')
                        ->when($manageable !== [],
                            fn ($scope) => $scope->whereNotIn('denied_targets.library_id', $manageable),
                            fn ($scope) => $scope->whereRaw('1 = 1'));
                });
            }

            /** @var list<string> $jobIds */
            $jobIds = $query->orderBy('jobs.id')->pluck('jobs.id')->map('strval')->all();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $ignoredJobIds = [];
            $ignoredTargetCount = 0;
            foreach ($jobIds as $jobId) {
                $changed = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
                    ->where('awaiting_confirmation', 1)->update([
                        'awaiting_confirmation' => 0,
                        'updated_at' => $now,
                    ]);
                if ($changed < 1) continue;
                $ignoredJobIds[] = $jobId;
                $ignoredTargetCount += $changed;
                $this->synchronizeIgnoredJob($jobId, $now);
            }

            if ($ignoredJobIds !== [] && Db::connection()->getSchemaBuilder()->hasTable('user_notifications')) {
                foreach (array_chunk($ignoredJobIds, 500) as $chunk) {
                    Db::table('user_notifications')->where('user_id', $actorId)
                        ->where('kind', 'scrape.awaiting_confirmation')
                        ->where('object_type', 'metadata_sync_scrape_job')
                        ->whereIn('object_id', $chunk)->whereNull('read_at')
                        ->update(['read_at' => $now, 'updated_at' => $now]);
                }
            }
            if ($ignoredJobIds !== []) {
                $this->audit->record($actorId, 'metadata.rescrape.ignore_all',
                    'metadata_sync_scrape_confirmation', null, 'success', $requestId, [
                        'ignoredTaskCount' => count($ignoredJobIds),
                        'ignoredTargetCount' => $ignoredTargetCount,
                    ]);
            }

            return [
                'ignoredTaskCount' => count($ignoredJobIds),
                'ignoredTargetCount' => $ignoredTargetCount,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function project(string $jobId, array $actor): array
    {
        /** @var stdClass|null $job */
        $job = Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)
            ->where('requested_by', (string) $actor['id'])->first();
        if (!$job instanceof stdClass) throw new MediaMetadataNotFound('同步刮削任务不存在。');
        $manageable = $this->manageableLibraryIds($actor);
        /** @var list<stdClass> $targets */
        $targets = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
            ->orderBy('position')->get()->all();
        foreach ($targets as $target) {
            if (!isset($manageable[(string) $target->library_id])) throw new AuthorizationDenied('任务音乐库授权已变化。');
        }
        $channelRows = $targets === [] ? [] : Db::table('metadata_sync_scrape_channel_results')
            ->whereIn('target_id', array_map(static fn (stdClass $target): string => (string) $target->id, $targets))
            ->orderBy('target_id')->orderBy('position')->get()->all();
        $channelsByTarget = [];
        foreach ($channelRows as $channelRow) {
            $targetId = (string) $channelRow->target_id;
            $channelsByTarget[$targetId][] = $this->projectChannelResult($channelRow);
        }
        $publicationRows = $this->assetPublications->projectForTargets(
            array_map(static fn (stdClass $target): string => (string) $target->id, $targets),
        );
        $publicationsByTarget = [];
        foreach ($publicationRows as $publication) {
            $targetId = (string) $publication['targetId'];
            unset($publication['targetId']);
            $publicationsByTarget[$targetId][] = $publication;
        }
        $projectedStatus = (string) $job->status;
        $publicationActive = false;
        $publicationFailed = false;
        foreach ($publicationRows as $publication) {
            $publicationActive = $publicationActive
                || in_array($publication['status'], ['queued', 'running'], true);
            $publicationFailed = $publicationFailed
                || in_array($publication['status'], ['conflict', 'failed'], true);
        }
        if ($publicationActive && in_array($projectedStatus, ['succeeded', 'partial'], true)) {
            $projectedStatus = 'running';
        } elseif ($publicationFailed && $projectedStatus === 'succeeded') {
            $projectedStatus = 'partial';
        }
        $confirmationRequired = property_exists($job, 'confirmation_required') && (int) $job->confirmation_required === 1;
        $awaitingConfirmation = $confirmationRequired && count($targets) === 1
            && property_exists($targets[0], 'awaiting_confirmation') && (int) $targets[0]->awaiting_confirmation === 1;
        return [
            'id' => (string) $job->id, 'status' => $projectedStatus,
            'targetCount' => (int) $job->target_count, 'processedCount' => (int) $job->processed_count,
            'succeededCount' => (int) $job->succeeded_count, 'unmatchedCount' => (int) $job->unmatched_count,
            'failedCount' => (int) $job->failed_count, 'version' => (int) $job->version,
            'createdAt' => (string) $job->created_at,
            'startedAt' => $job->started_at === null ? null : (string) $job->started_at,
            'finishedAt' => $job->finished_at === null ? null : (string) $job->finished_at,
            'workflowStage' => $awaitingConfirmation ? 'awaiting_confirmation'
                : (in_array((string) $job->status, ['queued', 'running'], true)
                    ? ((property_exists($targets[0], 'phase')
                        ? (string) $targets[0]->phase === 'provider_query'
                        : (string) ($targets[0]->selected_source ?? '') === '')
                        ? 'searching' : 'applying') : 'completed'),
            'targets' => array_map(function (stdClass $target) use ($actor, $channelsByTarget, $publicationsByTarget): array {
                $facts = $this->decodeFrozenEvidence((string) $target->evidence_json);
                $entities = $this->relatedEntities((string) ($target->song_id ?? $facts['songId']));
                $selection = null;
                try {
                    if (is_string($target->selection_json ?? null) && $target->selection_json !== '') {
                        $selected = MetadataSyncScrapeSelection::fromJson($target->selection_json);
                        $selection = ['metadata' => $selected->metadata, 'lyrics' => $selected->lyrics,
                            'artwork' => $selected->artwork];
                        if ($this->supportsUnifiedPipeline()) {
                            $selection['relatedArtwork'] = $selected->relatedArtwork;
                        }
                    }
                } catch (Throwable) {
                    $selection = null;
                }
                return [
                    'songId' => $target->song_id === null ? $facts['songId'] : (string) $target->song_id,
                    'libraryId' => (string) $target->library_id,
                    'title' => $facts['title'],
                    'artists' => array_map(static fn (array $artist): string => $artist['name'], $entities['artists']),
                    'artistEntities' => $entities['artists'], 'album' => $entities['album'],
                    'status' => (string) $target->status,
                    'phase' => property_exists($target, 'phase') ? (string) $target->phase : 'completed',
                    'selectedSource' => $target->selected_source === null ? null : (string) $target->selected_source,
                    'selections' => $selection,
                    'score' => $target->score === null ? null : (int) $target->score,
                    'lyricsSaved' => (int) $target->lyrics_saved === 1,
                    'artworkStatus' => (string) $target->artwork_status,
                    'assetStorageMode' => $facts['scrapeStorageMode'] ?? 'managed_cache',
                    'assetPublications' => $publicationsByTarget[(string) $target->id] ?? [],
                    'errorCode' => $target->error_code === null ? null : (string) $target->error_code,
                    'platformResults' => $channelsByTarget[(string) $target->id] ?? [],
                    'artworkQueries' => $this->relatedArtwork->project((string) $target->id, $actor),
                    'resourceStatus' => $this->decodeResourceStatus($target->resource_status_json ?? null),
                    // 完成详情只以业务表中的有效值和当前资源选择为事实；候选快照仍供确认流程使用，
                    // 但管理任务详情应通过 persisted 读取真正入库的数据，避免把未采用候选误显示成结果。
                    'persisted' => $this->projectPersistedFacts(
                        $target->song_id === null ? (string) $facts['songId'] : (string) $target->song_id,
                        $channelsByTarget[(string) $target->id] ?? [],
                    ),
                ];
            }, $targets),
            'policy' => ['databaseOnly' => false, 'writesAudioFile' => false, 'writesDerivedFiles' => true,
                'maximumTargets' => 50,
                'importsSongArtwork' => true, 'preservesArtworkSelection' => true,
                'derivedAssetPublication' => 'configured_per_library'],
        ];
    }

    /**
     * 把 Worker 保存的平台值对象重新校验后投影给管理页面。
     *
     * 数据库内容不能因为“由本程序写入”就直接信任：候选与诊断都通过各自的版本化解析器复验，损坏的
     * 单个平台行只降级成无候选/无诊断，不影响任务本身或其他平台结果。返回字段是固定白名单，不会把
     * 未来追加到内部 metadata 的路径、URL 或平台私有字段自动透传到浏览器。
     *
     * @return array<string,mixed>
     */
    private function projectChannelResult(stdClass $row): array
    {
        $candidate = null;
        $diagnostics = null;
        try {
            if (is_string($row->candidate_json) && $row->candidate_json !== '') {
                $candidate = ScrapeMetadataCandidate::fromJson($row->candidate_json);
            }
        } catch (Throwable) {
            $candidate = null;
        }
        try {
            if (is_string($row->diagnostics_json) && $row->diagnostics_json !== '') {
                $diagnostics = ScrapeProviderDiagnostics::fromJson($row->diagnostics_json)->toArray();
            }
        } catch (Throwable) {
            $diagnostics = null;
        }
        $metadata = $candidate?->metadata;
        return [
            'channelKey' => (string) $row->channel_key,
            'displayName' => (string) $row->display_name,
            'status' => (string) $row->status,
            'confidence' => $candidate?->confidence,
            'hasLyrics' => property_exists($row, 'has_lyrics') && (int) $row->has_lyrics === 1,
            'hasArtwork' => property_exists($row, 'has_artwork') && (int) $row->has_artwork === 1,
            'providedFields' => $candidate === null ? [] : array_values(array_filter(
                array_keys(MetadataSyncScrapeSelection::METADATA_FIELDS),
                static fn (string $field): bool => MetadataSyncScrapeSelection::candidateProvides($candidate, $field),
            )),
            'metadata' => $metadata === null ? null : [
                'title' => (string) $metadata['title'],
                'artists' => array_values($metadata['artists']),
                'albumArtists' => array_values($metadata['albumArtists']),
                'albumTitle' => (string) $metadata['albumTitle'],
                'releaseDate' => is_string($metadata['releaseDate'] ?? null) ? $metadata['releaseDate'] : null,
                'trackNumber' => is_int($metadata['trackNumber'] ?? null) ? $metadata['trackNumber'] : null,
                'trackTotal' => is_int($metadata['trackTotal'] ?? null) ? $metadata['trackTotal'] : null,
                'discNumber' => is_int($metadata['discNumber'] ?? null) ? $metadata['discNumber'] : null,
                'discTotal' => is_int($metadata['discTotal'] ?? null) ? $metadata['discTotal'] : null,
                'genres' => is_array($metadata['genres'] ?? null) ? array_values($metadata['genres']) : [],
                'isrc' => is_string($metadata['isrc'] ?? null) ? $metadata['isrc'] : null,
                'musicbrainzTrackId' => is_string($metadata['musicbrainzTrackId'] ?? null)
                    ? $metadata['musicbrainzTrackId'] : null,
                'musicbrainzArtistId' => is_string($metadata['musicbrainzArtistId'] ?? null)
                    ? $metadata['musicbrainzArtistId'] : null,
                'musicbrainzReleaseId' => is_string($metadata['musicbrainzReleaseId'] ?? null)
                    ? $metadata['musicbrainzReleaseId'] : null,
                'musicbrainzReleaseGroupId' => is_string($metadata['musicbrainzReleaseGroupId'] ?? null)
                    ? $metadata['musicbrainzReleaseGroupId'] : null,
                'durationMs' => is_int($metadata['durationMs'] ?? null) ? $metadata['durationMs'] : null,
            ],
            'evidence' => $candidate?->evidence ?? [],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * 投影主程序刮削详情真正已经落库的歌曲、专辑、歌词和封面。
     *
     * effective_value_json 是页面显示的唯一值，effective_source 只负责说明它来自文件原始标签、元数据
     * 插件还是手工覆盖；空值不进入投影。插件弹窗只返回对应有效字段的 scraped_value_json，因此不会把
     * “插件返回但未采用”的候选伪装成最终结果，也不会返回原始响应、远端 URL、歌词正文、图片字节、路径
     * 或平台私有 ID。此方法只读数据库；旧滚动升级期间缺少实体状态表时失败关闭为空投影。
     *
     * @param list<array<string,mixed>> $channels 已经过 projectChannelResult 白名单化的歌曲插件结果
     * @return array{metadata:array{song:list<array<string,mixed>>,album:list<array<string,mixed>>},lyrics:list<array<string,mixed>>,artwork:?array<string,mixed>,plugin:?array<string,mixed>}
     */
    private function projectPersistedFacts(string $songId, array $channels): array
    {
        $songProjection = $this->projectPersistedFieldStates('media_metadata_field_states', 'song_id', $songId);
        $albumId = Db::table('media_songs')->where('id', $songId)->value('album_id');
        $albumProjection = is_string($albumId) && $albumId !== ''
            ? $this->projectPersistedFieldStates('media_album_metadata_field_states', 'album_id', $albumId)
            : ['fields' => [], 'plugin' => []];

        $pluginChannel = null;
        foreach ($channels as $channel) {
            if (($channel['channelKey'] ?? null) === 'metadata-scrape' && is_array($channel['metadata'] ?? null)) {
                $pluginChannel = $channel;
                break;
            }
        }

        $lyrics = [];
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('media_lyrics')) {
            $primaryLyricId = $schema->hasTable('media_lyrics_primary_selections')
                ? Db::table('media_lyrics_primary_selections')->where('song_id', $songId)->value('lyric_id') : null;
            /** @var list<stdClass> $lyricRows */
            $lyricRows = Db::table('media_lyrics')->where('song_id', $songId)->orderByDesc('priority')->orderBy('created_at')->get([
                'id', 'source_kind', 'storage_kind', 'language', 'lyric_kind', 'source_format', 'priority',
            ])->all();
            foreach ($lyricRows as $row) {
                $lyrics[] = [
                    'source' => (string) $row->source_kind,
                    'storage' => (string) $row->storage_kind,
                    'language' => (string) $row->language,
                    'kind' => (string) $row->lyric_kind,
                    'format' => (string) $row->source_format,
                    'primary' => is_string($primaryLyricId) && hash_equals($primaryLyricId, (string) $row->id),
                ];
            }
        }
        $artwork = $this->projectPersistedArtwork($songId);
        $pluginResources = [
            'lyrics' => count(array_filter($lyrics, static fn (array $lyric): bool => $lyric['source'] === 'provider')) > 0,
            'artwork' => is_array($artwork) && $artwork['source'] === 'scraped',
        ];
        $pluginFields = $songProjection['plugin'] + $albumProjection['plugin'];
        if ($pluginFields !== [] || $pluginResources['lyrics'] || $pluginResources['artwork']) {
            $plugin = [
                'channelKey' => 'metadata-scrape',
                'displayName' => (string) ($pluginChannel['displayName'] ?? '元数据刮削插件'),
                'confidence' => is_int($pluginChannel['confidence'] ?? null) ? $pluginChannel['confidence'] : null,
                'metadata' => ['song' => $songProjection['plugin'], 'album' => $albumProjection['plugin']],
                'resources' => $pluginResources,
            ];
        } else {
            $plugin = null;
        }

        return [
            'metadata' => ['song' => $songProjection['fields'], 'album' => $albumProjection['fields']],
            'lyrics' => $lyrics,
            'artwork' => $artwork,
            'plugin' => $plugin,
        ];
    }

    /**
     * 读取一个歌曲或共享实体的有效字段，并只把有效来源为 scraped 的值交给插件弹窗。
     *
     * 表和主键列来自固定调用方白名单，不接受浏览器输入；字段 JSON 损坏或字段表尚未迁移时跳过该项。
     * 返回值不包含数据库 locator、更新时间或锁信息，避免详情页把内部存储事实误当成业务数据。
     *
     * @return array{fields:list<array{field:string,value:mixed,source:string}>,plugin:array<string,mixed>}
     */
    private function projectPersistedFieldStates(string $table, string $idColumn, string $entityId): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable($table)) return ['fields' => [], 'plugin' => []];
        /** @var list<stdClass> $states */
        $states = Db::table($table)->where($idColumn, $entityId)->orderBy('field_key')->get([
            'field_key', 'effective_value_json', 'effective_source', 'scraped_value_json',
        ])->all();
        $fields = [];
        $plugin = [];
        foreach ($states as $state) {
            try {
                $value = json_decode((string) $state->effective_value_json, true, 32, JSON_THROW_ON_ERROR);
                $scraped = $state->scraped_value_json === null
                    ? null : json_decode((string) $state->scraped_value_json, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if ($this->persistedValueEmpty($value)) continue;
            $source = in_array((string) $state->effective_source, ['raw', 'scraped', 'manual'], true)
                ? (string) $state->effective_source : 'raw';
            $field = (string) $state->field_key;
            $fields[] = ['field' => $field, 'value' => $value, 'source' => $source];
            if ($source === 'scraped' && !$this->persistedValueEmpty($scraped)) $plugin[$field] = $scraped;
        }
        return ['fields' => $fields, 'plugin' => $plugin];
    }

    /** 返回当前歌曲可读封面的来源摘要；没有真实选择时返回 null，不用任务状态伪造资源。 */
    private function projectPersistedArtwork(string $songId): ?array
    {
        /** @var stdClass|null $songSelection */
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasTable('media_manual_artwork_candidates')) return null;
        $songSelection = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', 'candidates.id', '=', 'selections.candidate_id')
            ->where('selections.song_id', $songId)->first(['candidates.origin_kind']);
        if ($songSelection instanceof stdClass) {
            $source = (string) $songSelection->origin_kind === 'provider' ? 'scraped' : 'manual';
            return ['source' => $source, 'entity' => 'song', 'provider' => $source === 'scraped' ? 'metadata-scrape' : null];
        }
        $albumId = Db::table('media_songs')->where('id', $songId)->value('album_id');
        if (!is_string($albumId) || $albumId === '') return null;
        /** @var stdClass|null $albumSelection */
        $albumSelection = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', 'candidates.id', '=', 'selections.candidate_id')
            ->where('selections.album_id', $albumId)->first(['candidates.origin_kind']);
        if ($albumSelection instanceof stdClass) {
            $source = (string) $albumSelection->origin_kind === 'provider' ? 'scraped' : 'manual';
            return ['source' => $source, 'entity' => 'album', 'provider' => $source === 'scraped' ? 'metadata-scrape' : null];
        }
        if (!$schema->hasTable('media_album_artworks') || !$schema->hasColumn('media_album_artworks', 'source_kind')) return null;
        /** @var stdClass|null $indexed */
        $indexed = Db::table('media_album_artworks')->where('album_id', $albumId)->first(['source_kind']);
        if (!$indexed instanceof stdClass) return null;
        $sourceKind = (string) $indexed->source_kind;
        $source = $sourceKind === 'provider' ? 'scraped' : ($sourceKind === 'manual' ? 'manual' : 'raw');
        return ['source' => $source, 'entity' => 'album',
            'provider' => $source === 'scraped' ? 'metadata-scrape' : null];
    }

    private function persistedValueEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
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

    /**
     * 投影同一首歌可进入独立图片工作流的专辑和艺人实体。
     *
     * 这里只返回稳定 ID 与当前名称；图片接口仍会独立复验实体全部关联库 manage 权限，不能把本投影
     * 当作图片授权。歌曲消失或关系损坏返回空实体，任务历史仍可查看其他脱敏字段。
     *
     * @return array{album:?array{id:string,title:string},artists:list<array{id:string,name:string}>}
     */
    private function relatedEntities(string $songId): array
    {
        /** @var stdClass|null $album */
        $album = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->where('songs.id', $songId)->first(['albums.id', 'albums.title']);
        /** @var list<stdClass> $artists */
        $artists = Db::table('media_song_artists as links')
            ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->where('links.song_id', $songId)->orderBy('links.position')->orderBy('artists.id')
            ->get(['artists.id', 'artists.name'])->all();
        return [
            'album' => $album instanceof stdClass
                ? ['id' => (string) $album->id, 'title' => (string) $album->title] : null,
            'artists' => array_map(static fn (stdClass $artist): array => [
                'id' => (string) $artist->id, 'name' => (string) $artist->name,
            ], $artists),
        ];
    }

    /**
     * 从逐曲目标终态重建忽略后的父任务计数和版本。
     *
     * 调用前置条件是同一事务已经成功清除至少一个等待标记。`unmatched` 仍计入已处理但不会被改写成
     * 成功；只要还有 pending/running 目标，父任务保持 running，否则按全部成功、全部失败或混合结果
     * 收敛为 succeeded、failed 或 partial。该方法不触碰候选快照和资源发布，也不单独提交事务。
     */
    private function synchronizeIgnoredJob(string $jobId, string $now): void
    {
        $counts = ['succeeded' => 0, 'unmatched' => 0, 'failed' => 0];
        /** @var list<stdClass> $targets */
        $targets = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
            ->get(['status'])->all();
        foreach ($targets as $target) {
            $status = (string) $target->status;
            if (isset($counts[$status])) ++$counts[$status];
        }
        $processed = array_sum($counts);
        $targetCount = count($targets);
        $terminal = $processed === $targetCount;
        $status = !$terminal ? 'running'
            : ($counts['succeeded'] === $targetCount ? 'succeeded'
                : ($counts['failed'] === $targetCount ? 'failed' : 'partial'));
        Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->update([
            'status' => $status,
            'processed_count' => $processed,
            'succeeded_count' => $counts['succeeded'],
            'unmatched_count' => $counts['unmatched'],
            'failed_count' => $counts['failed'],
            'version' => Db::raw('version + 1'),
            'finished_at' => $terminal ? $now : null,
            'updated_at' => $now,
        ]);
    }

    /**
     * 读取构造平台查询所需的稳定目录事实，并冻结当前库存对象版本。
     *
     * 任务不需要把路径或原始标签 JSON 暴露给插件，但必须把库存主键、普通文件 dev/inode/size/mtime
     * 以及已经计算出的远端 ETag/字节摘要纳入 evidence。这样同一路径被替换、远端对象版本变化或扫描
     * 重新绑定库存时，网络结果不会应用到另一份媒体。旧测试/滚动部署缺少可选摘要列时使用 null，
     * 仍以已有库存身份字段形成稳定摘要；缺少库存行直接视为媒体已消失。
     *
     * @return array<string,mixed>|null
     */
    public function currentEvidence(string $songId): ?array
    {
        /** @var stdClass|null $row */
        $schema = Db::connection()->getSchemaBuilder();
        $inventorySelects = [
            'inventory.id as inventory_id', 'inventory.relative_path',
            'inventory.device_id', 'inventory.inode', 'inventory.file_size', 'inventory.modified_at',
        ];
        foreach (['byte_hash_status', 'byte_sha256', 'remote_etag'] as $column) {
            if ($schema->hasColumn('library_file_inventory', $column)) $inventorySelects[] = 'inventory.' . $column;
        }
        $row = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->leftJoin('library_file_inventory as inventory', 'inventory.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->first([
                'songs.id', 'songs.library_id', 'songs.title', 'songs.duration_ms', 'songs.track_number',
                'songs.track_total', 'songs.disc_number', 'songs.disc_total', 'songs.release_date',
                'songs.composer', 'songs.isrc', 'songs.musicbrainz_track_id', 'albums.title as album_title',
                'albums.musicbrainz_release_id', 'albums.musicbrainz_release_group_id',
                'libraries.scrape_storage_mode', ...$inventorySelects,
            ]);
        if (!$row instanceof stdClass || $row->inventory_id === null) return null;
        $artists = Db::table('media_song_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->where('links.song_id', $songId)->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
        $albumArtists = Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->join('media_songs as songs', 'songs.album_id', '=', 'links.album_id')->where('songs.id', $songId)
            ->orderBy('links.position')->pluck('artists.name')->map('strval')->all();
        $genres = Db::table('media_song_genres as links')->join('media_genres as genres', 'genres.id', '=', 'links.genre_id')
            ->where('links.song_id', $songId)->orderBy('links.position')->pluck('genres.name')->map('strval')->all();
        $artists = $artists ?: ['未知艺术家'];
        $evidence = [
            'version' => 1, 'songId' => (string) $row->id, 'libraryId' => (string) $row->library_id,
            'inventoryId' => (string) $row->inventory_id,
            'inventoryIdentity' => [
                'deviceId' => (int) $row->device_id,
                'inode' => (int) $row->inode,
                'fileSize' => (int) $row->file_size,
                'modifiedAt' => (int) $row->modified_at,
                'byteHashStatus' => property_exists($row, 'byte_hash_status') && $row->byte_hash_status !== null
                    ? (string) $row->byte_hash_status : null,
                'byteSha256' => property_exists($row, 'byte_sha256') && $row->byte_sha256 !== null
                    ? (string) $row->byte_sha256 : null,
                'remoteEtag' => property_exists($row, 'remote_etag') && $row->remote_etag !== null
                    ? (string) $row->remote_etag : null,
            ],
            'scrapeStorageMode' => (string) $row->scrape_storage_mode,
            'title' => (string) $row->title, 'artists' => $artists,
            'albumArtists' => $albumArtists ?: $artists, 'albumTitle' => (string) $row->album_title,
            'durationMs' => (int) $row->duration_ms,
            'trackNumber' => $row->track_number === null ? null : (int) $row->track_number,
            'trackTotal' => $row->track_total === null ? null : (int) $row->track_total,
            'discNumber' => $row->disc_number === null ? null : (int) $row->disc_number,
            'discTotal' => $row->disc_total === null ? null : (int) $row->disc_total,
            'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
            'genres' => $genres, 'composer' => $row->composer === null ? null : (string) $row->composer,
            'isrc' => $row->isrc === null ? null : (string) $row->isrc,
            'musicbrainzTrackId' => $row->musicbrainz_track_id === null ? null : (string) $row->musicbrainz_track_id,
            'musicbrainzReleaseId' => $row->musicbrainz_release_id === null ? null : (string) $row->musicbrainz_release_id,
            'musicbrainzReleaseGroupId' => $row->musicbrainz_release_group_id === null ? null : (string) $row->musicbrainz_release_group_id,
        ];
        $context = $this->filenameContext($row, $songId);
        if ($context !== null) {
            $evidence['filenameContext'] = $context;
            $evidence['scrapeInputMode'] = $context['metadataComplete'] ? 'metadata' : 'filename';
        } else {
            $evidence['scrapeInputMode'] = 'metadata';
        }
        return $evidence;
    }

    /**
     * 冻结文件名和上两级目录原文，并仅根据原始标签是否完整选择插件输入模式。
     * 这里不得做任何目录语义解析；返回值参与 evidence SHA，库存路径变化会使旧任务失效。读取无写入、
     * 网络或文件副作用，metadataComplete 只表示原始标签事实，不代表插件已确认媒体身份。
     *
     * @return array{fileName:string,parentDirectory:?string,grandparentDirectory:?string,metadataComplete:bool}|null
     */
    private function filenameContext(stdClass $row, string $songId): ?array
    {
        $path = is_string($row->relative_path ?? null) ? str_replace('\\', '/', $row->relative_path) : '';
        if ($path === '') return null;
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
        if ($parts === []) return null;
        $fileName = (string) end($parts);
        $parent = count($parts) >= 2 ? $parts[count($parts) - 2] : null;
        $grandparent = count($parts) >= 3 ? $parts[count($parts) - 3] : null;
        $metadataComplete = false;
        if (Db::connection()->getSchemaBuilder()->hasTable('media_tag_snapshots')) {
            $json = Db::table('media_tag_snapshots')->where('song_id', $songId)->value('raw_tags_json');
            if (is_string($json) && $json !== '') {
                try {
                    $raw = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
                    $tags = [];
                    foreach (['format', 'audioStream'] as $scope) {
                        if (is_array($raw[$scope] ?? null)) $tags = array_replace($tags, $raw[$scope]);
                    }
                    $metadataComplete = true;
                    foreach (['title', 'artist', 'album'] as $key) {
                        if (!is_string($tags[$key] ?? null) || trim($tags[$key]) === '') $metadataComplete = false;
                    }
                } catch (Throwable) {
                    $metadataComplete = false;
                }
            }
        }
        return ['fileName' => $fileName, 'parentDirectory' => $parent, 'grandparentDirectory' => $grandparent,
            'metadataComplete' => $metadataComplete];
    }

    private function requireRunScrape(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('run_scrape', $capabilities, true)) throw new AuthorizationDenied('缺少在线刮削权限。');
    }

    /** 测试夹具和滚动部署旧 schema 继续走原子旧流程；迁移完成后新任务才启用确认。 */
    private function supportsConfirmation(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('metadata_sync_scrape_jobs', 'confirmation_required')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'selected_candidate_sha256')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'selection_json');
    }

    /** 新逐曲状态列必须整体存在，避免滚动部署写出无法继续的半状态。 */
    private function supportsUnifiedPipeline(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('metadata_sync_scrape_targets', 'phase')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'next_attempt_at')
            && $schema->hasColumn('metadata_sync_scrape_targets', 'resource_status_json');
    }

    /** @return array<string,mixed>|null 损坏的内部资源摘要只降级为空，绝不直接透传未校验 JSON。 */
    private function decodeResourceStatus(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            return is_array($value) && !array_is_list($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @throws JsonException */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    public function decodeFrozenEvidence(string $json): array
    {
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_string($value['songId'] ?? null)
            || !is_string($value['title'] ?? null) || !is_array($value['artists'] ?? null)) {
            throw new MediaMetadataConflict('同步刮削证据损坏。');
        }
        return $value;
    }
}
