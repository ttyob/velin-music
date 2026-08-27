<?php

declare(strict_types=1);

namespace app\application\Scan;

use app\application\User\HighCostJobPolicy;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * Owns administrator-visible scan commands and actor-scoped task projections.
 *
 * This service never traverses a library. Enqueue commits the durable job, library summary state,
 * and audit record in one short transaction. Object queries return not-found for both absent and
 * out-of-scope IDs so managers cannot enumerate another manager's task metadata.
 */
final class ScanJobService
{
    public function __construct(
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly HighCostJobPolicy $highCostJobs = new HighCostJobPolicy(),
    )
    {
    }

    /**
     * Lists a bounded, filtered task window after applying per-library management scope.
     *
     * @param array<string, mixed> $actor Authorized principal with global manage_library.
     * @return array{jobs: list<array<string, mixed>>, total: int}
     */
    public function listJobs(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $scanType,
        int $limit,
        int $offset = 0,
        ?string $requestedBy = null,
        ?string $createdFrom = null,
        ?string $createdBefore = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = $this->scopedJobQuery($actor);
        if (is_string($libraryId) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) === 1) {
            $query->where('jobs.library_id', $libraryId);
        }
        if (is_string($status) && in_array($status, [
            'queued', 'running', 'cancel_requested', 'cancelled', 'succeeded', 'failed',
        ], true)) {
            $query->where('jobs.status', $status);
        }
        if (is_string($scanType) && in_array($scanType, ['incremental', 'full'], true)) {
            $query->where('jobs.scan_type', $scanType);
        }
        if (is_string($requestedBy) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $requestedBy) === 1) {
            $query->where('jobs.requested_by', $requestedBy);
        }
        if (is_string($createdFrom) && $createdFrom !== '') {
            $query->where('jobs.created_at', '>=', $createdFrom);
        }
        if (is_string($createdBefore) && $createdBefore !== '') {
            $query->where('jobs.created_at', '<', $createdBefore);
        }

        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.created_at')->offset($offset)->limit($limit)->get($this->jobColumns())->all();

        return [
            'jobs' => array_map(fn (stdClass $row): array => $this->mapJob($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns filter vocabularies derived only from tasks in the actor's current manage scope.
     *
     * Requester IDs/names are already visible on authorized task rows. This query does not require
     * `manage_users`, enumerate users without tasks, or expose email, username, roles, or hidden
     * libraries. Options remain independent from active list filters so a selected value is stable.
     *
     * @return array{libraries: list<array{id: string, label: string}>, requesters: list<array{id: string, label: string}>}
     */
    public function filterOptions(array $actor): array
    {
        $base = $this->scopedJobQuery($actor);
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])
            ->distinct()->orderBy('libraries.name')->get()
            ->map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'label' => (string) $row->name])
            ->all();
        $requesters = (clone $base)->whereNotNull('jobs.requested_by')->whereNotNull('requesters.display_name')
            ->select(['jobs.requested_by as id', 'requesters.display_name as label'])
            ->distinct()->orderBy('requesters.display_name')->get()
            ->map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'label' => (string) $row->label])
            ->all();

        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /** Returns one task only when the actor still manages its library. */
    public function findJob(string $jobId, array $actor): array
    {
        /** @var stdClass|null $row */
        $row = $this->scopedJobQuery($actor)
            ->where('jobs.id', $jobId)
            ->first($this->jobColumns());
        if (!$row instanceof stdClass) {
            throw new ScanJobNotFound('扫描任务不存在。');
        }

        return $this->mapJob($row);
    }

    /**
     * Returns a bounded page of historic per-file recognition snapshots for one authorized job.
     *
     * Calling findJob first deliberately repeats the current library management check. The result
     * table contains only safe basenames and normalized display fields; this method never joins the
     * inventory path columns, so neither absolute nor relative directories can enter the response.
     *
     * @return array{files: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listFileResults(string $jobId, array $actor, int $limit, int $offset): array
    {
        $this->findJob($jobId, $actor);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $query = Db::table('library_scan_file_results')->where('scan_job_id', $jobId);
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderBy('file_name')
            ->orderBy('inventory_file_id')
            ->offset($offset)
            ->limit($limit)
            ->get()->all();

        return [
            'files' => array_map(fn (stdClass $row): array => $this->mapFileResult($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * 返回扫描详情使用的有界错误样本，并重复校验目标音乐库的实时 manage 范围。
     *
     * 历史结果表只保存安全 basename、稳定错误码和已裁剪消息；查询不连接 inventory，因而绝对路径、
     * 相对目录、原始标签或命令输出无法进入响应。total 表达完整失败数，samples 最多五条且稳定排序。
     *
     * @param array<string,mixed> $actor 当前 Web Session 身份。
     * @return array{total:int,samples:list<array{label:string,code:string,message:string}>}
     */
    public function errorSamples(string $jobId, array $actor, int $limit = 5): array
    {
        $this->findJob($jobId, $actor);
        $limit = max(1, min(5, $limit));
        $query = Db::table('library_scan_file_results')->where('scan_job_id', $jobId)
            ->whereNotNull('error_code');
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('file_name')->orderBy('inventory_file_id')->limit($limit)
            ->get(['file_name', 'error_code', 'error_message'])->all();
        return ['total' => $total, 'samples' => array_map(static fn (stdClass $row): array => [
            'label' => (string) $row->file_name,
            'code' => (string) $row->error_code,
            'message' => $row->error_message === null ? '' : (string) $row->error_message,
        ], $rows)];
    }

    /**
     * Atomically queues one scan after proving object-level library management scope.
     *
     * The database partial unique index is the final race guard. A second concurrent request for
     * the same library receives ScanJobConflict and cannot create duplicate filesystem work.
     *
     * @param array<string, mixed> $actor Authorized principal with global manage_library.
     * @return array<string, mixed> Newly queued actor-scoped job.
     */
    public function createJob(
        string $libraryId,
        ScanCreateInput $input,
        array $actor,
        string $requestId,
    ): array {
        return $this->createJobs([$libraryId], $input, $actor, $requestId)[0];
    }

    /**
     * Atomically queues the same scan intent for a bounded set of managed active libraries.
     *
     * All target IDs, object permissions, and active-job conflicts are checked before the first
     * insert. Jobs, library summary transitions, and one audit row per job then commit in a single
     * short transaction. The partial unique index remains the final concurrent-writer guard; any
     * race rolls the whole batch back, so callers never observe a partially queued multi-library
     * command. Filesystem traversal remains outside this transaction and is performed later by the
     * worker. Input IDs are server-owned but still canonicalized here to protect future adapters.
     *
     * @param list<string> $libraryIds Unique library IDs requested by an authorized adapter.
     * @param array<string, mixed> $actor Principal with object-level manage scope.
     * @return list<array<string, mixed>> Created jobs in the input library order.
     */
    public function createJobs(
        array $libraryIds,
        ScanCreateInput $input,
        array $actor,
        string $requestId,
        ?string $retryOfJobId = null,
    ): array {
        $libraryIds = array_values(array_unique($libraryIds));
        if ($libraryIds === [] || count($libraryIds) > 100) {
            throw new ScanJobNotFound('没有可扫描的音乐库。');
        }
        if ($retryOfJobId !== null && count($libraryIds) !== 1) {
            throw new ScanJobConflict('一次重试只能对应一个原扫描任务。');
        }
        foreach ($libraryIds as $libraryId) {
            if (!is_string($libraryId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
                throw new ScanJobNotFound('音乐库不存在或不可扫描。');
            }
        }
        $jobs = [];
        foreach ($libraryIds as $libraryId) {
            $jobs[$libraryId] = (string) new Ulid();
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');

        try {
            Db::transaction(function () use ($actor, $input, $jobs, $libraryIds, $now, $requestId, $retryOfJobId): void {
                foreach ($libraryIds as $libraryId) {
                    $this->requireManagedActiveLibrary($libraryId, $actor);
                }
                if (Db::table('library_scan_jobs')->whereIn('library_id', $libraryIds)
                    ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) {
                    throw new ScanJobConflict('至少一个音乐库已有活动扫描任务。');
                }
                $this->highCostJobs->assertCanQueue((string) $actor['id'], count($libraryIds));
                foreach ($jobs as $libraryId => $jobId) {
                    Db::table('library_scan_jobs')->insert($this->newJobRow(
                        $jobId,
                        $libraryId,
                        (string) $actor['id'],
                        $input->scanType,
                        $requestId,
                        $now,
                    ));
                    Db::table('music_libraries')->where('id', $libraryId)->update([
                        'scan_status' => 'queued',
                        'version' => Db::raw('version + 1'),
                        'updated_by' => (string) $actor['id'],
                        'updated_at' => $now,
                    ]);
                    $this->auditLogger->record(
                        (string) $actor['id'],
                        $retryOfJobId === null ? 'library.scan.queue' : 'library.scan.retry',
                        'library_scan_job',
                        $jobId,
                        'success',
                        $requestId,
                        array_filter([
                            'libraryId' => $libraryId,
                            'scanType' => $input->scanType,
                            'retryOfJobId' => $retryOfJobId,
                        ], static fn (mixed $value): bool => $value !== null),
                    );
                }
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ScanJobConflict('至少一个音乐库已有活动扫描任务。', previous: $exception);
            }
            throw $exception;
        }

        return array_map(
            fn (string $jobId): array => $this->findJob($jobId, $actor),
            array_values($jobs),
        );
    }

    /**
     * 为服务端扫描计划创建一个没有用户所有者的增量任务。
     *
     * 本方法只允许 `scheduled|watch|reconcile|download_import` 四种内部原因，调用方不能提供用户、路径
     * 或扫描类型。download_import 允许 active 本地库及核心支持发布的三种远程库，并且只有受控文件
     * 完整公布后才触发一次增量扫描；远程网络 I/O 仍由扫描 Worker 在事务外执行，本方法不探测新对象。
     * 事务内重新读取活动音乐库的 scan_mode/source_type，并以局部唯一索引防止与 Web、CLI、Subsonic
     * 或其他自动触发器并发入队。同库已有活动任务时返回 active，既不修改该任务，也不制造失败审计；
     * ineligible 表示配置已经变化，调用方应丢弃旧监听事件。系统任务跳过账号级高成本额度，但仍受
     * 单库活动任务约束、单扫描 Worker 和自动化轮询上限控制。
     *
     * 副作用：任务、音乐库摘要和去路径化审计在同一短事务提交；不读取音乐目录、不访问 WebDAV，
     * 也不向 Redis 写入唯一事实。失败回滚全部数据库变更，稍后周期轮询可安全重试。
     *
     * @return 'queued'|'active'|'ineligible'
     */
    public function queueAutomaticJob(string $libraryId, string $reason): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1
            || !in_array($reason, ['scheduled', 'watch', 'reconcile', 'download_import'], true)) {
            return 'ineligible';
        }
        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $requestId = 'scan-automation:' . $reason . ':' . $jobId;
        try {
            return Db::transaction(function () use ($jobId, $libraryId, $now, $reason, $requestId): string {
                /** @var stdClass|null $library */
                $library = Db::table('music_libraries')->where('id', $libraryId)->where('status', 'active')
                    ->first(['scan_mode', 'source_type']);
                $eligible = $library instanceof stdClass
                    && (($reason === 'download_import' && in_array((string) $library->source_type,
                        ['local', 'webdav', 'onedrive', 'google_drive'], true))
                        || ($reason === 'scheduled' && $library->scan_mode === 'scheduled')
                        || ($reason === 'reconcile' && $library->scan_mode === 'watch'
                            && $library->source_type === 'local')
                        || ($reason === 'watch' && $library->scan_mode === 'watch'
                            && $library->source_type === 'local'));
                if (!$eligible) {
                    return 'ineligible';
                }
                if (Db::table('library_scan_jobs')->where('library_id', $libraryId)
                    ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) {
                    return 'active';
                }
                Db::table('library_scan_jobs')->insert($this->newJobRow(
                    $jobId,
                    $libraryId,
                    null,
                    'incremental',
                    $requestId,
                    $now,
                ));
                Db::table('music_libraries')->where('id', $libraryId)->where('status', 'active')->update([
                    'scan_status' => 'queued',
                    'version' => Db::raw('version + 1'),
                    'updated_at' => $now,
                ]);
                $this->auditLogger->record(
                    null,
                    'library.scan.automation.queue',
                    'library_scan_job',
                    $jobId,
                    'success',
                    $requestId,
                    ['libraryId' => $libraryId, 'scanType' => 'incremental', 'trigger' => $reason],
                );
                return 'queued';
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                return 'active';
            }
            throw $exception;
        }
    }

    /**
     * 按失败或已取消任务的原音乐库和扫描模式创建一个全新任务。
     *
     * 原任务及其历史识别报告保持不可变；新任务重新执行当前音乐库 manage 权限、活动任务唯一约束和
     * 账号高成本任务限额。入队、音乐库摘要状态和 `library.scan.retry` 审计在同一短事务中提交。
     */
    public function retryJob(string $jobId, array $actor, string $requestId): array
    {
        $source = $this->findJob($jobId, $actor);
        if (!in_array($source['status'], ['failed', 'cancelled'], true)) {
            throw new ScanJobConflict('当前扫描状态不允许重试。');
        }
        return $this->createJobs(
            [(string) $source['library']['id']],
            new ScanCreateInput((string) $source['scanType']),
            $actor,
            $requestId,
            $jobId,
        )[0];
    }

    /**
     * Cancels a queued task immediately or records a cooperative request for a running Worker.
     *
     * Repeating cancellation for `cancel_requested` is idempotent. Terminal jobs reject the command
     * because their inventory and summary transaction has already reached a stable outcome.
     */
    public function cancelJob(string $jobId, array $actor, string $requestId): array
    {
        $job = $this->findJob($jobId, $actor);
        if ($job['status'] === 'cancel_requested') {
            return $job;
        }
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            throw new ScanJobConflict('当前扫描状态不允许取消。');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $job, $jobId, $now, $requestId): void {
            $nextStatus = $job['status'] === 'queued' ? 'cancelled' : 'cancel_requested';
            $changed = Db::table('library_scan_jobs')->where('id', $jobId)->where('status', $job['status'])->update([
                'status' => $nextStatus,
                'phase' => $nextStatus === 'cancelled' ? 'cancelled' : $job['phase'],
                'cancel_requested_at' => $now,
                'finished_at' => $nextStatus === 'cancelled' ? $now : null,
                'updated_at' => $now,
            ]);
            if ($changed !== 1) {
                throw new ScanJobConflict('扫描状态已变化，请刷新后重试。');
            }
            if ($nextStatus === 'cancelled') {
                $this->restoreLibraryAfterNonSuccess((string) $job['library']['id'], $now);
            }
            $this->auditLogger->record(
                (string) $actor['id'],
                'library.scan.cancel.request',
                'library_scan_job',
                $jobId,
                'success',
                $requestId,
                ['from' => $job['status'], 'to' => $nextStatus],
            );
        });

        return $this->findJob($jobId, $actor);
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function scopedJobQuery(array $actor)
    {
        $query = Db::table('library_scan_jobs as jobs')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'jobs.requested_by');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }

        return $query;
    }

    /** Proves the library exists, is active, and is within the actor's management scope. */
    private function requireManagedActiveLibrary(string $libraryId, array $actor): void
    {
        $query = Db::table('music_libraries as libraries')
            ->where('libraries.id', $libraryId)
            ->where('libraries.status', 'active');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }
        if (!$query->exists()) {
            throw new ScanJobNotFound('音乐库不存在或不可扫描。');
        }
    }

    /** @return array<string, int|string|null> Complete initial state for one durable queued job. */
    private function newJobRow(
        string $jobId,
        string $libraryId,
        ?string $actorId,
        string $scanType,
        string $requestId,
        string $now,
    ): array {
        return [
            'id' => $jobId,
            'library_id' => $libraryId,
            'requested_by' => $actorId,
            'scan_type' => $scanType,
            'status' => 'queued',
            'phase' => 'queued',
            'processed_entries' => 0,
            'discovered_files' => 0,
            'added_files' => 0,
            'missing_files' => 0,
            'ignored_entries' => 0,
            'failed_entries' => 0,
            'metadata_parsed_files' => 0,
            'metadata_failed_files' => 0,
            'updated_files' => 0,
            'attempt' => 0,
            'worker_id' => null,
            'heartbeat_at' => null,
            'cancel_requested_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
            'request_id' => $requestId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Restores the last trustworthy library summary without claiming a scan succeeded. */
    private function restoreLibraryAfterNonSuccess(string $libraryId, string $now): void
    {
        /** @var stdClass|null $library */
        $library = Db::table('music_libraries')->where('id', $libraryId)->first(['last_scanned_at']);
        Db::table('music_libraries')->where('id', $libraryId)->update([
            'scan_status' => $library?->last_scanned_at === null ? 'never_scanned' : 'ready',
            'version' => Db::raw('version + 1'),
            'updated_at' => $now,
        ]);
    }

    /** @return list<string> */
    private function jobColumns(): array
    {
        return [
            'jobs.id', 'jobs.scan_type', 'jobs.status', 'jobs.phase', 'jobs.processed_entries',
            'jobs.discovered_files', 'jobs.added_files', 'jobs.missing_files',
            'jobs.ignored_entries', 'jobs.failed_entries', 'jobs.attempt', 'jobs.heartbeat_at',
            'jobs.metadata_parsed_files', 'jobs.metadata_failed_files', 'jobs.updated_files',
            'jobs.cancel_requested_at', 'jobs.started_at', 'jobs.finished_at', 'jobs.error_code',
            'jobs.error_message', 'jobs.created_at', 'jobs.updated_at',
            'jobs.requested_by as requester_id',
            'libraries.id as library_id', 'libraries.name as library_name',
            'requesters.display_name as requester_name',
        ];
    }

    /** @return array<string, mixed> */
    private function mapJob(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'scanType' => (string) $row->scan_type,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'processedEntries' => (int) $row->processed_entries,
            'discoveredFiles' => (int) $row->discovered_files,
            'addedFiles' => (int) $row->added_files,
            'missingFiles' => (int) $row->missing_files,
            'ignoredEntries' => (int) $row->ignored_entries,
            'failedEntries' => (int) $row->failed_entries,
            'metadataParsedFiles' => (int) $row->metadata_parsed_files,
            'metadataFailedFiles' => (int) $row->metadata_failed_files,
            'updatedFiles' => (int) $row->updated_files,
            'attempt' => (int) $row->attempt,
            'requestedBy' => $row->requester_name === null ? null : (string) $row->requester_name,
            'requestedById' => $row->requester_id === null ? null : (string) $row->requester_id,
            'heartbeatAt' => $row->heartbeat_at === null ? null : (string) $row->heartbeat_at,
            'cancelRequestedAt' => $row->cancel_requested_at === null ? null : (string) $row->cancel_requested_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'error' => $row->error_code === null ? null : [
                'code' => (string) $row->error_code,
                'message' => (string) $row->error_message,
            ],
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /** Maps JSON facets defensively so one damaged diagnostic row cannot break the whole report. */
    private function mapFileResult(stdClass $row): array
    {
        return [
            'fileName' => (string) $row->file_name,
            'result' => (string) $row->result_kind,
            'metadataParsed' => (bool) $row->metadata_parsed,
            'metadataUpdated' => (bool) $row->metadata_updated,
            'songId' => $row->song_id === null ? null : (string) $row->song_id,
            'title' => $row->title === null ? null : (string) $row->title,
            'artists' => $this->stringArray((string) $row->artists_json),
            'albumTitle' => $row->album_title === null ? null : (string) $row->album_title,
            'durationMs' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'audio' => [
                'codec' => $row->codec_name === null ? null : (string) $row->codec_name,
                'sampleRate' => $row->sample_rate === null ? null : (int) $row->sample_rate,
                'bitrate' => $row->bitrate === null ? null : (int) $row->bitrate,
            ],
            'lyrics' => [
                'count' => (int) $row->lyrics_count,
                'languages' => $this->stringArray((string) $row->lyric_languages_json),
                'kinds' => $this->stringArray((string) $row->lyric_kinds_json),
                'embeddedFound' => (bool) $row->embedded_lyrics_found,
                'exportStatus' => (string) $row->lyrics_export_status,
            ],
            'artworkStatus' => (string) $row->artwork_status,
            'error' => $row->error_code === null ? null : [
                'code' => (string) $row->error_code,
                'message' => (string) $row->error_message,
            ],
        ];
    }

    /** @return list<string> */
    private function stringArray(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item)));
    }
}
