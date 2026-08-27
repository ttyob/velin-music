<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 消费已确认的歌词写回任务并维护文件系统与数据库之间的补偿状态机。
 *
 * Worker 是唯一允许执行 sidecar I/O 的调用方。领取使用数据库状态 CAS；执行时重新校验音乐库、
 * 歌曲、歌词版本/许可/摘要、清单路径和音频四元身份，随后调用独占发布器。文件系统和 SQLite
 * 不能构成单一事务，因此先持久化 started 操作日志，发布后再短事务提交成功状态与逐库扫描
 * Outbox；提交失败时按发布结果的完整身份精确补偿。日志、审计、错误和 Outbox 均不保存歌词正文。
 */
final class LyricsWritebackWorkerService
{
    private const LEASE_SECONDS = 900;

    public function __construct(
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
        private readonly LyricsSidecarPublisher $publisher = new LyricsSidecarPublisher(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    ) {
    }

    /**
     * 领取最早的 queued 任务并同步推进方案状态。
     *
     * 返回值只含任务 ID；完整快照在执行前重新查询，避免把领取前读取的正文或路径当作可信状态。
     * 重复 Worker 竞争通过 `status = queued` 更新谓词安全落败。
     *
     * @return array{id:string}|null
     */
    public function claimNext(string $workerId): ?array
    {
        $claimed = Db::transaction(function () use ($workerId): ?array {
            $schema = Db::connection()->getSchemaBuilder();
            /** @var stdClass|null $row */
            $row = Db::table('lyrics_writeback_jobs')->where('status', 'queued')
                ->orderBy('created_at')->first(['id', 'plan_id']);
            if (!$row instanceof stdClass) {
                return null;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('lyrics_writeback_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->where('attempt', '<', 5)->update([
                    'status' => 'running',
                    'phase' => 'validating',
                    'attempt' => Db::raw('attempt + 1'),
                    'worker_id' => $workerId,
                    'heartbeat_at' => $now,
                    'started_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                return null;
            }
            $planChanged = Db::table('lyrics_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => $now]);
            if ($planChanged !== 1) {
                throw new LyricsWritebackFailed('LYRICS_PLAN_STATE_INVALID', '写回方案状态无效。');
            }

            return ['id' => (string) $row->id];
        });
        if ($claimed !== null) {
            $planId = Db::table('lyrics_writeback_jobs')->where('id', $claimed['id'])->value('plan_id');
            if (is_string($planId)) {
                $this->synchronizeBatchForPlan($planId);
            }
        }

        return $claimed;
    }

    /**
     * 执行一个已领取任务；调用者不得传入歌词、路径或方案字段。
     *
     * 正常失败会进入终态且不向循环抛出；只有任务头已不存在等无法可靠归属的基础设施错误可能由
     * 数据库层抛出。成功后的不变量是目标为本任务独占创建且摘要匹配、任务/方案成功、扫描请求已
     * 持久化。数据库提交失败时先补偿目标，再记录失败；补偿失败会保留目标和明确错误码供人工核对。
     *
     * @param array{id:string} $claimed
     */
    public function execute(array $claimed): void
    {
        $jobId = $claimed['id'] ?? '';
        if (!is_string($jobId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            throw new LyricsWritebackFailed('LYRICS_JOB_INVALID', '歌词写回任务标识无效。');
        }
        /** @var stdClass|null $row */
        $row = Db::table('lyrics_writeback_jobs as jobs')
            ->join('lyrics_writeback_plans as plans', 'plans.id', '=', 'jobs.plan_id')
            ->join('media_lyrics as lyrics', 'lyrics.id', '=', 'plans.lyric_id')
            ->join('media_songs as songs', 'songs.id', '=', 'plans.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'plans.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'plans.library_id')
            ->where('jobs.id', $jobId)
            ->where('jobs.status', 'running')
            ->where('plans.status', 'running')
            ->first([
                'jobs.id as job_id', 'jobs.plan_id', 'jobs.requested_by', 'jobs.request_id',
                'plans.song_id', 'plans.lyric_id', 'plans.library_id', 'plans.inventory_file_id',
                'plans.expected_lyric_version', 'plans.expected_library_version',
                'plans.source_kind as planned_source_kind', 'plans.source_format as planned_source_format',
                'plans.language as planned_language', 'plans.lyric_kind as planned_lyric_kind',
                'plans.match_score as planned_match_score', 'plans.license_policy as planned_license_policy',
                'plans.lyric_content_sha256', 'plans.output_sha256', 'plans.output_size_bytes',
                'plans.source_device', 'plans.source_inode', 'plans.source_file_size',
                'plans.source_modified_at', 'plans.target_relative_path', 'plans.replace_existing',
                'plans.existing_target_device', 'plans.existing_target_inode',
                'plans.existing_target_size', 'plans.existing_target_modified_at',
                'plans.existing_target_sha256',
                'lyrics.song_id as lyric_song_id', 'lyrics.source_kind', 'lyrics.source_format',
                'lyrics.language', 'lyrics.lyric_kind', 'lyrics.match_score', 'lyrics.license_policy',
                'lyrics.content_sha256', 'lyrics.version as lyric_version',
                'songs.library_id as song_library_id', 'songs.inventory_file_id as song_inventory_file_id',
                'files.library_id as file_library_id', 'files.relative_path', 'files.resolved_path',
                'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
                'files.status as file_status', 'files.metadata_status',
                'libraries.resolved_root_path', 'libraries.status as library_status',
                'libraries.version as library_version',
            ]);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackFailed('LYRICS_JOB_STATE_INVALID', '歌词写回任务状态无效。');
        }

        $published = null;
        try {
            $content = $this->validateAndSerialize($row);
            $this->phase($jobId, 'writing');
            $this->startOperation($jobId, 1, 'write_temp', (string) $row->target_relative_path);
            $published = $this->publisher->publish(
                (string) $row->resolved_root_path,
                (string) $row->relative_path,
                (string) $row->target_relative_path,
                [
                    'device' => (int) $row->source_device,
                    'inode' => (int) $row->source_inode,
                    'size' => (int) $row->source_file_size,
                    'modifiedAt' => (int) $row->source_modified_at,
                ],
                $content,
                (string) $row->output_sha256,
                $this->expectedTargetIdentity($row),
            );
            $this->finishOperation($jobId, 1, 'succeeded', null, $published);
            $this->phase($jobId, 'publishing');
            $this->startOperation($jobId, 2, 'verify', (string) $row->target_relative_path);
            $this->finishOperation($jobId, 2, 'succeeded', null, $published);
            $this->phase($jobId, 'indexing');
            $this->complete($row);
            if (!$this->publisher->finalize($published)) {
                // 数据库和新 sidecar 已经提交，此时只能报告脱敏诊断，禁止回滚成功事实。
                Log::warning('歌词 sidecar 替换备份清理失败。', [
                    'jobId' => (string) $row->job_id,
                    'errorCode' => 'LYRICS_BACKUP_CLEANUP_FAILED',
                ]);
            }
        } catch (LyricsWritebackFailed $failure) {
            $code = $failure->reasonCode;
            if ($published instanceof LyricsSidecarPublishResult) {
                $compensated = $this->publisher->compensate($published);
                $this->recordCompensation($row, $compensated);
                $code = $compensated ? 'LYRICS_DATABASE_COMMIT_FAILED' : 'LYRICS_COMPENSATION_FAILED';
            }
            $this->finishOpenOperation($jobId, $code);
            $this->fail($row, $code, $published === null && $this->isStaleCode($failure->reasonCode));
        } catch (Throwable) {
            if ($published instanceof LyricsSidecarPublishResult) {
                $compensated = $this->publisher->compensate($published);
                $this->recordCompensation($row, $compensated);
                $code = $compensated ? 'LYRICS_DATABASE_COMMIT_FAILED' : 'LYRICS_COMPENSATION_FAILED';
            } else {
                $code = 'LYRICS_WORKER_FAILED';
            }
            $this->finishOpenOperation($jobId, $code);
            $this->fail($row, $code, false);
        }
        $this->synchronizeBatchForPlan((string) $row->plan_id);
    }

    /**
     * 把超时 running 租约标记失败，不自动重试可能已经发布文件的任务。
     *
     * 操作日志无法证明进程中断点时，自动重放可能创建重复文件或误补偿，因此恢复策略是保守终止，
     * 由管理员查看目标冲突后重新生成方案。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('lyrics_writeback_jobs')->where('status', 'running')
            ->where('heartbeat_at', '<', $threshold)->get(['id', 'plan_id', 'heartbeat_at'])->all();
        foreach ($rows as $row) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::transaction(function () use ($now, $row): void {
                $changed = Db::table('lyrics_writeback_jobs')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                        'status' => 'failed',
                        'phase' => 'failed',
                        'worker_id' => null,
                        'heartbeat_at' => null,
                        'finished_at' => $now,
                        'error_code' => 'LYRICS_LEASE_EXPIRED',
                        'updated_at' => $now,
                    ]);
                if ($changed === 1) {
                    Db::table('lyrics_writeback_plans')->where('id', (string) $row->plan_id)
                        ->where('status', 'running')->update([
                            'status' => 'failed',
                            'finished_at' => $now,
                            'error_code' => 'LYRICS_LEASE_EXPIRED',
                            'updated_at' => $now,
                        ]);
                }
            });
            $this->synchronizeBatchForPlan((string) $row->plan_id);
        }
    }

    /**
     * 将逐库扫描 Outbox 合并为普通增量扫描。
     *
     * 活动扫描存在时请求保持 pending；引用的扫描终结后才删除 enqueued 行。这样 sidecar 即使在一次
     * 扫描尾声发布，也不会因为“当时已有扫描”而永久漏索引。事务不读取媒体文件。
     */
    public function flushScanRequests(): void
    {
        /** @var list<stdClass> $enqueued */
        $enqueued = Db::table('lyrics_writeback_scan_requests')->where('status', 'enqueued')
            ->whereNotNull('scan_job_id')->get(['library_id', 'scan_job_id'])->all();
        foreach ($enqueued as $request) {
            $status = Db::table('library_scan_jobs')->where('id', (string) $request->scan_job_id)->value('status');
            if (in_array($status, ['succeeded', 'failed', 'cancelled'], true) || $status === null) {
                Db::table('lyrics_writeback_scan_requests')->where('library_id', (string) $request->library_id)
                    ->where('scan_job_id', (string) $request->scan_job_id)->delete();
            }
        }
        /** @var list<stdClass> $pending */
        $pending = Db::table('lyrics_writeback_scan_requests')->where('status', 'pending')
            ->orderBy('first_requested_at')->get()->all();
        foreach ($pending as $request) {
            Db::transaction(function () use ($request): void {
                $stillPending = Db::table('lyrics_writeback_scan_requests')
                    ->where('library_id', (string) $request->library_id)->where('status', 'pending')->exists();
                $active = Db::table('library_scan_jobs')->where('library_id', (string) $request->library_id)
                    ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists();
                if (!$stillPending || $active) {
                    return;
                }
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $scanJobId = (string) new Ulid();
                Db::table('library_scan_jobs')->insert([
                    'id' => $scanJobId,
                    'library_id' => (string) $request->library_id,
                    'requested_by' => null,
                    'scan_type' => 'incremental',
                    'status' => 'queued',
                    'phase' => 'queued',
                    'processed_entries' => 0,
                    'discovered_files' => 0,
                    'added_files' => 0,
                    'missing_files' => 0,
                    'ignored_entries' => 0,
                    'failed_entries' => 0,
                    'attempt' => 0,
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'cancel_requested_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'request_id' => 'lyrics-writeback:' . $scanJobId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Db::table('music_libraries')->where('id', (string) $request->library_id)->update([
                    'scan_status' => 'queued',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
                Db::table('lyrics_writeback_scan_requests')->where('library_id', (string) $request->library_id)
                    ->where('status', 'pending')->update([
                        'status' => 'enqueued',
                        'scan_job_id' => $scanJobId,
                        'enqueued_at' => $now,
                        'updated_at' => $now,
                    ]);
            });
        }
    }

    /** 验证不可变方案与当前业务行一致，并返回仅在本调用栈存在的序列化正文。 */
    private function validateAndSerialize(stdClass $row): string
    {
        $sameOwnership = (string) $row->lyric_song_id === (string) $row->song_id
            && (string) $row->song_library_id === (string) $row->library_id
            && (string) $row->file_library_id === (string) $row->library_id
            && (string) $row->song_inventory_file_id === (string) $row->inventory_file_id;
        if (
            !$sameOwnership || (string) $row->library_status !== 'active'
            || (string) $row->file_status !== 'available' || (string) $row->metadata_status !== 'ready'
        ) {
            throw new LyricsWritebackFailed('LYRICS_SCOPE_STALE', '歌词写回对象范围已变化。');
        }
        if (
            (int) $row->lyric_version !== (int) $row->expected_lyric_version
            || (int) $row->library_version !== (int) $row->expected_library_version
            || (string) $row->source_kind !== (string) $row->planned_source_kind
            || (string) $row->source_format !== (string) $row->planned_source_format
            || (string) $row->language !== (string) $row->planned_language
            || (string) $row->lyric_kind !== (string) $row->planned_lyric_kind
            || ($row->match_score === null ? null : (float) $row->match_score)
                !== ($row->planned_match_score === null ? null : (float) $row->planned_match_score)
            || (string) $row->license_policy !== (string) $row->planned_license_policy
            || !in_array((string) $row->license_policy, ['local_controlled', 'redistributable'], true)
            || !hash_equals((string) $row->lyric_content_sha256, (string) $row->content_sha256)
        ) {
            throw new LyricsWritebackFailed('LYRICS_PLAN_STALE', '歌词或许可版本已变化。');
        }
        if (
            (int) $row->device_id !== (int) $row->source_device
            || (int) $row->inode !== (int) $row->source_inode
            || (int) $row->file_size !== (int) $row->source_file_size
            || (int) $row->modified_at !== (int) $row->source_modified_at
        ) {
            throw new LyricsWritebackFailed('LYRICS_AUDIO_STALE', '音频清单身份已变化。');
        }
        try {
            $parsed = $this->files->readById((string) $row->lyric_id);
            if ($parsed->kind !== (string) $row->lyric_kind) {
                throw new LyricsWritebackInvalid('歌词文件解析类型与索引不一致。');
            }
            $content = $this->serializer->serialize($parsed->kind, $parsed->lines);
        } catch (LyricsFileUnavailable|LyricsWritebackInvalid $exception) {
            throw new LyricsWritebackFailed('LYRICS_CONTENT_INVALID', '歌词文件无法安全写回。');
        }
        if (
            strlen($content) !== (int) $row->output_size_bytes
            || !hash_equals((string) $row->output_sha256, hash('sha256', $content))
        ) {
            throw new LyricsWritebackFailed('LYRICS_OUTPUT_STALE', '歌词输出摘要已变化。');
        }

        return $content;
    }

    /**
     * 从已确认方案重建旧目标 CAS 身份。
     *
     * 非替换任务返回 null 并继续走独占新建；替换任务只要有一个字段缺失就失败关闭，防止旧 schema、
     * 人工改库或损坏记录把“替换”降级成普通覆盖。
     *
     * @return null|array{device:int,inode:int,size:int,modifiedAt:int,sha256:string}
     */
    private function expectedTargetIdentity(stdClass $row): ?array
    {
        if ((int) ($row->replace_existing ?? 0) !== 1) {
            return null;
        }
        if (
            $row->existing_target_device === null || $row->existing_target_inode === null
            || $row->existing_target_size === null || $row->existing_target_modified_at === null
            || $row->existing_target_sha256 === null
        ) {
            throw new LyricsWritebackFailed('LYRICS_PLAN_STALE', '替换方案缺少旧歌词身份。');
        }

        return [
            'device' => (int) $row->existing_target_device,
            'inode' => (int) $row->existing_target_inode,
            'size' => (int) $row->existing_target_size,
            'modifiedAt' => (int) $row->existing_target_modified_at,
            'sha256' => (string) $row->existing_target_sha256,
        ];
    }

    /** 更新阶段和心跳；状态谓词防止已终结任务被迟到 Worker 复活。 */
    private function phase(string $jobId, string $phase): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('lyrics_writeback_jobs')->where('id', $jobId)->where('status', 'running')
            ->update(['phase' => $phase, 'heartbeat_at' => $now, 'updated_at' => $now]);
        if ($changed !== 1) {
            throw new LyricsWritebackFailed('LYRICS_JOB_STATE_INVALID', '歌词写回任务状态已变化。');
        }
    }

    /** 在任何文件 I/O 前插入无正文 started 日志。 */
    private function startOperation(string $jobId, int $sequence, string $operation, string $target): void
    {
        Db::table('lyrics_writeback_operation_logs')->insert([
            'id' => (string) new Ulid(),
            'job_id' => $jobId,
            'sequence' => $sequence,
            'operation' => $operation,
            'target_relative_path' => $target,
            'status' => 'started',
            'output_sha256' => null,
            'output_size_bytes' => null,
            'error_code' => null,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finished_at' => null,
        ]);
    }

    /** 完成一个操作日志；只记录摘要和大小，绝不记录实际内容或绝对路径。 */
    private function finishOperation(
        string $jobId,
        int $sequence,
        string $status,
        ?string $errorCode,
        ?LyricsSidecarPublishResult $published = null,
    ): void {
        Db::table('lyrics_writeback_operation_logs')->where('job_id', $jobId)
            ->where('sequence', $sequence)->where('status', 'started')->update([
                'status' => $status,
                'output_sha256' => $published?->sha256,
                'output_size_bytes' => $published?->size,
                'error_code' => $errorCode,
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /** 把最后一个未完成步骤标记失败；无开放步骤时保持历史不变。 */
    private function finishOpenOperation(string $jobId, string $errorCode): void
    {
        /** @var stdClass|null $row */
        $row = Db::table('lyrics_writeback_operation_logs')->where('job_id', $jobId)
            ->where('status', 'started')->orderByDesc('sequence')->first(['sequence']);
        if ($row instanceof stdClass) {
            $this->finishOperation($jobId, (int) $row->sequence, 'failed', $errorCode);
        }
    }

    /** 原子提交成功任务、方案、审计和逐库去重扫描请求。 */
    private function complete(stdClass $row): void
    {
        Db::transaction(function () use ($row): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $jobChanged = Db::table('lyrics_writeback_jobs')->where('id', (string) $row->job_id)
                ->where('status', 'running')->update([
                    'status' => 'succeeded',
                    'phase' => 'completed',
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'finished_at' => $now,
                    'error_code' => null,
                    'updated_at' => $now,
                ]);
            $planChanged = Db::table('lyrics_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'running')->update([
                    'status' => 'succeeded',
                    'finished_at' => $now,
                    'error_code' => null,
                    'updated_at' => $now,
                ]);
            if ($jobChanged !== 1 || $planChanged !== 1) {
                throw new LyricsWritebackFailed('LYRICS_COMMIT_CONFLICT', '歌词写回完成状态冲突。');
            }
            $existing = Db::table('lyrics_writeback_scan_requests')
                ->where('library_id', (string) $row->library_id)->exists();
            if ($existing) {
                Db::table('lyrics_writeback_scan_requests')->where('library_id', (string) $row->library_id)
                    ->update([
                        'status' => 'pending',
                        'scan_job_id' => null,
                        'last_requested_at' => $now,
                        'enqueued_at' => null,
                        'updated_at' => $now,
                    ]);
            } else {
                Db::table('lyrics_writeback_scan_requests')->insert([
                    'library_id' => (string) $row->library_id,
                    'status' => 'pending',
                    'scan_job_id' => null,
                    'first_requested_at' => $now,
                    'last_requested_at' => $now,
                    'enqueued_at' => null,
                    'updated_at' => $now,
                ]);
            }
            $this->audit->record(
                (string) $row->requested_by,
                'lyrics.writeback.job.complete',
                'lyrics_writeback_job',
                (string) $row->job_id,
                'success',
                (string) $row->request_id,
                [
                    'planId' => (string) $row->plan_id,
                    'songId' => (string) $row->song_id,
                    'lyricId' => (string) $row->lyric_id,
                    'libraryId' => (string) $row->library_id,
                ],
            );
        });
    }

    /** 记录数据库提交失败后的精确补偿结果，不把内部绝对路径写入日志。 */
    private function recordCompensation(stdClass $row, bool $compensated): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('lyrics_writeback_operation_logs')->insert([
            'id' => (string) new Ulid(),
            'job_id' => (string) $row->job_id,
            'sequence' => 3,
            'operation' => 'compensate',
            'target_relative_path' => (string) $row->target_relative_path,
            'status' => $compensated ? 'compensated' : 'failed',
            'output_sha256' => null,
            'output_size_bytes' => null,
            'error_code' => $compensated ? null : 'LYRICS_COMPENSATION_FAILED',
            'started_at' => $now,
            'finished_at' => $now,
        ]);
    }

    /** 提交失败终态和不含正文的审计；stale 与普通执行失败分别投影。 */
    private function fail(stdClass $row, string $errorCode, bool $stale): void
    {
        Db::transaction(function () use ($errorCode, $row, $stale): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('lyrics_writeback_jobs')->where('id', (string) $row->job_id)
                ->where('status', 'running')->update([
                    'status' => 'failed',
                    'phase' => 'failed',
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'finished_at' => $now,
                    'error_code' => $errorCode,
                    'updated_at' => $now,
                ]);
            Db::table('lyrics_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'running')->update([
                    'status' => $stale ? 'stale' : 'failed',
                    'finished_at' => $now,
                    'error_code' => $errorCode,
                    'updated_at' => $now,
                ]);
            $this->audit->record(
                (string) $row->requested_by,
                'lyrics.writeback.job.complete',
                'lyrics_writeback_job',
                (string) $row->job_id,
                'failure',
                (string) $row->request_id,
                [
                    'planId' => (string) $row->plan_id,
                    'songId' => (string) $row->song_id,
                    'lyricId' => (string) $row->lyric_id,
                    'libraryId' => (string) $row->library_id,
                    'errorCode' => $errorCode,
                ],
            );
        });
    }

    /** 这些错误证明预览 CAS 已失效，管理员必须新建方案而不能重试旧方案。 */
    private function isStaleCode(string $errorCode): bool
    {
        return in_array($errorCode, [
            'LYRICS_SCOPE_STALE',
            'LYRICS_PLAN_STALE',
            'LYRICS_AUDIO_STALE',
            'LYRICS_AUDIO_CHANGED',
            'LYRICS_ROOT_CHANGED',
            'LYRICS_OUTPUT_STALE',
            'LYRICS_TARGET_CONFLICT',
            'LYRICS_TARGET_STALE',
        ], true);
    }

    /**
     * 若单曲方案属于批量预览，则从当前子任务事实幂等重算父状态。
     *
     * 单曲写回可独立部署在尚未执行批量迁移的数据库上，因此先检查表存在。父状态更新失败不会改变
     * 已完成的文件事实；管理详情下次读取还会再次汇总，避免把派生进度更新当作文件提交条件。
     */
    private function synchronizeBatchForPlan(string $planId): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('lyrics_writeback_batch_targets')) {
            return;
        }
        $batchId = Db::table('lyrics_writeback_batch_targets')->where('writeback_plan_id', $planId)
            ->value('batch_plan_id');
        if (is_string($batchId)) {
            (new LyricsWritebackBatchStateService())->synchronize($batchId);
        }
    }
}
