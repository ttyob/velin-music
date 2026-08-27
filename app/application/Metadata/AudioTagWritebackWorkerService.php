<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Scrape\AudioMetadataRewrite;
use app\application\Scrape\AudioMetadataWriter;
use app\application\Scrape\FfmpegAudioMetadataWriter;
use app\application\Scrape\ScrapeMetadataCandidate;
use app\application\Scrape\ScrapePipelineFailed;
use app\infrastructure\Audit\AuditLogger;
use Closure;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 消费音频标签写回任务，并维护文件替换、库存身份和增量扫描之间的补偿状态机。
 *
 * 本服务是最终音乐库音频标签写入的唯一执行入口。它在文件 I/O 前重新校验不可变方案、全部字段
 * 版本、歌曲/音乐库版本、库存四元身份和真实 stat；随后通过无重编码写入器保留完整原文件备份。
 * 文件系统与 SQLite 无法形成同一事务，因此数据库提交失败时必须恢复原字节；成功提交后才删除
 * 备份。任务、审计、SSE 和操作日志只保存对象 ID、稳定错误码、大小和摘要，绝不保存物理路径或
 * 标签值。
 */
final class AudioTagWritebackWorkerService
{
    private const LEASE_SECONDS = 900;
    /** @var Closure(string,array<string,mixed>):void 已脱敏运维告警出口。 */
    private readonly Closure $warningLogger;

