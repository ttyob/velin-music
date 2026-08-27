<?php

declare(strict_types=1);

namespace app\application\Job;

use app\application\Scan\ScanJobService;
use app\application\Scan\ScanJobConflict;
use app\application\Scan\ScanJobNotFound;
use app\application\Upload\UploadAdminService;
use app\application\Upload\UploadConflict;
use app\application\Upload\UploadNotFound;
use app\infrastructure\Audit\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use support\Db;
use support\Log;

/**
 * 将已经落地的异步任务事实合并为统一任务中心契约（OPS-JOB-001/002/009）。
 *
 * 扫描、逐曲刮削、上传及各派生任务继续拥有各自事实表与权限边界；旧目录整理和待入库历史已经
 * 从活动数据库与 API 删除。本服务只做安全投影的有界合并，不复制状态、
 * 不翻译终态，也不推算 ETA。新增任务
 * 类型必须提供独立授权适配器与固定筛选词表，不能把浏览器输入扩展成动态 SQL。所有读取均无写事务、
 * 文件或网络副作用。
 */
final readonly class JobCenterService
{
    public function __construct(
        private ScanJobService $scans = new ScanJobService(),
        private SongScrapeJobProjectionService $songScrapes = new SongScrapeJobProjectionService(),
        private UploadJobProjectionService $uploads = new UploadJobProjectionService(),
        private LyricsWritebackJobProjectionService $lyricsWriteback = new LyricsWritebackJobProjectionService(),
        private LyricsBatchWritebackJobProjectionService $lyricsBatchWriteback = new LyricsBatchWritebackJobProjectionService(),
        private AudioTagWritebackJobProjectionService $audioTagWriteback = new AudioTagWritebackJobProjectionService(),
        private AudioTagWritebackBatchJobProjectionService $audioTagWritebackBatches = new AudioTagWritebackBatchJobProjectionService(),
        private LyricsAudioTagWritebackJobProjectionService $lyricsAudioTagWriteback = new LyricsAudioTagWritebackJobProjectionService(),
        private LyricsAudioTagWritebackBatchJobProjectionService $lyricsAudioTagWritebackBatches = new LyricsAudioTagWritebackBatchJobProjectionService(),
        private ArtworkProviderJobProjectionService $artworkProvider = new ArtworkProviderJobProjectionService(),
        private MetadataBatchJobProjectionService $metadataBatches = new MetadataBatchJobProjectionService(),
        private UploadAdminService $uploadCommands = new UploadAdminService(),
        private AuditLogger $audit = new AuditLogger(),
    )
    {
    }

    /**
     * 在各适配器完成能力与对象范围过滤后返回按时间倒序的统一任务页。
     *
     * 合并分页最多为当前 offset 加 limit 读取各来源，且每个来源仍限制单页 100 行；日期先严格转换为
     * UTC 半开区间。无能力的任务类型会被拒绝而不是静默扩大为全部类型，音乐库筛选会自然排除全局
     * 备份。方法只读，不改变任何源任务状态。
     *
     * @param array<string, mixed> $actor 至少拥有一个适配器能力的当前 Session 身份快照。
     * @return array{jobs: list<array<string, mixed>>, total: int, limit: int, offset: int, supportedTypes: list<string>, filterOptions: array<string, mixed>, awaitingConfirmations:list<array<string,mixed>>, awaitingConfirmationTotal:int}
     */
    public function list(
        array $actor,
        ?string $type,
        ?string $status,
        ?string $libraryId,
        int $limit,
        int $offset,
        ?string $requestedBy = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
    ): array {
        $supported = $this->supportedTypes($actor);
        $this->requireType($type, $supported);
        $this->requireStatus($status);
        if ($libraryId !== null && $libraryId !== '' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
            throw new JobCenterInvalid('Invalid library filter.');
        }
        if ($requestedBy !== null && $requestedBy !== '' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestedBy) !== 1) {
            throw new JobCenterInvalid('Invalid requester filter.');
        }
        [$fromUtc, $beforeUtc] = $this->dateRange($createdFrom, $createdTo);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $libraryId = $libraryId === '' ? null : $libraryId;
        $requestedBy = $requestedBy === '' ? null : $requestedBy;
        $status = $status === '' ? null : $status;
        $clearedJobIds = $this->clearedJobIds($type);
        // 已清理任务可能仍占据来源分页；扩展读取窗口后再过滤，保证“清空”后的 total 和当前页一致。
        $sourceLimit = $clearedJobIds === []
            ? $limit
            : min(10_000, max(1, $offset + $limit + count($clearedJobIds)));
        $sourceOffset = $clearedJobIds === [] ? $offset : 0;
        $jobs = [];
        $total = 0;
        if ($type === 'scan') {
            $page = $this->scanPage($actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset);
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'song_scrape') {
            $page = $this->songScrapePage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'upload') {
            $page = $this->uploadPage(
                $actor,
                $libraryId,
                $status,
                $requestedBy,
                $fromUtc,
                $beforeUtc,
                $sourceLimit,
                $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'lyrics_writeback') {
            $page = $this->lyricsWritebackPage(
                $actor,
                $libraryId,
                $status,
                $requestedBy,
                $fromUtc,
                $beforeUtc,
                $sourceLimit,
                $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'lyrics_writeback_batch') {
            $page = $this->lyricsBatchWritebackPage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'audio_tag_writeback') {
            $page = $this->audioTagWritebackPage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'audio_tag_writeback_batch') {
            $page = $this->audioTagWritebackBatchPage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'lyrics_audio_tag_writeback') {
            $page = $this->lyricsAudioTagWritebackPage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'lyrics_audio_tag_writeback_batch') {
            $page = $this->lyricsAudioTagWritebackBatchPage(
                $actor,$libraryId,$status,$requestedBy,$fromUtc,$beforeUtc,$sourceLimit,$sourceOffset,
            );
            $jobs=$page['jobs'];$total=$page['total'];
        } elseif (in_array($type, ['artwork_provider_search', 'artwork_provider_import'], true)) {
            $page = $this->artworkProviderPage(
                $type, $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } elseif ($type === 'metadata_batch') {
            $page = $this->metadataBatchPage(
                $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $sourceLimit, $sourceOffset,
            );
            $jobs = $page['jobs']; $total = $page['total'];
        } else {
            $required = $sourceOffset + $sourceLimit;
            $all = [];
            if (in_array('scan', $supported, true)) {
                $page = $this->scanPage($actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0);
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('song_scrape', $supported, true)) {
                $page = $this->songScrapePage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('upload', $supported, true)) {
                $page = $this->uploadPage(
                    $actor,
                    $libraryId,
                    $status,
                    $requestedBy,
                    $fromUtc,
                    $beforeUtc,
                    $required,
                    0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('lyrics_writeback', $supported, true)) {
                $page = $this->lyricsWritebackPage(
                    $actor,
                    $libraryId,
                    $status,
                    $requestedBy,
                    $fromUtc,
                    $beforeUtc,
                    $required,
                    0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('lyrics_writeback_batch', $supported, true)) {
                $page = $this->lyricsBatchWritebackPage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('audio_tag_writeback', $supported, true)) {
                $page = $this->audioTagWritebackPage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('audio_tag_writeback_batch', $supported, true)) {
                $page = $this->audioTagWritebackBatchPage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('lyrics_audio_tag_writeback', $supported, true)) {
                $page = $this->lyricsAudioTagWritebackPage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if(in_array('lyrics_audio_tag_writeback_batch',$supported,true)){
                $page=$this->lyricsAudioTagWritebackBatchPage($actor,$libraryId,$status,$requestedBy,$fromUtc,$beforeUtc,$required,0);
                $all=array_merge($all,$page['jobs']);$total+=$page['total'];
            }
            foreach (['artwork_provider_search', 'artwork_provider_import'] as $providerType) {
                if (!in_array($providerType, $supported, true)) continue;
                $page = $this->artworkProviderPage(
                    $providerType, $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            if (in_array('metadata_batch', $supported, true)) {
                $page = $this->metadataBatchPage(
                    $actor, $libraryId, $status, $requestedBy, $fromUtc, $beforeUtc, $required, 0,
                );
                $all = array_merge($all, $page['jobs']); $total += $page['total'];
            }
            usort($all, static fn (array $a, array $b): int => [$b['createdAt'], $b['id']] <=> [$a['createdAt'], $a['id']]);
            $jobs = $all;
        }

        $beforeHidden = count($jobs);
        if ($clearedJobIds !== []) {
            $jobs = array_values(array_filter(
                $jobs,
                static fn (array $job): bool => !isset($clearedJobIds[(string) $job['type'] . ':' . (string) $job['id']]),
            ));
            $total = max(0, $total - ($beforeHidden - count($jobs)));
        }
        $jobs = array_slice($jobs, $offset, $limit);

        $filterOptions = ['libraries' => [], 'requesters' => []];
        if (in_array('scan', $supported, true)) $filterOptions = $this->scans->filterOptions($actor);
        if (in_array('song_scrape', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions($filterOptions, $this->songScrapes->filterOptions($actor));
        }
        if (in_array('upload', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions($filterOptions, $this->uploads->filterOptions($actor));
        }
        if (in_array('lyrics_writeback', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions($filterOptions, $this->lyricsWriteback->filterOptions($actor));
        }
        if (in_array('lyrics_writeback_batch', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions(
                $filterOptions,
                $this->lyricsBatchWriteback->filterOptions($actor),
            );
        }
        if (in_array('audio_tag_writeback', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions($filterOptions, $this->audioTagWriteback->filterOptions($actor));
        }
        if (in_array('audio_tag_writeback_batch', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions(
                $filterOptions,
                $this->audioTagWritebackBatches->filterOptions($actor),
            );
        }
        if (in_array('lyrics_audio_tag_writeback', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions(
                $filterOptions, $this->lyricsAudioTagWriteback->filterOptions($actor),
            );
        }
        if(in_array('lyrics_audio_tag_writeback_batch',$supported,true))
            $filterOptions=$this->mergeFilterOptions($filterOptions,$this->lyricsAudioTagWritebackBatches->filterOptions($actor));
        foreach (['artwork_provider_search', 'artwork_provider_import'] as $providerType) {
            if (in_array($providerType, $supported, true)) {
                $filterOptions = $this->mergeFilterOptions(
                    $filterOptions, $this->artworkProvider->filterOptions($providerType, $actor),
                );
            }
        }
        if (in_array('metadata_batch', $supported, true)) {
            $filterOptions = $this->mergeFilterOptions($filterOptions, $this->metadataBatches->filterOptions($actor));
        }
        $awaiting = in_array('song_scrape', $supported, true)
            ? $this->songScrapes->awaitingConfirmation($actor)
            : ['jobs' => [], 'total' => 0];

        $jobs = array_map(function (array $job): array {
            $job['commands']['canClear'] = $this->canClearJob($job);
            return $job;
        }, $jobs);

        return [
            'jobs' => $jobs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'supportedTypes' => $supported,
            'filterOptions' => $filterOptions,
            'awaitingConfirmations' => $awaiting['jobs'],
            'awaitingConfirmationTotal' => $awaiting['total'],
        ];
    }

    /**
     * 返回一个统一任务投影，并在每个来源适配器中重复实时权限校验。
     *
     * ID 格式先严格验证；不存在与任一来源失权统一抛出不可枚举的 not-found。方法不会因在前一个来源
     * 未命中而放宽后续来源的能力或音乐库范围，也不执行任何任务命令。
     */
    public function find(array $actor, string $jobId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            throw new JobCenterInvalid('Invalid task identifier.');
        }

        if (in_array('scan', $this->supportedTypes($actor), true)) {
            try {
                $job = $this->mapScan($this->scans->findJob($jobId, $actor));
                $job['errorSamples'] = $this->scans->errorSamples($jobId, $actor);
                return $this->decorateJob($job);
            } catch (ScanJobNotFound) {}
        }
        if (in_array('song_scrape', $this->supportedTypes($actor), true)) {
            $job = $this->songScrapes->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('upload', $this->supportedTypes($actor), true)) {
            $job = $this->uploads->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('lyrics_writeback', $this->supportedTypes($actor), true)) {
            $job = $this->lyricsWriteback->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('lyrics_writeback_batch', $this->supportedTypes($actor), true)) {
            $job = $this->lyricsBatchWriteback->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('audio_tag_writeback', $this->supportedTypes($actor), true)) {
            $job = $this->audioTagWriteback->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('audio_tag_writeback_batch', $this->supportedTypes($actor), true)) {
            $job = $this->audioTagWritebackBatches->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('lyrics_audio_tag_writeback', $this->supportedTypes($actor), true)) {
            $job = $this->lyricsAudioTagWriteback->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if(in_array('lyrics_audio_tag_writeback_batch',$this->supportedTypes($actor),true)){
            $job=$this->lyricsAudioTagWritebackBatches->find($actor,$jobId);
            if($job!==null)return$this->decorateJob($job);}
        if (in_array('artwork_provider_search', $this->supportedTypes($actor), true)) {
            $job = $this->artworkProvider->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        if (in_array('metadata_batch', $this->supportedTypes($actor), true)) {
            $job = $this->metadataBatches->find($actor, $jobId);
            if ($job !== null) return $this->decorateJob($job);
        }
        throw new JobCenterNotFound('Task not found.');
    }

    /**
     * 把统一取消命令委托给已经落地的来源状态机，并返回命令后的最新统一投影。
     *
     * 浏览器必须提交当前详情中的固定类型；上传还必须提交来源 version。这样即使不同事实表意外使用
     * 相同 ULID，也不会按探测顺序误操作。扫描只支持 queued 立即取消和 running 协作取消；上传只
     * 支持 created/uploading/ready 的版本化立即取消。其余任务没有成熟来源状态机，明确拒绝而不是
     * 直接改表或伪造 cancelled。实际上传暂存清理仍由 UploadAdminService 在 SQLite 事务外执行。
     *
     * @param array<string,mixed> $actor 当前 Web Session 重新解析的身份。
     * @return array<string,mixed> 命令完成后的统一任务投影。
     */
    public function cancel(
        array $actor,
        string $jobId,
        string $type,
        ?int $expectedVersion,
        string $requestId,
    ): array {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            throw new JobCenterInvalid('Invalid task identifier.');
        }
        try {
            if ($type === 'scan') {
                if (!in_array('scan', $this->supportedTypes($actor), true)) {
                    throw new JobCenterInvalid('Unsupported task command.');
                }
                $this->scans->cancelJob($jobId, $actor, $requestId);
                return $this->mapScan($this->scans->findJob($jobId, $actor));
            }
            if ($type === 'upload') {
                if (!in_array('upload', $this->supportedTypes($actor), true)) {
                    throw new JobCenterInvalid('Unsupported task command.');
                }
                if ($expectedVersion === null || $expectedVersion < 1) {
                    throw new JobCenterInvalid('Upload task version is required.');
                }
                $current = $this->uploads->find($actor, $jobId);
                if ($current === null) throw new JobCenterNotFound('Task not found.');
                if (($current['commands']['expectedVersion'] ?? null) !== $expectedVersion) {
                    throw new JobCenterConflict('Task version changed.');
                }
                $this->uploadCommands->cancel($actor, $jobId, $expectedVersion, $requestId);
                $updated = $this->uploads->find($actor, $jobId);
                if ($updated === null) throw new JobCenterNotFound('Task not found.');
                return $updated;
            }
        } catch (ScanJobNotFound|UploadNotFound) {
            throw new JobCenterNotFound('Task not found.');
        } catch (ScanJobConflict|UploadConflict $conflict) {
            throw new JobCenterConflict($conflict->getMessage(), previous: $conflict);
        }
        throw new JobCenterInvalid('Task type does not support cancellation.');
    }

    /**
     * 把统一重试命令委托给会创建新任务的扫描来源状态机。
     *
     * 请求必须携带详情投影中的固定类型，避免同 ULID 跨事实表误操作。原任务永不复位或覆盖；新任务
     * 会重新执行来源权限、对象范围、活动任务唯一约束、账号限额和事务内审计。文件工作仍由 Worker
     * 完成。没有成熟幂等重试边界的来源明确拒绝，不能仅把 failed 字段改回 queued。
     *
     * @return array<string,mixed> 新创建任务的统一投影。
     */
    public function retry(array $actor, string $jobId, string $type, string $requestId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            throw new JobCenterInvalid('Invalid task identifier.');
        }
        try {
            if ($type === 'scan') {
                if (!in_array('scan', $this->supportedTypes($actor), true)) {
                    throw new JobCenterInvalid('Unsupported task command.');
                }
                return $this->mapScan($this->scans->retryJob($jobId, $actor, $requestId));
            }
        } catch (ScanJobNotFound) {
            throw new JobCenterNotFound('Task not found.');
        } catch (ScanJobConflict $conflict) {
            throw new JobCenterConflict($conflict->getMessage(), previous: $conflict);
        }
        throw new JobCenterInvalid('Task type does not support retry.');
    }

    /**
     * 清理一个任务来源的终态事实，并保留已经应用的媒体、元数据、派生资源和审计。
     *
     * type 必须来自当前列表投影；服务会先重新授权并确认非待确认终态，再在短事务中按来源固定
     * 的子表顺序删除任务事实；若业务外键仍引用源任务，则回滚删除并写入任务中心隐藏标记。活动状态、
     * 待确认刮削、未知来源和并发状态变化均失败关闭。上传任务额外委托上传领域服务清理受控暂存，
     * 避免统一任务中心接触物理路径。
     *
     * @return array{id:string,type:string,cleared:true}
     */
    public function clear(array $actor, string $jobId, string $type, string $requestId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1 || $type === '') {
            throw new JobCenterInvalid('Invalid task identifier.');
        }
        if ($this->isCleared($type, $jobId)) {
            throw new JobCenterNotFound('Task not found.');
        }
        $current = $this->find($actor, $jobId);
        if (($current['type'] ?? null) !== $type) throw new JobCenterNotFound('Task not found.');
        if (!$this->canClearJob($current)) throw new JobCenterConflict('当前任务状态不允许清理。');

        if ($type === 'upload') {
            $this->uploadCommands->clearTerminal($actor, $jobId, (string) $current['status'], $requestId);
            return ['id' => $jobId, 'type' => $type, 'cleared' => true];
        }

        try {
            Db::transaction(function () use ($current, $jobId, $type, $actor, $requestId): void {
                $this->clearSourceFact($type, $jobId, (string) $current['status']);
                $this->audit->record((string) $actor['id'], 'admin.job.clear', 'job', $jobId, 'success', $requestId, [
                    'type' => $type, 'previousStatus' => (string) $current['status'],
                ]);
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed')) {
                // 业务表仍引用该任务时，事务已经回滚；写入任务中心隐藏标记，不能为清理显示而破坏业务事实。
                Db::transaction(function () use ($actor, $jobId, $type, $requestId): void {
                    $this->markCleared($actor, $jobId, $type, $requestId, 'retained_by_business_fact');
                    $this->audit->record((string) $actor['id'], 'admin.job.clear', 'job', $jobId, 'success', $requestId, [
                        'type' => $type, 'retainedSourceFact' => true,
                    ]);
                });
                return ['id' => $jobId, 'type' => $type, 'cleared' => true];
            }
            throw $exception;
        }

        return ['id' => $jobId, 'type' => $type, 'cleared' => true];
    }

    /**
     * 按当前任务筛选批量清理可见的终态任务事实。
     *
     * type 和 status 只来自统一列表的固定词表；方法先完整读取当前权限范围内的有界快照，再逐条
     * 复用 clear() 的来源授权、状态复验、暂存补偿和审计逻辑，避免批量入口绕过任一来源适配器。
     * 活动任务、待确认刮削和并发中已经变化的任务会保留；已经成功写入媒体、元数据、歌词、封面或
     * 音频标签的业务事实不会被删除。为避免一次确认产生无界数据库事务，清理仍按单任务短事务执行。
     *
     * @return array{clearedJobCount:int, skippedJobCount:int}
     */
    public function clearAll(array $actor, ?string $type, ?string $status, string $requestId): array
    {
        $supported = $this->supportedTypes($actor);
        $this->requireType($type, $supported);
        $this->requireStatus($status);

        $firstPage = $this->list($actor, $type, $status, null, 100, 0);
        $total = (int) $firstPage['total'];
        // 统一列表本身限制最大偏移；超过该范围时要求管理员先用类型或状态筛选分批清理，避免静默漏删。
        if ($total > 10_100) {
            throw new JobCenterConflict('任务数量过多，请先按类型或状态筛选后清理。');
        }

        $jobs = $firstPage['jobs'];
        for ($offset = 100; $offset < $total; $offset += 100) {
            $page = $this->list($actor, $type, $status, null, 100, $offset);
            $jobs = array_merge($jobs, $page['jobs']);
        }

        $cleared = 0;
        $skipped = 0;
        foreach ($jobs as $job) {
            if (!$this->canClearJob($job)) {
                $skipped++;
                continue;
            }
            try {
                $this->clear($actor, (string) $job['id'], (string) $job['type'], $requestId);
                $cleared++;
            } catch (JobCenterConflict|JobCenterNotFound|UploadConflict|UploadNotFound) {
                // 列表快照与清理之间可能有 Worker 或其他管理员改变状态；该条保留并继续处理其余任务。
                $skipped++;
            } catch (\Throwable $exception) {
                // 单条来源事实异常不能让整个批量请求失败：clear() 已经用短事务保护了数据库删除，
                // 未预期异常发生时该条事务会回滚，任务仍可在下一次诊断或修复后重试。这里记录稳定的
                // 来源类型、任务 ID、异常类和请求 ID，既便于定位具体投影，又不把 SQL、路径或错误正文
                // 返回给前端；其余任务继续处理，保证“清空”始终是可部分完成且可重复执行的操作。
                try {
                    $logConfig = config('log', []);
                    if (is_array($logConfig) && isset($logConfig['default'])) {
                        Log::warning('Unified task cleanup skipped an unexpected source failure.', [
                            'request_id' => $requestId,
                            'job_type' => (string) ($job['type'] ?? ''),
                            'job_id' => (string) ($job['id'] ?? ''),
                            'exception_class' => $exception::class,
                        ]);
                    }
                } catch (\Throwable) {
                    // 精简测试引导或日志系统自身故障不能改变任务清理的部分成功语义。
                }
                $skipped++;
            }
        }

        return ['clearedJobCount' => $cleared, 'skippedJobCount' => $skipped];
    }

    /** 任务列表只允许展示非待确认终态的清理图标；具体来源仍在 clear() 内复验。 */
    private function canClearJob(array $job): bool
    {
        return ($job['attention'] ?? null) !== 'confirmation'
            && in_array((string) ($job['status'] ?? ''), [
                'succeeded', 'partial', 'failed', 'cancelled', 'completed', 'expired',
            ], true);
    }

    /** 为列表和详情统一补充来源状态机计算出的清理能力，不改变来源适配器的原始投影字段。 */
    private function decorateJob(array $job): array
    {
        $job['commands']['canClear'] = $this->canClearJob($job);
        return $job;
    }

    /**
     * 读取任务中心隐藏标记，并按 type:id 组成内存集合。
     *
     * 标记表是跨来源的投影事实；不存在于旧测试缩减 schema 时按空集合处理，生产迁移完成后所有查询
     * 都通过该边界过滤。集合只用于有界列表和详情判断，不替代来源适配器的对象权限校验。
     *
     * @return array<string, true>
     */
    private function clearedJobIds(?string $type): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('job_center_cleared_jobs')) return [];
        $query = Db::table('job_center_cleared_jobs');
        if ($type !== null && $type !== '') $query->where('job_type', $type);
        $keys = [];
        foreach ($query->get(['job_type', 'job_id']) as $row) {
            $keys[(string) $row->job_type . ':' . (string) $row->job_id] = true;
        }
        return $keys;
    }

    /** 判断单条任务是否已从任务中心清理，避免通过旧通知或详情链接重新显示。 */
    private function isCleared(string $type, string $jobId): bool
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('job_center_cleared_jobs')) return false;
        return Db::table('job_center_cleared_jobs')
            ->where('job_type', $type)
            ->where('job_id', $jobId)
            ->exists();
    }

    /** 写入清理标记；唯一键保证并发点击清理具有幂等性。 */
    private function markCleared(array $actor, string $jobId, string $type, string $requestId, string $reason): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('job_center_cleared_jobs')) {
            throw new JobCenterConflict('任务清理存储尚未就绪。');
        }
        Db::table('job_center_cleared_jobs')->insertOrIgnore([
            'id' => (string) new \Symfony\Component\Uid\Ulid(),
            'job_type' => $type,
            'job_id' => $jobId,
            'actor_user_id' => (string) ($actor['id'] ?? ''),
            'request_id' => $requestId,
            'reason' => $reason,
            'cleared_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * 按固定来源白名单清理任务事实。
     *
     * 子表删除顺序是数据库外键契约的一部分；不会删除媒体表、变更集、封面候选选择或已应用的
     * 扫描结果之外的业务事实。批量写回只删除任务方案及其内部执行事实，已写入文件的结果不做反向文件操作。
     */
    private function clearSourceFact(string $type, string $jobId, string $status): void
    {
        $terminal = ['succeeded', 'partial', 'failed', 'cancelled', 'completed', 'expired'];
        if (!in_array($status, $terminal, true)) throw new JobCenterConflict('当前任务状态不允许清理。');
        switch ($type) {
            case 'scan':
                Db::table('library_scan_file_results')->where('scan_job_id', $jobId)->delete();
                $changed = Db::table('library_scan_jobs')->where('id', $jobId)->where('status', $status)->delete();
                if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
                return;
            case 'song_scrape':
                $targets = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->pluck('id')->all();
                if ($targets !== []) Db::table('metadata_sync_scrape_channel_results')->whereIn('target_id', $targets)->delete();
                Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)->delete();
                $changed = Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->where('status', $status)->delete();
                if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
                return;
            case 'lyrics_writeback':
                $this->clearSingleFact('lyrics_writeback_jobs', 'lyrics_writeback_operation_logs', $jobId, $status);
                return;
            case 'audio_tag_writeback':
                $this->clearSingleFact('audio_tag_writeback_jobs', 'audio_tag_writeback_operation_logs', $jobId, $status);
                return;
            case 'lyrics_audio_tag_writeback':
                $this->clearSingleFact('lyrics_audio_tag_writeback_jobs', 'lyrics_audio_tag_writeback_operation_logs', $jobId, $status);
                return;
            case 'metadata_batch':
                Db::table('metadata_batch_targets')->where('plan_id', $jobId)->delete();
                $changed = Db::table('metadata_batch_plans')->where('id', $jobId)->where('status', $status)->delete();
                if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
                return;
            case 'lyrics_writeback_batch':
                $this->clearBatchFact('lyrics_writeback_batch_plans', 'lyrics_writeback_batch_targets', 'lyrics_writeback_plans', 'lyrics_writeback_jobs', 'lyrics_writeback_operation_logs', $jobId, $status);
                return;
            case 'audio_tag_writeback_batch':
                $this->clearBatchFact('audio_tag_writeback_batch_plans', 'audio_tag_writeback_batch_targets', 'audio_tag_writeback_plans', 'audio_tag_writeback_jobs', 'audio_tag_writeback_operation_logs', $jobId, $status);
                return;
            case 'lyrics_audio_tag_writeback_batch':
                $this->clearBatchFact('lyrics_audio_tag_writeback_batch_plans', 'lyrics_audio_tag_writeback_batch_targets', 'lyrics_audio_tag_writeback_plans', 'lyrics_audio_tag_writeback_jobs', 'lyrics_audio_tag_writeback_operation_logs', $jobId, $status);
                return;
            case 'artwork_provider_import':
                $changed = Db::table('artwork_provider_import_jobs')->where('id', $jobId)->where('status', $status)->delete();
                if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
                return;
            case 'artwork_provider_search':
                if (Db::table('artwork_provider_import_jobs')->where('search_job_id', $jobId)->exists()) {
                    throw new JobCenterConflict('封面搜索仍被导入任务引用。');
                }
                Db::table('artwork_provider_candidates')->where('search_job_id', $jobId)->delete();
                $changed = Db::table('artwork_provider_search_jobs')->where('id', $jobId)->where('status', $status)->delete();
                if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
                return;
        }
        throw new JobCenterInvalid('Task type does not support cleanup.');
    }

    private function clearSingleFact(string $jobTable, string $logTable, string $jobId, string $status): void
    {
        Db::table($logTable)->where('job_id', $jobId)->delete();
        $changed = Db::table($jobTable)->where('id', $jobId)->where('status', $status)->delete();
        if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
    }

    private function clearBatchFact(
        string $batchTable,
        string $targetTable,
        string $planTable,
        string $jobTable,
        string $logTable,
        string $batchId,
        string $status,
    ): void {
        $planIds = Db::table($targetTable)->where('batch_plan_id', $batchId)->pluck('writeback_plan_id')->all();
        if ($planIds !== []) {
            $jobIds = Db::table($jobTable)->whereIn('plan_id', $planIds)->pluck('id')->all();
            if ($jobIds !== []) Db::table($logTable)->whereIn('job_id', $jobIds)->delete();
            Db::table($jobTable)->whereIn('plan_id', $planIds)->delete();
            Db::table($targetTable)->where('batch_plan_id', $batchId)->delete();
            Db::table($planTable)->whereIn('id', $planIds)->delete();
        }
        $changed = Db::table($batchTable)->where('id', $batchId)->where('status', $status)->delete();
        if ($changed !== 1) throw new JobCenterConflict('任务状态已经变化。');
    }

    /** 仅映射扫描服务已脱敏字段，并把不可靠的百分比、速度与 ETA 明确保留为 null。 */
    private function mapScan(array $job): array
    {
        return [
            'id' => (string) $job['id'],
            'type' => 'scan',
            'status' => (string) $job['status'],
            'phase' => (string) $job['phase'],
            'subject' => [
                'type' => 'library',
                'id' => (string) $job['library']['id'],
                'label' => (string) $job['library']['name'],
            ],
            'requestedBy' => $job['requestedById'] === null || $job['requestedBy'] === null ? null : [
                'id' => (string) $job['requestedById'],
                'label' => (string) $job['requestedBy'],
            ],
            'progress' => [
                'processed' => (int) $job['processedEntries'],
                'discovered' => (int) $job['discoveredFiles'],
                'failed' => (int) $job['failedEntries'],
                'percent' => null,
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => (int) $job['attempt'],
            'error' => $job['error'],
            'createdAt' => (string) $job['createdAt'],
            'startedAt' => $job['startedAt'],
            'finishedAt' => $job['finishedAt'],
            'updatedAt' => (string) $job['updatedAt'],
            'commands' => [
                'canCancel' => in_array($job['status'], ['queued', 'running'], true),
                'canRetry' => in_array($job['status'], ['failed', 'cancelled'], true),
                'canRollback' => false,
                'cancelBehavior' => $job['status'] === 'queued' ? 'immediate'
                    : ($job['status'] === 'running' ? 'request' : null),
                'expectedVersion' => null,
            ],
            'sourceDetailHref' => '/admin/jobs?type=scan&jobId=' . rawurlencode((string) $job['id']),
            'errorSamples' => null,
            'report' => in_array($job['status'], ['succeeded', 'failed', 'cancelled'], true) ? [
                'format' => 'json',
                'downloadHref' => '/api/v1/admin/jobs/' . rawurlencode((string) $job['id']) . '/report?type=scan',
            ] : null,
        ];
    }

    /**
     * 根据当前请求的全局能力返回可用适配器词表。
     *
     * 逐曲刮削同时需要 `run_scrape` 与 `edit_metadata`，扫描需要 `manage_library`，上传后台需要
     * `manage_storage`，歌词与音频标签写回需要 `edit_metadata`；在线歌词任务同时需要 `edit_metadata` 与
     * `run_scrape`。真正对象读取还会按来源规则校验音乐库范围。
     * 返回词表只用于路由选择，不能替代适配器自身授权。
     *
     * @return list<string>
     */
    private function supportedTypes(array $actor): array
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        $types = [];
        if (in_array('manage_library', $capabilities, true)) $types[] = 'scan';
        if (in_array('run_scrape', $capabilities, true) && in_array('edit_metadata', $capabilities, true)
            && \support\Db::connection()->getSchemaBuilder()->hasTable('metadata_sync_scrape_jobs')) {
            $types[] = 'song_scrape';
        }
        if (in_array('manage_storage', $capabilities, true)) $types[] = 'upload';
        if (in_array('edit_metadata', $capabilities, true)) $types[] = 'lyrics_writeback';
        if (in_array('edit_metadata', $capabilities, true)
            && \support\Db::connection()->getSchemaBuilder()->hasTable('lyrics_writeback_batch_plans')) {
            $types[] = 'lyrics_writeback_batch';
        }
        // 滚动升级或隔离模块测试中迁移可能尚未完成；事实表可用前不能公布一个必然 503 的筛选类型。
        if (in_array('edit_metadata', $capabilities, true)
            && \support\Db::connection()->getSchemaBuilder()->hasTable('audio_tag_writeback_jobs')) {
            $types[] = 'audio_tag_writeback';
        }
        if (in_array('edit_metadata', $capabilities, true)
            && \support\Db::connection()->getSchemaBuilder()->hasTable('audio_tag_writeback_batch_plans')) {
            $types[] = 'audio_tag_writeback_batch';
        }
        if (in_array('edit_metadata', $capabilities, true)
            && \support\Db::connection()->getSchemaBuilder()->hasTable('lyrics_audio_tag_writeback_jobs')) {
            $types[] = 'lyrics_audio_tag_writeback';
        }
        if(in_array('edit_metadata',$capabilities,true)
            &&\support\Db::connection()->getSchemaBuilder()->hasTable('lyrics_audio_tag_writeback_batch_plans'))
            $types[]='lyrics_audio_tag_writeback_batch';
        if (in_array('edit_metadata', $capabilities, true) && in_array('run_scrape', $capabilities, true)) {
            if (\support\Db::connection()->getSchemaBuilder()->hasTable('artwork_provider_search_jobs')) {
                $types[] = 'artwork_provider_search';
                $types[] = 'artwork_provider_import';
            }
        }
        if (in_array('edit_metadata', $capabilities, true)) $types[] = 'metadata_batch';
        return $types;
    }

    /** 只接受当前身份已启用的适配器，未知或无权类型均拒绝，防止筛选静默放宽。 */
    private function requireType(?string $type, array $supported): void
    {
        if ($type !== null && $type !== '' && !in_array($type, $supported, true)) {
            throw new JobCenterInvalid('Unsupported task type.');
        }
    }

    /** 分批读取足以覆盖合并 offset 的扫描行，每次仍受来源服务 100 行和对象权限限制。 */
    private function scanPage(array $actor, ?string $libraryId, ?string $status, ?string $requestedBy, ?string $from, ?string $before, int $limit, int $offset): array
    {
        $raw = $this->collect(fn (int $take, int $skip): array => $this->scans->listJobs($actor, $libraryId, $status, null, $take, $skip, $requestedBy, $from, $before), $limit, $offset);
        return ['jobs' => array_map(fn (array $job): array => $this->mapScan($job), $raw['jobs']), 'total' => $raw['total']];
    }

    /**
     * 分批读取逐曲刮削父任务；渠道候选和派生资源结果由详情弹窗从原业务接口读取。
     *
     * 适配器会重复校验双能力、发起者和全部目标库实时 manage 权限。collect 只负责有界分页，不能
     * 将旧目录整理任务混入当前逐曲刮削类型，也不复制或改写任务状态。
     *
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    private function songScrapePage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->songScrapes->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /**
     * 分批读取上传会话事实，保留存储能力与音乐库范围。
     *
     * collect 只处理分页窗口，上传状态映射、发起者和进度可信性均由专用适配器负责。
     *
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    private function uploadPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->uploads->page(
                $actor,
                $libraryId,
                $status,
                $requestedBy,
                $from,
                $before,
                $take,
                $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取歌词写回事实；授权和字段脱敏由专用适配器重复执行。 */
    private function lyricsWritebackPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->lyricsWriteback->page(
                $actor,
                $libraryId,
                $status,
                $requestedBy,
                $from,
                $before,
                $take,
                $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取批量歌词父任务；内部单曲子任务由单曲适配器排除，避免重复展示。 */
    private function lyricsBatchWritebackPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->lyricsBatchWriteback->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取音频标签写回事实；完整 manage 授权和字段脱敏由专用适配器重复执行。 */
    private function audioTagWritebackPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->audioTagWriteback->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取批量描述元数据音频标签父任务，内部单曲任务由单曲适配器隐藏。 */
    private function audioTagWritebackBatchPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->audioTagWritebackBatches->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取歌词音频标签写回事实；实时 manage 授权和脱敏由专用适配器重复执行。 */
    private function lyricsAudioTagWritebackPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->lyricsAudioTagWriteback->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取批量歌词音频标签父任务，内部单曲任务由单曲适配器隐藏。 */
    private function lyricsAudioTagWritebackBatchPage(array$actor,?string$libraryId,?string$status,
        ?string$requestedBy,?string$from,?string$before,int$limit,int$offset):array
    {
        return$this->collect(fn(int$take,int$skip):array=>$this->lyricsAudioTagWritebackBatches->page(
            $actor,$libraryId,$status,$requestedBy,$from,$before,$take,$skip),$limit,$offset);
    }

    /** 分批读取远程封面搜索或导入事实；完整实体 manage 范围由专用适配器重复验证。 */
    private function artworkProviderPage(
        string $type,
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->artworkProvider->page(
                $type, $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /** 分批读取已确认批量元数据方案；草稿排除和完整 manage 范围由专用适配器重复执行。 */
    private function metadataBatchPage(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $from,
        ?string $before,
        int $limit,
        int $offset,
    ): array {
        return $this->collect(
            fn (int $take, int $skip): array => $this->metadataBatches->page(
                $actor, $libraryId, $status, $requestedBy, $from, $before, $take, $skip,
            ),
            $limit,
            $offset,
        );
    }

    /**
     * 按最多 100 行读取来源，直到覆盖所需窗口或到达真实总数。
     *
     * reader 必须已经包含权限条件；本方法只做有界分页拼接，不重排单一来源，也不执行写入。
     *
     * @param callable(int,int): array{jobs:list<array<string,mixed>>,total:int} $reader
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    private function collect(callable $reader, int $limit, int $offset): array
    {
        $wanted = $offset + $limit; $rows = []; $cursor = 0; $total = 0;
        do {
            $page = $reader(min(100, max(1, $wanted - $cursor)), $cursor);
            $total = (int) $page['total']; $rows = array_merge($rows, $page['jobs']); $cursor = count($rows);
        } while ($cursor < min($wanted, $total));
        return ['jobs' => array_slice($rows, $offset, $limit), 'total' => $total];
    }

    /** 拒绝统一词表之外的状态，避免拼写错误退化成未筛选的管理员查询。 */
    private function requireStatus(?string $status): void
    {
        if ($status !== null && $status !== '' && !in_array($status, [
            'queued', 'running', 'cancel_requested', 'cancelled', 'succeeded', 'partial', 'failed',
        ], true)) {
            throw new JobCenterInvalid('Unsupported task status.');
        }
    }

    /**
     * 把浏览器的闭区间日期转换为规范 UTC 半开区间。
     *
     * 纯日期筛选被定义为与客户端时区无关的管理边界：from 从 00:00:00Z 开始，to 截止到下一 UTC 日
     * 之前。严格往返解析会拒绝 PHP 自动归一化的非法日期与倒置区间，不能静默扩大任务查询。
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(?string $from, ?string $to): array
    {
        $timezone = new DateTimeZone('UTC');
        $start = $this->date($from, $timezone);
        $end = $this->date($to, $timezone);
        if ($start !== null && $end !== null && $start > $end) {
            throw new JobCenterInvalid('Task date range is inverted.');
        }

        return [
            $start?->format('Y-m-d\T00:00:00\Z'),
            $end?->modify('+1 day')->format('Y-m-d\T00:00:00\Z'),
        ];
    }

    /** 严格解析一个可选 YYYY-MM-DD 值，拒绝 PHP 自动归一化出来的其他日期。 */
    private function date(?string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new JobCenterInvalid('Invalid task date filter.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new JobCenterInvalid('Invalid task date filter.');
        }

        return $date;
    }

    /**
     * 合并各可见事实来源的筛选项，并按 ID 去重、显示名排序。
     *
     * 选项只能来自已经授权的任务投影，不查询独立用户目录；同一库或发起者跨任务类型出现时只保留
     * 一项。该方法只处理已脱敏的 ID/label 对，不接受浏览器值。
     *
     * @param array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>} $left
     * @param array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>} $right
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    private function mergeFilterOptions(array $left, array $right): array
    {
        $result = [];
        foreach (['libraries', 'requesters'] as $key) {
            $byId = [];
            foreach (array_merge($left[$key], $right[$key]) as $option) {
                $byId[$option['id']] = $option;
            }
            $result[$key] = array_values($byId);
            usort($result[$key], static fn (array $a, array $b): int => $a['label'] <=> $b['label']);
        }

        return $result;
    }
}