    public function __construct(
        private readonly AudioMetadataWriter $writer = new FfmpegAudioMetadataWriter(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?Closure $warningLogger = null,
    ) {
        // 生产默认进入 Webman 结构化日志；测试可注入内存出口验证提交后清理异常而不初始化 HTTP 路由。
        $this->warningLogger = $warningLogger ?? static function (string $message, array $context): void {
            Log::warning($message, $context);
        };
    }

    /**
     * 使用状态 CAS 领取最早的 queued 任务，并同步推进方案。
     *
     * 多个 Worker 即使误配置也只有一个能把同一任务从 queued 改为 running。返回值只含任务 ID，
     * 执行阶段必须重新读取所有权威事实。
     *
     * @return array{id:string}|null
     */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('audio_tag_writeback_jobs')->where('status', 'queued')
                ->orderBy('created_at')->first(['id', 'plan_id']);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('audio_tag_writeback_jobs')->where('id', (string) $row->id)
                ->where('status', 'queued')->where('attempt', '<', 5)->update([
                    'status' => 'running', 'phase' => 'validating', 'attempt' => Db::raw('attempt + 1'),
                    'worker_id' => $workerId, 'heartbeat_at' => $now, 'started_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) return null;
            $planChanged = Db::table('audio_tag_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'queued')->update(['status' => 'running', 'updated_at' => $now]);
            if ($planChanged !== 1) {
                throw new AudioTagWritebackFailed('AUDIO_TAG_PLAN_STATE_INVALID', '音频标签写回方案状态无效。');
            }
            return ['id' => (string) $row->id];
        });
    }

    /**
     * 执行已领取任务，成功后更新同一库存记录而不改变歌曲稳定 ID。
     *
     * 写入器返回后完整备份仍存在；本方法复验新普通文件并在短事务内以旧库存身份作 CAS，更新 inode、
     * 大小和 mtime，同时使字节哈希/声学指纹进入待重建状态并写逐库扫描 Outbox。事务异常先回滚文件，
     * 再记录失败终态，不能留下数据库仍指向旧 inode 的成功任务。
     *
     * @param array{id:string} $claimed
     */
    public function execute(array $claimed): void
    {
        $jobId = $claimed['id'] ?? '';
        if (!is_string($jobId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_JOB_INVALID', '音频标签写回任务标识无效。');
        }
        $row = $this->row($jobId);
        $rewrite = null;
        try {
            [$candidate, $targetPath] = $this->validate($row);
            $this->phase($jobId, 'writing');
            $this->startOperation($jobId, 1, 'rewrite', (int) $row->source_link_count > 1);
            try {
                $rewrite = $this->writer->rewrite($targetPath, $candidate, $jobId);
            } catch (ScrapePipelineFailed $failure) {
                throw new AudioTagWritebackFailed($failure->errorCode, '音频容器拒绝标签写回。', $failure);
            }
            $this->phase($jobId, 'verifying');
            $identity = $this->writtenIdentity($targetPath);
            $this->finishOperation($jobId, 1, 'succeeded', null, $rewrite->detachedLink, $identity);
            $this->startOperation($jobId, 2, 'verify', $rewrite->detachedLink);
            $this->finishOperation($jobId, 2, 'succeeded', null, $rewrite->detachedLink, $identity);
            $this->phase($jobId, 'persisting');
            $this->complete($row, $identity);
            // 数据库已提交后绝不能再回滚音频；备份清理异常只保留受控残留并记录无路径运维事实。
            $committedRewrite = $rewrite;
            $rewrite = null;
            try {
                $this->writer->commit($committedRewrite);
            } catch (Throwable $cleanupFailure) {
                try {
                    $this->recordCleanupFailure($row, $committedRewrite->detachedLink, $cleanupFailure);
                } catch (Throwable) {
                    // 成功事务后的诊断失败也不能反向进入文件补偿或改变已提交任务终态。
                }
            }
        } catch (AudioTagWritebackFailed $failure) {
            $code = $failure->reasonCode;
            if ($rewrite instanceof AudioMetadataRewrite) {
                $code = $this->compensate($row, $rewrite)
                    ? 'AUDIO_TAG_DATABASE_COMMIT_FAILED'
                    : 'AUDIO_TAG_COMPENSATION_FAILED';
            }
            $this->finishOpenOperation($jobId, $code);
            $this->fail($row, $code, $this->isStaleCode($failure->reasonCode));
        } catch (Throwable $failure) {
            $code = 'AUDIO_TAG_WORKER_FAILED';
            if ($rewrite instanceof AudioMetadataRewrite) {
                $code = $this->compensate($row, $rewrite)
                    ? 'AUDIO_TAG_DATABASE_COMMIT_FAILED'
                    : 'AUDIO_TAG_COMPENSATION_FAILED';
            }
            $this->finishOpenOperation($jobId, $code);
            $this->fail($row, $code, false);
        } finally {
            // 单曲结果是执行事实，父批次只是可重建投影。终态提交后立即重建可避免任务中心依赖
            // 某个浏览器继续轮询；同步异常不允许反向改变已经落盘并提交的单曲结果。
            if (isset($row) && $row instanceof stdClass) {
                $this->synchronizeBatch((string) $row->plan_id);
            }
        }
    }

    /**
     * 在批量迁移已可用且单曲方案确实属于批次时同步父状态。
     *
     * 滚动升级期间目标表可能尚不存在，此时安全跳过。同步只读取任务头并更新批量计数，不读取标签
     * 快照或文件；失败仅写脱敏告警，后续批量详情读取会再次执行相同的幂等重建。
     */
    private function synchronizeBatch(string $planId): void
    {
        try {
            $schema = Db::connection()->getSchemaBuilder();
            if (!$schema->hasTable('audio_tag_writeback_batch_targets')) return;
            $batchId = Db::table('audio_tag_writeback_batch_targets')
                ->where('writeback_plan_id', $planId)->value('batch_plan_id');
            if (is_string($batchId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $batchId) === 1) {
                (new AudioTagWritebackBatchStateService())->synchronize($batchId);
            }
        } catch (Throwable $error) {
            try {
                ($this->warningLogger)('Audio tag batch synchronization failed after child terminal state.', [
                    'plan_id' => $planId,
                    'exception_class' => $error::class,
                ]);
            } catch (Throwable) {
                // 告警出口失败同样不能污染已确定的媒体与任务终态。
            }
        }
    }

    /**
     * 保守终止过期 running 租约。
     *
     * 操作日志不能证明崩溃发生在重命名前还是数据库提交后，自动重放可能再次替换文件，所以只标记
     * 稳定失败并保留现场供管理员重新生成方案，不尝试猜测性删除或恢复临时文件。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('audio_tag_writeback_jobs')->where('status', 'running')
            ->where('heartbeat_at', '<', $threshold)->get(['id', 'plan_id', 'heartbeat_at'])->all();
        foreach ($rows as $row) {
            Db::transaction(function () use ($row): void {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $changed = Db::table('audio_tag_writeback_jobs')->where('id', (string) $row->id)
                    ->where('status', 'running')->where('heartbeat_at', (string) $row->heartbeat_at)->update([
                        'status' => 'failed', 'phase' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
                        'finished_at' => $now, 'error_code' => 'AUDIO_TAG_LEASE_EXPIRED', 'updated_at' => $now,
                    ]);
                if ($changed === 1) {
                    Db::table('audio_tag_writeback_plans')->where('id', (string) $row->plan_id)
                        ->where('status', 'running')->update([
                            'status' => 'failed', 'finished_at' => $now,
                            'error_code' => 'AUDIO_TAG_LEASE_EXPIRED', 'updated_at' => $now,
                        ]);
                }
            });
        }
    }

    /**
     * 将成功写回产生的逐库 Outbox 合并为普通增量扫描。
     *
     * 已有活动扫描时保持 pending；已入队扫描终结后删除请求。这样写回发生在扫描尾声时不会漏掉
     * 新标签，且重复任务只会为同一音乐库形成一条待处理请求。本方法不读取媒体文件。
     */
    public function flushScanRequests(): void
    {
        /** @var list<stdClass> $enqueued */
        $enqueued = Db::table('metadata_writeback_scan_requests')->where('status', 'enqueued')
            ->whereNotNull('scan_job_id')->get(['library_id', 'scan_job_id'])->all();
        foreach ($enqueued as $request) {
            $status = Db::table('library_scan_jobs')->where('id', (string) $request->scan_job_id)->value('status');
            if ($status === null || in_array($status, ['succeeded', 'failed', 'cancelled'], true)) {
                Db::table('metadata_writeback_scan_requests')->where('library_id', (string) $request->library_id)
                    ->where('scan_job_id', (string) $request->scan_job_id)->delete();
            }
        }
        /** @var list<stdClass> $pending */
        $pending = Db::table('metadata_writeback_scan_requests')->where('status', 'pending')
            ->orderBy('first_requested_at')->get()->all();
        foreach ($pending as $request) {
            Db::transaction(function () use ($request): void {
                $stillPending = Db::table('metadata_writeback_scan_requests')
                    ->where('library_id', (string) $request->library_id)->where('status', 'pending')->exists();
                $active = Db::table('library_scan_jobs')->where('library_id', (string) $request->library_id)
                    ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists();
                if (!$stillPending || $active) return;
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $scanJobId = (string) new Ulid();
                Db::table('library_scan_jobs')->insert([
                    'id' => $scanJobId, 'library_id' => (string) $request->library_id, 'requested_by' => null,
                    'scan_type' => 'incremental', 'status' => 'queued', 'phase' => 'queued',
                    'processed_entries' => 0, 'discovered_files' => 0, 'added_files' => 0, 'missing_files' => 0,
                    'ignored_entries' => 0, 'failed_entries' => 0, 'attempt' => 0, 'worker_id' => null,
                    'heartbeat_at' => null, 'cancel_requested_at' => null, 'started_at' => null,
                    'finished_at' => null, 'error_code' => null, 'error_message' => null,
                    'request_id' => 'audio-tag-writeback:' . $scanJobId, 'created_at' => $now, 'updated_at' => $now,
                ]);
                Db::table('music_libraries')->where('id', (string) $request->library_id)->update([
                    'scan_status' => 'queued', 'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
                Db::table('metadata_writeback_scan_requests')->where('library_id', (string) $request->library_id)
                    ->where('status', 'pending')->update([
                        'status' => 'enqueued', 'scan_job_id' => $scanJobId,
                        'enqueued_at' => $now, 'updated_at' => $now,
                    ]);
            });
        }
    }

    /** 读取一个 running 任务及其方案和当前文件事实；查询结果不对外暴露。 */
    private function row(string $jobId): stdClass
    {
        /** @var stdClass|null $row */
        $row = Db::table('audio_tag_writeback_jobs as jobs')
            ->join('audio_tag_writeback_plans as plans', 'plans.id', '=', 'jobs.plan_id')
            ->join('media_songs as songs', 'songs.id', '=', 'plans.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'plans.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'plans.library_id')
            ->where('jobs.id', $jobId)->where('jobs.status', 'running')->where('plans.status', 'running')
            ->first([
                'jobs.id as job_id', 'jobs.plan_id', 'jobs.requested_by', 'jobs.request_id',
                'plans.song_id', 'plans.library_id', 'plans.inventory_file_id', 'plans.expected_song_updated_at',
                'plans.expected_library_version', 'plans.field_versions_json', 'plans.metadata_snapshot_json',
                'plans.metadata_sha256', 'plans.source_relative_path', 'plans.source_device', 'plans.source_inode',
                'plans.source_file_size', 'plans.source_modified_at', 'plans.source_link_count',
                'songs.library_id as song_library_id', 'songs.inventory_file_id as song_inventory_file_id',
                'songs.updated_at as song_updated_at', 'files.library_id as file_library_id', 'files.relative_path',
                'files.resolved_path', 'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
                'files.status as file_status', 'files.metadata_status', 'libraries.resolved_root_path',
                'libraries.status as library_status', 'libraries.version as library_version',
            ]);
        if (!$row instanceof stdClass) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_JOB_STATE_INVALID', '音频标签写回任务状态无效。');
        }
        return $row;
    }

    /** @return array{0:ScrapeMetadataCandidate,1:string} */
    private function validate(stdClass $row): array
    {
        $ownership = (string) $row->song_library_id === (string) $row->library_id
            && (string) $row->file_library_id === (string) $row->library_id
            && (string) $row->song_inventory_file_id === (string) $row->inventory_file_id;
        if (!$ownership || (string) $row->library_status !== 'active'
            || (string) $row->file_status !== 'available' || (string) $row->metadata_status !== 'ready') {
            throw new AudioTagWritebackFailed('AUDIO_TAG_SCOPE_STALE', '音频标签写回对象范围已变化。');
        }
        if ((string) $row->song_updated_at !== (string) $row->expected_song_updated_at
            || (int) $row->library_version !== (int) $row->expected_library_version) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_PLAN_STALE', '歌曲或音乐库版本已变化。');
        }
        try {
            $expected = json_decode((string) $row->field_versions_json, true, 64, JSON_THROW_ON_ERROR);
            $candidate = ScrapeMetadataCandidate::fromJson((string) $row->metadata_snapshot_json);
        } catch (Throwable $failure) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_SNAPSHOT_INVALID', '音频标签写回快照无效。', $failure);
        }
        if (!is_array($expected) || array_is_list($expected)
            || !hash_equals((string) $row->metadata_sha256, hash('sha256', (string) $row->metadata_snapshot_json))) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_SNAPSHOT_INVALID', '音频标签写回快照无效。');
        }
        $current = [];
        /** @var list<stdClass> $versions */
        $versions = Db::table('media_metadata_field_states')->where('song_id', (string) $row->song_id)
            ->orderBy('field_key')->get(['field_key', 'version'])->all();
        foreach ($versions as $version) $current[(string) $version->field_key] = (int) $version->version;
        ksort($expected);
        if ($current !== $expected) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_FIELDS_STALE', '歌曲字段版本已变化。');
        }
        if ((string) $row->relative_path !== (string) $row->source_relative_path
            || (int) $row->device_id !== (int) $row->source_device
            || (int) $row->inode !== (int) $row->source_inode
            || (int) $row->file_size !== (int) $row->source_file_size
            || (int) $row->modified_at !== (int) $row->source_modified_at) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_AUDIO_STALE', '音频库存身份已变化。');
        }
        $root = realpath((string) $row->resolved_root_path);
        $configured = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR);
        $target = $configured . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, (string) $row->source_relative_path);
        // Worker 是长驻进程；确认后同 inode 原地改写时必须绕过 PHP stat 缓存读取当前文件身份。
        clearstatcache(true, $target);
        if ($root === false || $root !== $configured || realpath($target) !== $target
            || (string) $row->resolved_path !== $target || is_link($target) || !is_file($target)
            || !is_readable($target) || !is_writable($target)) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_PATH_STALE', '音频路径或权限已变化。');
        }
        $stat = @stat($target);
        if (!is_array($stat) || (int) $stat['dev'] !== (int) $row->source_device
            || (int) $stat['ino'] !== (int) $row->source_inode || (int) $stat['size'] !== (int) $row->source_file_size
            || (int) $stat['mtime'] !== (int) $row->source_modified_at
            || max(1, (int) ($stat['nlink'] ?? 1)) !== (int) $row->source_link_count) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_AUDIO_STALE', '音频真实身份已变化。');
        }
        return [$candidate, $target];
    }

    /** @return array{device:int,inode:int,size:int,mtime:int,sha256:string} */
    private function writtenIdentity(string $target): array
    {
        clearstatcache(true, $target);
        $stat = @stat($target);
        $sha256 = is_file($target) && !is_link($target) ? @hash_file('sha256', $target) : false;
        if (!is_array($stat) || !is_string($sha256) || strlen($sha256) !== 64) {
            throw new AudioTagWritebackFailed('AUDIO_TAG_OUTPUT_INVALID', '写回结果身份或摘要无效。');
        }
        return ['device' => (int) $stat['dev'], 'inode' => (int) $stat['ino'], 'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'], 'sha256' => $sha256];
    }

    /** 更新任务阶段与租约心跳，已终结任务不能被迟到 Worker 复活。 */
    private function phase(string $jobId, string $phase): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('audio_tag_writeback_jobs')->where('id', $jobId)->where('status', 'running')
            ->update(['phase' => $phase, 'heartbeat_at' => $now, 'updated_at' => $now]);
        if ($changed !== 1) throw new AudioTagWritebackFailed('AUDIO_TAG_JOB_STATE_INVALID', '任务状态已变化。');
    }

    /** 文件 I/O 前持久化无路径操作意图，崩溃后不依赖内存判断是否开始过替换。 */
    private function startOperation(string $jobId, int $sequence, string $operation, bool $detached): void
    {
        Db::table('audio_tag_writeback_operation_logs')->insert([
            'id' => (string) new Ulid(), 'job_id' => $jobId, 'sequence' => $sequence,
            'operation' => $operation, 'status' => 'started', 'detached_hardlink' => $detached ? 1 : 0,
            'output_sha256' => null, 'output_size_bytes' => null, 'error_code' => null,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'), 'finished_at' => null,
        ]);
    }

    /** @param array{device:int,inode:int,size:int,mtime:int,sha256:string}|null $identity */
    private function finishOperation(string $jobId, int $sequence, string $status, ?string $errorCode,
        bool $detached = false, ?array $identity = null): void
    {
        Db::table('audio_tag_writeback_operation_logs')->where('job_id', $jobId)
            ->where('sequence', $sequence)->where('status', 'started')->update([
                'status' => $status, 'detached_hardlink' => $detached ? 1 : 0,
                'output_sha256' => $identity['sha256'] ?? null, 'output_size_bytes' => $identity['size'] ?? null,
                'error_code' => $errorCode, 'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /** 将最后一个 started 步骤标记失败；不存在开放步骤时不改写历史。 */
    private function finishOpenOperation(string $jobId, string $errorCode): void
    {
        /** @var stdClass|null $operation */
        $operation = Db::table('audio_tag_writeback_operation_logs')->where('job_id', $jobId)
            ->where('status', 'started')->orderByDesc('sequence')->first(['sequence']);
        if ($operation instanceof stdClass) {
            $this->finishOperation($jobId, (int) $operation->sequence, 'failed', $errorCode);
        }
    }

    /** @param array{device:int,inode:int,size:int,mtime:int,sha256:string} $identity */
    private function complete(stdClass $row, array $identity): void
    {
        Db::transaction(function () use ($identity, $row): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $inventoryChanged = Db::table('library_file_inventory')->where('id', (string) $row->inventory_file_id)
                ->where('device_id', (int) $row->source_device)->where('inode', (int) $row->source_inode)
                ->where('file_size', (int) $row->source_file_size)->where('modified_at', (int) $row->source_modified_at)
                ->update([
                    'device_id' => $identity['device'], 'inode' => $identity['inode'],
                    'file_size' => $identity['size'], 'modified_at' => $identity['mtime'],
                    'metadata_signature' => null, 'byte_hash_status' => 'pending', 'byte_sha256' => null,
                    'acoustic_fingerprint_status' => 'pending', 'acoustic_fingerprint_sha256' => null,
                    'acoustic_duration_seconds' => null, 'duplicate_evidence_signature' => null,
                    'duplicate_evidence_error_code' => null, 'updated_at' => $now,
                ]);
            $jobChanged = Db::table('audio_tag_writeback_jobs')->where('id', (string) $row->job_id)
                ->where('status', 'running')->update([
                    'status' => 'succeeded', 'phase' => 'completed', 'worker_id' => null, 'heartbeat_at' => null,
                    'finished_at' => $now, 'error_code' => null, 'updated_at' => $now,
                ]);
            $planChanged = Db::table('audio_tag_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'running')->update([
                    'status' => 'succeeded', 'finished_at' => $now, 'error_code' => null, 'updated_at' => $now,
                ]);
            if ($inventoryChanged !== 1 || $jobChanged !== 1 || $planChanged !== 1) {
                throw new AudioTagWritebackFailed('AUDIO_TAG_COMMIT_CONFLICT', '写回完成状态发生冲突。');
            }
            $exists = Db::table('metadata_writeback_scan_requests')
                ->where('library_id', (string) $row->library_id)->exists();
            if ($exists) {
                Db::table('metadata_writeback_scan_requests')->where('library_id', (string) $row->library_id)
                    ->update(['status' => 'pending', 'scan_job_id' => null, 'last_requested_at' => $now,
                        'enqueued_at' => null, 'updated_at' => $now]);
            } else {
                Db::table('metadata_writeback_scan_requests')->insert([
                    'library_id' => (string) $row->library_id, 'status' => 'pending', 'scan_job_id' => null,
                    'first_requested_at' => $now, 'last_requested_at' => $now, 'enqueued_at' => null,
                    'updated_at' => $now,
                ]);
            }
            $this->audit->record((string) $row->requested_by, 'metadata.audio_tags.job.complete',
                'audio_tag_writeback_job', (string) $row->job_id, 'success', (string) $row->request_id,
                ['planId' => (string) $row->plan_id, 'songId' => (string) $row->song_id,
                    'libraryId' => (string) $row->library_id, 'detachedHardlink' => (int) $row->source_link_count > 1]);
        });
    }

    /** 恢复原文件并用方案冻结身份复验补偿，不把备份或目标绝对路径写入任何事实表。 */
    private function compensate(stdClass $row, AudioMetadataRewrite $rewrite): bool
    {
        $this->writer->rollback($rewrite);
        clearstatcache(true, $rewrite->targetPath);
        $stat = @stat($rewrite->targetPath);
        $ok = is_array($stat) && (int) $stat['dev'] === (int) $row->source_device
            && (int) $stat['ino'] === (int) $row->source_inode && (int) $stat['size'] === (int) $row->source_file_size
            && (int) $stat['mtime'] === (int) $row->source_modified_at;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('audio_tag_writeback_operation_logs')->insert([
            'id' => (string) new Ulid(), 'job_id' => (string) $row->job_id, 'sequence' => 3,
            'operation' => 'compensate', 'status' => $ok ? 'compensated' : 'failed',
            'detached_hardlink' => $rewrite->detachedLink ? 1 : 0, 'output_sha256' => null,
            'output_size_bytes' => null, 'error_code' => $ok ? null : 'AUDIO_TAG_COMPENSATION_FAILED',
            'started_at' => $now, 'finished_at' => $now,
        ]);
        return $ok;
    }

    /**
     * 数据库成功后的备份删除失败只记录安全告警，不能恢复旧文件或把成功任务改成失败。
     *
     * 此时库存已经原子指向新 inode，回滚会制造数据库/磁盘分裂。操作日志不保存备份名或路径；清理
     * 实现必须只留下当前任务精确命名的受控文件，后续维护流程可据稳定错误码处理。
     */
    private function recordCleanupFailure(stdClass $row, bool $detached, Throwable $failure): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::table('audio_tag_writeback_operation_logs')->insert([
                'id' => (string) new Ulid(), 'job_id' => (string) $row->job_id, 'sequence' => 3,
                'operation' => 'backup', 'status' => 'failed', 'detached_hardlink' => $detached ? 1 : 0,
                'output_sha256' => null, 'output_size_bytes' => null,
                'error_code' => 'AUDIO_TAG_BACKUP_CLEANUP_FAILED', 'started_at' => $now, 'finished_at' => $now,
            ]);
        } catch (Throwable $loggingFailure) {
            try {
                ($this->warningLogger)('Audio tag backup cleanup and operation logging failed.', [
                    'job_id' => (string) $row->job_id,
                    'cleanup_exception_class' => $failure::class,
                    'logging_exception_class' => $loggingFailure::class,
                ]);
            } catch (Throwable) {
                // 数据库与文件已成功提交，最后的运维日志不可成为回滚触发器。
            }
            return;
        }
        try {
            ($this->warningLogger)('Audio tag backup cleanup failed after database commit.', [
                'job_id' => (string) $row->job_id,
                'exception_class' => $failure::class,
            ]);
        } catch (Throwable) {
            // 操作日志已经持久化，日志后端故障不能改变成功终态。
        }
    }

    /** 提交失败终态和脱敏审计；方案陈旧与执行故障保持可区分。 */
    private function fail(stdClass $row, string $errorCode, bool $stale): void
    {
        Db::transaction(function () use ($errorCode, $row, $stale): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('audio_tag_writeback_jobs')->where('id', (string) $row->job_id)
                ->where('status', 'running')->update([
                    'status' => 'failed', 'phase' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
                    'finished_at' => $now, 'error_code' => $errorCode, 'updated_at' => $now,
                ]);
            Db::table('audio_tag_writeback_plans')->where('id', (string) $row->plan_id)
                ->where('status', 'running')->update([
                    'status' => $stale ? 'stale' : 'failed', 'finished_at' => $now,
                    'error_code' => $errorCode, 'updated_at' => $now,
                ]);
            $this->audit->record((string) $row->requested_by, 'metadata.audio_tags.job.complete',
                'audio_tag_writeback_job', (string) $row->job_id, 'failure', (string) $row->request_id,
                ['planId' => (string) $row->plan_id, 'songId' => (string) $row->song_id,
                    'libraryId' => (string) $row->library_id, 'errorCode' => $errorCode]);
        });
    }

    /** 只有执行前权威事实漂移才将方案标成 stale；容器或基础设施失败保持普通 failed。 */
    private function isStaleCode(string $code): bool
    {
        return in_array($code, [
            'AUDIO_TAG_SCOPE_STALE', 'AUDIO_TAG_PLAN_STALE', 'AUDIO_TAG_FIELDS_STALE',
            'AUDIO_TAG_AUDIO_STALE', 'AUDIO_TAG_PATH_STALE', 'AUDIO_TAG_SNAPSHOT_INVALID',
        ], true);
    }
}
