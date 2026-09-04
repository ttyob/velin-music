<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use app\application\Metadata\MetadataWorkerWakeSignal;
use app\application\Metadata\MetadataScrapePolicyService;
use app\infrastructure\Metadata\RedisMetadataWorkerWakeSignal;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 消费远程封面搜索和显式导入任务，并隔离网络、图片处理与 SQLite 写事务。
 *
 * 单个搜索仍只提交、轮询或读取一个远端步骤；Worker tick 可通过有界 drain 连续推进多个已到期任务，
 * 降低专辑图与艺人图在 SQLite 单消费者中的轮询空等。导入先重读 result 证明候选仍归属冻结结果，再
 * 读取原图并按摘要复验，随后在事务外居中裁切、等比重采样为 1000 WebP。最终短事务重新验证账号、双 capability、实体
 * 全部库 manage 范围和证据，再原子写入未选中的手工候选与任务终态。远端字节、URL、摘要和 Worker
 * 身份不进入审计、Outbox、日志或错误响应。
 */
final class ArtworkProviderWorkerService
{
    private const LEASE_SECONDS = 120;
    private const MAX_FAILURES = 5;
    private const MAX_DRAIN_STEPS = 20;
    private ?ArtworkProviderGateway $gateway;

    public function __construct(
        ?ArtworkProviderGateway $gateway = null,
        private readonly ArtworkProviderEvidenceService $evidence = new ArtworkProviderEvidenceService(),
        private readonly ArtworkCandidateImageNormalizer $normalizer = new ArtworkCandidateImageNormalizer(),
        private readonly CapabilityResolver $capabilities = new CapabilityResolver(),
        private readonly LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ArtworkCurrentStateService $currentArtwork = new ArtworkCurrentStateService(),
        private readonly MetadataWorkerWakeSignal $metadataWake = new RedisMetadataWorkerWakeSignal(),
        private readonly MetadataScrapePolicyService $scrapePolicy = new MetadataScrapePolicyService(),
        private readonly ArtworkBlobStore $blobs = new ArtworkBlobStore(),
    ) {
        $this->gateway = $gateway;
    }

    /** @return array{id:string}|null 通过租约 CAS 领取一个到期搜索任务。 */
    public function claimSearch(string $workerId): ?array
    {
        return $this->claim('artwork_provider_search_jobs', $workerId);
    }

    /** @return array{id:string}|null 领取一个到期导入任务；单消费者仍使用 CAS 防止重复执行。 */
    public function claimImport(string $workerId): ?array
    {
        return $this->claim('artwork_provider_import_jobs', $workerId);
    }

    /**
     * 在一次进程 tick 内有界推进已到期的搜索和导入任务。
     *
     * 每次领取仍使用原有 SQLite 短事务与租约 CAS，网络、图片读取和规范化继续在事务外执行；一个搜索
     * 若同步产生导入任务，会在同一轮立即尝试导入，从而避免再等待整个轮询周期。远端未终结搜索会按
     * next_attempt_at 释放租约，本方法不会忙等或越过到期时间。maximumSteps 小于一时不做任何副作用，
     * 大于内部上限时截断为 20，防止错误配置让常驻进程长期占用事件循环；单步业务失败由原执行方法
     * 写入稳定终态，不中断后续已到期任务。重复调用保持任务幂等，不改变单消费者和逐曲顺序边界。
     */
    public function drain(string $workerId, int $maximumSteps): int
    {
        $limit = min(self::MAX_DRAIN_STEPS, max(0, $maximumSteps));
        $processed = 0;
        while ($processed < $limit) {
            $advanced = false;
            $search = $this->claimSearch($workerId);
            if ($search !== null) {
                $companion = $processed + 1 < $limit && $this->gateway() instanceof ArtworkProviderBatchGateway
                    ? $this->claimCompanionSearch($search['id'], $workerId) : null;
                if ($companion !== null) {
                    $this->executeSearchBatch([$search, $companion]);
                    $processed += 2;
                } else {
                    $this->executeSearch($search);
                    ++$processed;
                }
                $advanced = true;
            }
            if ($processed >= $limit) break;

            $import = $this->claimImport($workerId);
            if ($import !== null) {
                $this->executeImport($import);
                ++$processed;
                $advanced = true;
            }
            if (!$advanced) break;
        }
        return $processed;
    }

    /**
     * 领取与首任务属于同一逐曲目标、尚未提交远端的另一类图片搜索。
     *
     * 只有带同一 scrape_target_id 的专辑或艺人任务可成对领取；不会跨歌曲、跨目标或把已经持有远端
     * job ID 的轮询任务重新提交。领取仍在 SQLite 短事务内使用状态、空租约和到期时间 CAS，失败返回
     * null 并由普通 drain 路径处理。两个任务由同一 Worker 顺序结算，因此没有增加数据库写消费者。
     *
     * @return array{id:string}|null
     */
    private function claimCompanionSearch(string $firstJobId, string $workerId): ?array
    {
        if (!Db::connection()->getSchemaBuilder()->hasColumn('artwork_provider_search_jobs', 'scrape_target_id')) {
            return null;
        }
        return Db::transaction(function () use ($firstJobId, $workerId): ?array {
            /** @var stdClass|null $first */
            $first = Db::table('artwork_provider_search_jobs')->where('id', $firstJobId)
                ->where('status', 'running')->where('worker_id', $workerId)->whereNull('remote_job_id')
                ->whereNotNull('scrape_target_id')->first(['scrape_target_id', 'album_id', 'artist_id']);
            if (!$first instanceof stdClass || ($first->album_id === null && $first->artist_id === null)) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $query = Db::table('artwork_provider_search_jobs')->where('scrape_target_id', (string) $first->scrape_target_id)
                ->where('id', '<>', $firstJobId)->whereIn('status', ['queued', 'running'])->whereNull('worker_id')
                ->whereNull('remote_job_id')->where('next_attempt_at', '<=', $now);
            $first->album_id !== null ? $query->whereNotNull('artist_id') : $query->whereNotNull('album_id');
            /** @var stdClass|null $row */
            $row = $query->orderBy('created_at')->first(['id', 'status']);
            if (!$row instanceof stdClass) return null;
            $updates = ['status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                'updated_at' => $now, 'error_code' => null];
            if ((string) $row->status === 'queued') $updates['started_at'] = $now;
            $changed = Db::table('artwork_provider_search_jobs')->where('id', (string) $row->id)
                ->whereIn('status', ['queued', 'running'])->whereNull('worker_id')->whereNull('remote_job_id')
                ->update($updates);
            return $changed === 1 ? ['id' => (string) $row->id] : null;
        });
    }

    /**
     * 并行执行同一逐曲目标的专辑图与艺人图远程提交，并逐任务串行落库。
     *
     * 每项在启动网络前独立重建账号、库权限和证据摘要；任一项失效只终结该项。批量网关保证返回与
     * 请求顺序绑定，Worker 随后逐项读取冻结 result 并调用原 completeSearch() 短事务，因此候选、导入
     * 任务和审计仍按顺序写 SQLite。方法结束时分别检查父目标，只有所有关联图片任务终态才发布唤醒。
     *
     * @param list<array{id:string}> $claimed
     */
    private function executeSearchBatch(array $claimed): void
    {
        $gateway = $this->gateway();
        if (!$gateway instanceof ArtworkProviderBatchGateway) {
            foreach ($claimed as $job) $this->executeSearch($job);
            return;
        }
        $contexts = [];
        foreach ($claimed as $index => $job) {
            $jobId = $this->claimedId($job);
            /** @var stdClass|null $row */
            $row = Db::table('artwork_provider_search_jobs')->where('id', $jobId)
                ->whereNotNull('worker_id')->first();
            if (!$row instanceof stdClass) continue;
            try {
                $actor = $this->liveActor((string) $row->requested_by);
                $type = $this->type($row);
                $entityId = $this->entityId($row);
                $facts = $this->evidence->scoped($type, $entityId, (string) $row->library_id, $actor);
                if (!hash_equals((string) $row->evidence_sha256, $facts['evidenceSha256'])
                    || (string) $row->evidence_song_id !== $facts['songId']
                    || (string) $row->locale !== $facts['locale'] || (string) $row->region !== $facts['region']) {
                    $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
                    continue;
                }
                $this->phase('artwork_provider_search_jobs', $jobId, 'submitting');
                $contexts[] = ['row' => $row, 'request' => [
                    'evidence' => $facts['evidence'] + ['artworkEntityType' => $type,
                        'artworkEntityName' => $facts['entityName'], 'artworkEntityId' => $entityId],
                    'locale' => $facts['locale'], 'region' => $facts['region'],
                    'idempotencyKey' => 'velin-artwork-search-' . strtolower($jobId),
                ]];
            } catch (ArtworkProviderRemoteFailure $failure) {
                $this->remoteFailure('artwork_provider_search_jobs', $row, $failure);
            } catch (ArtworkAdminNotFound) {
                $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_AUTHORIZATION_REVOKED');
            } catch (ArtworkAdminConflict|ArtworkAdminInvalid) {
                $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
            } catch (Throwable) {
                $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_WORKER_FAILED');
            }
        }
        if ($contexts !== []) {
            try {
                $responses = $gateway->submitBatch(array_column($contexts, 'request'));
            } catch (Throwable) {
                $responses = array_fill(0, count($contexts), [
                    'remote' => null,
                    'failure' => new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10),
                ]);
            }
            foreach ($contexts as $index => $context) {
                /** @var stdClass $row */
                $row = $context['row'];
                try {
                    $response = $responses[$index] ?? null;
                    $failure = is_array($response) ? ($response['failure'] ?? null) : null;
                    if (!is_array($response) || $failure instanceof ArtworkProviderRemoteFailure
                        || !array_key_exists('remote', $response)) {
                        throw $failure instanceof ArtworkProviderRemoteFailure ? $failure
                            : new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10);
                    }
                    $this->settleSubmittedSearch($row, $response['remote']);
                } catch (ArtworkProviderRemoteFailure $failure) {
                    $this->remoteFailure('artwork_provider_search_jobs', $row, $failure);
                } catch (Throwable) {
                    $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_WORKER_FAILED');
                }
            }
        }
        foreach ($claimed as $job) $this->wakeParentIfSettled('artwork_provider_search_jobs', $job['id']);
    }

    /** 校验批量提交的远端终态并按原有单任务路径读取、提交冻结结果。 */
    private function settleSubmittedSearch(stdClass $row, mixed $remote): void
    {
        if (!is_array($remote) || !isset($remote['id'], $remote['status'])
            || !array_key_exists('resultId', $remote)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10);
        }
        if (!in_array($remote['status'], ['succeeded', 'partial', 'failed', 'cancelled'], true)) {
            $this->releaseSearchPending($row, $remote, 2);
            return;
        }
        if (!in_array($remote['status'], ['succeeded', 'partial'], true) || $remote['resultId'] === null) {
            $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_REMOTE_JOB_FAILED', $remote);
            return;
        }
        $this->phase('artwork_provider_search_jobs', (string) $row->id, 'reading_result');
        $result = $this->gateway()->result($remote['resultId']);
        if ($result['id'] !== $remote['resultId'] || $result['jobId'] !== $remote['id']) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
        }
        $this->completeSearch($row, $remote, $result);
    }

    /**
     * 执行搜索的一个远端步骤。
     *
     * 远端 POST 使用本地任务 ID 派生的稳定幂等键；未终结任务释放本地租约并持久化下一次轮询时间。
     * 业务失败只终结当前任务，不向进程循环抛出远端内容。
     *
     * @param array{id:string} $claimed
     */
    public function executeSearch(array $claimed): void
    {
        $jobId = $this->claimedId($claimed);
        /** @var stdClass|null $row */
        $row = Db::table('artwork_provider_search_jobs')->where('id', $jobId)->whereNotNull('worker_id')->first();
        if (!$row instanceof stdClass) return;
        try {
            $actor = $this->liveActor((string) $row->requested_by);
            $type = $this->type($row);
            $entityId = $this->entityId($row);
            $facts = $this->evidence->scoped($type, $entityId, (string) $row->library_id, $actor);
            if (!hash_equals((string) $row->evidence_sha256, $facts['evidenceSha256'])
                || (string) $row->evidence_song_id !== $facts['songId']
                || (string) $row->locale !== $facts['locale'] || (string) $row->region !== $facts['region']) {
                $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
                return;
            }
            $this->phase('artwork_provider_search_jobs', $jobId,
                $row->remote_job_id === null ? 'submitting' : 'polling');
            $remote = $row->remote_job_id === null
                ? $this->gateway()->submit($facts['evidence'] + [
                    'artworkEntityType' => $type,
                    'artworkEntityName' => $facts['entityName'], 'artworkEntityId' => $entityId,
                ],
                    $facts['locale'], $facts['region'],
                    'velin-artwork-search-' . strtolower($jobId))
                : $this->gateway()->job((string) $row->remote_job_id);
            if ($row->remote_job_id !== null && $remote['id'] !== (string) $row->remote_job_id) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            if (!in_array($remote['status'], ['succeeded', 'partial', 'failed', 'cancelled'], true)) {
                $this->releaseSearchPending($row, $remote, 2);
                return;
            }
            if (!in_array($remote['status'], ['succeeded', 'partial'], true) || $remote['resultId'] === null) {
                $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_REMOTE_JOB_FAILED', $remote);
                return;
            }
            $this->phase('artwork_provider_search_jobs', $jobId, 'reading_result');
            $result = $this->gateway()->result($remote['resultId']);
            if ($result['id'] !== $remote['resultId'] || $result['jobId'] !== $remote['id']) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            $this->completeSearch($row, $remote, $result);
        } catch (ArtworkProviderRemoteFailure $failure) {
            $this->remoteFailure('artwork_provider_search_jobs', $row, $failure);
        } catch (ArtworkAdminNotFound) {
            $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_AUTHORIZATION_REVOKED');
        } catch (ArtworkAdminConflict|ArtworkAdminInvalid) {
            $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
        } catch (Throwable) {
            $this->fail('artwork_provider_search_jobs', $row, 'ARTWORK_PROVIDER_WORKER_FAILED');
        } finally {
            $this->wakeParentIfSettled('artwork_provider_search_jobs', $jobId);
        }
    }

    /**
     * 执行显式导入并新增未选中的手工候选。
     *
     * result、asset 与图片规范化全部在事务外；写事务内再次复验权限和证据。若数据库提交失败，内存
     * WebP 自然释放且不需要文件补偿；任务保持可恢复状态。导入成功也不会写选择覆盖或扫描事实。
     *
     * @param array{id:string} $claimed
     */
    public function executeImport(array $claimed): void
    {
        $jobId = $this->claimedId($claimed);
        /** @var stdClass|null $row */
        $row = Db::table('artwork_provider_import_jobs as imports')
            ->join('artwork_provider_search_jobs as searches', 'searches.id', '=', 'imports.search_job_id')
            ->join('artwork_provider_candidates as candidates', 'candidates.id', '=', 'imports.candidate_id')
            ->where('imports.id', $jobId)->whereNotNull('imports.worker_id')->first([
                'imports.*', 'searches.status as search_status', 'searches.remote_job_id',
                'searches.remote_result_id', 'searches.evidence_sha256 as search_evidence_sha256',
                'candidates.remote_asset_id', 'candidates.provider_key', 'candidates.artwork_kind',
                'candidates.mime_type', 'candidates.width', 'candidates.height', 'candidates.size_bytes',
                'candidates.resource_sha256', 'candidates.attribution_required',
                'candidates.attribution_text', 'candidates.attribution_url',
            ]);
        if (!$row instanceof stdClass) return;
        try {
            $actor = $this->liveActor((string) $row->requested_by);
            $type = $this->type($row);
            $entityId = $this->entityId($row);
            $facts = $this->evidence->scoped($type, $entityId, (string) $row->library_id, $actor);
            if ((string) $row->search_status !== 'succeeded'
                || !hash_equals((string) $row->evidence_sha256, $facts['evidenceSha256'])
                || !hash_equals((string) $row->search_evidence_sha256, $facts['evidenceSha256'])
                || $row->remote_job_id === null || $row->remote_result_id === null) {
                $this->fail('artwork_provider_import_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
                return;
            }
            $this->phase('artwork_provider_import_jobs', $jobId, 'validating');
            $result = $this->gateway()->result((string) $row->remote_result_id);
            if ($result['jobId'] !== (string) $row->remote_job_id) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            $summary = null;
            foreach ($result['assets'] as $asset) {
                if ($asset['id'] === (string) $row->remote_asset_id) $summary = $asset;
            }
            if (!is_array($summary) || !$this->summaryMatches($row, $summary)) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            $this->phase('artwork_provider_import_jobs', $jobId, 'reading_resource');
            $asset = $this->gateway()->asset((string) $row->remote_asset_id);
            if (!$this->assetMatches($row, $asset)) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            $this->phase('artwork_provider_import_jobs', $jobId, 'normalizing');
            try {
                $normalized = $this->normalizer->centered($asset['bytes'], $asset['mimeType']);
            } catch (ArtworkAdminInvalid|ArtworkAdminConflict $exception) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
            }
            $this->phase('artwork_provider_import_jobs', $jobId, 'persisting');
            $this->persistImport($row, $normalized);
        } catch (ArtworkProviderRemoteFailure $failure) {
            $this->remoteFailure('artwork_provider_import_jobs', $row, $failure);
        } catch (ArtworkAdminNotFound) {
            $this->fail('artwork_provider_import_jobs', $row, 'ARTWORK_PROVIDER_AUTHORIZATION_REVOKED');
        } catch (ArtworkAdminConflict|ArtworkAdminInvalid) {
            $this->fail('artwork_provider_import_jobs', $row, 'ARTWORK_PROVIDER_EVIDENCE_STALE');
        } catch (Throwable) {
            $this->fail('artwork_provider_import_jobs', $row, 'ARTWORK_PROVIDER_WORKER_FAILED');
        } finally {
            $this->wakeParentIfSettled('artwork_provider_import_jobs', $jobId);
        }
    }

    /**
     * 释放超过两分钟的本地租约。
     *
     * 搜索 POST 幂等，其他远端调用只读，图片写入与任务终态同一数据库事务，因此没有不确定文件系统
     * 副作用；清除旧租约后可安全重领。CAS 心跳防止迟到恢复覆盖活动 Worker。
     */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        foreach (['artwork_provider_search_jobs', 'artwork_provider_import_jobs'] as $table) {
            /** @var list<stdClass> $rows */
            $rows = Db::table($table)->where('status', 'running')->whereNotNull('worker_id')
                ->where('heartbeat_at', '<', $threshold)->get(['id', 'heartbeat_at'])->all();
            foreach ($rows as $row) {
                Db::table($table)->where('id', (string) $row->id)->where('heartbeat_at', (string) $row->heartbeat_at)
                    ->update(['worker_id' => null, 'heartbeat_at' => null,
                        'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            }
        }
    }

    /** @return array{id:string}|null */
    private function claim(string $table, string $workerId): ?array
    {
        return Db::transaction(function () use ($table, $workerId): ?array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            /** @var stdClass|null $row */
            $row = Db::table($table)->whereIn('status', ['queued', 'running'])->whereNull('worker_id')
                ->where('next_attempt_at', '<=', $now)->orderBy('created_at')->first(['id', 'status']);
            if (!$row instanceof stdClass) return null;
            $updates = ['status' => 'running', 'worker_id' => $workerId, 'heartbeat_at' => $now,
                'updated_at' => $now, 'error_code' => null];
            if ((string) $row->status === 'queued') $updates['started_at'] = $now;
            $changed = Db::table($table)->where('id', (string) $row->id)->whereIn('status', ['queued', 'running'])
                ->whereNull('worker_id')->update($updates);
            return $changed === 1 ? ['id' => (string) $row->id] : null;
        });
    }

    /** 重建当前发起账号；实体级全部库 manage 范围由随后 evidence 调用继续验证。 */
    private function liveActor(string $userId): array
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->leftJoin('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
            ->where('users.id', $userId)->where('users.status', 'active')->whereNull('users.deleted_at')
            ->first(['users.id', 'users.is_super_admin', 'users.locale', 'preferences.locale as preference_locale']);
        if (!$user instanceof stdClass) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_ACTOR_REVOKED', false);
        $super = (int) $user->is_super_admin === 1;
        $capabilities = $this->capabilities->resolve($userId, $super);
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_AUTHORIZATION_REVOKED', false);
        }
        return ['id' => $userId, 'isSuperAdmin' => $super, 'capabilities' => $capabilities,
            'libraries' => $this->libraries->resolve($userId, $super),
            'preferences' => ['locale' => (string) ($user->preference_locale ?? $user->locale)]];
    }

    /** 保存远端未终结状态并释放本地租约，下一次不会重复提交新任务。 */
    private function releaseSearchPending(stdClass $row, array $remote, int $seconds): void
    {
        Db::table('artwork_provider_search_jobs')->where('id', (string) $row->id)
            ->where('status', 'running')->where('worker_id', (string) $row->worker_id)->update([
                'phase' => 'polling', 'remote_job_id' => $remote['id'], 'remote_result_id' => $remote['resultId'],
                'worker_id' => null, 'heartbeat_at' => null,
                'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds),
                'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /**
     * 原子发布无字节候选并终结搜索；自动补图只为第一张可靠候选创建导入任务。
     *
     * 候选顺序继承已启用平台优先级。自动任务在建导入前再次确认实体仍无本地图和选择；管理员并发
     * 上传或已有手工导入任务时以人工操作优先，搜索仍以成功终结而不覆盖。空结果也是成功终态，同一
     * 证据不会被周期调度反复查询。
     */
    private function completeSearch(stdClass $row, array $remote, array $result): void
    {
        Db::transaction(function () use ($remote, $result, $row): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if (Db::table('artwork_provider_candidates')->where('search_job_id', (string) $row->id)->exists()) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_STATE_CONFLICT', false);
            }
            $firstCandidateId = null;
            foreach ($result['assets'] as $asset) {
                $candidateId = (string) new Ulid();
                $firstCandidateId ??= $candidateId;
                Db::table('artwork_provider_candidates')->insert([
                    'id' => $candidateId, 'search_job_id' => (string) $row->id,
                    'remote_asset_id' => $asset['id'], 'provider_key' => $asset['providerKey'],
                    'artwork_kind' => $asset['kind'], 'mime_type' => $asset['mimeType'],
                    'width' => $asset['width'], 'height' => $asset['height'], 'size_bytes' => $asset['sizeBytes'],
                    'resource_sha256' => $asset['sha256'],
                    'attribution_required' => $asset['attribution']['required'] ? 1 : 0,
                    'attribution_text' => $asset['attribution']['text'],
                    'attribution_url' => $asset['attribution']['url'], 'created_at' => $now,
                ]);
            }
            $changed = Db::table('artwork_provider_search_jobs')->where('id', (string) $row->id)
                ->where('status', 'running')->where('worker_id', (string) $row->worker_id)->update([
                    'status' => 'succeeded', 'phase' => 'completed', 'remote_job_id' => $remote['id'],
                    'remote_result_id' => $result['id'], 'candidate_count' => count($result['assets']),
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                    'version' => Db::raw('version + 1'), 'finished_at' => $now, 'updated_at' => $now,
            ]);
            if ($changed !== 1) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_STATE_CONFLICT', false);
            if ((int) ($row->auto_import ?? 0) === 1 && is_string($firstCandidateId)
                && $this->canAutomaticallySelect(
                    $this->type($row), $this->entityId($row), (string) $row->library_id,
                )) {
                $this->queueAutomaticImport($row, $firstCandidateId, $now);
            }
            $this->audit->record((string) $row->requested_by, 'artwork.provider.search.complete',
                'artwork_provider_search_job', (string) $row->id, 'success', (string) $row->request_id, [
                    'entityType' => $this->type($row), 'entityId' => $this->entityId($row),
                    'libraryId' => (string) $row->library_id, 'candidateCount' => count($result['assets']),
                ]);
        });
    }

    /**
     * 原子写入 Provider 手工候选和任务终态。
     *
     * provider_asset_digest 只由 opaque asset ID 派生；重复导入同一实体/库/asset 复用已有候选，并
     * 验证规范化内容摘要未漂移。事务内再次重建 actor 和证据，网络期间撤权会使全部写入回滚。自动
     * 导入仅在提交瞬间仍无任何扫描图或选择时新增选择覆盖；并发手工图片永远优先且不会被替换。
     */
    private function persistImport(stdClass $row, string $normalized): void
    {
        Db::transaction(function () use ($normalized, $row): void {
            $actor = $this->liveActor((string) $row->requested_by);
            $type = $this->type($row);
            $entityId = $this->entityId($row);
            $facts = $this->evidence->scoped($type, $entityId, (string) $row->library_id, $actor);
            if (!hash_equals((string) $row->evidence_sha256, $facts['evidenceSha256'])) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_EVIDENCE_STALE', false);
            }
            $assetDigest = hash('sha256', 'velin-data-artwork:' . (string) $row->remote_asset_id);
            $contentSha = hash('sha256', $normalized);
            /** @var stdClass|null $existing */
            $existing = Db::table('media_manual_artwork_candidates')->where($type . '_id', $entityId)
                ->where('library_id', (string) $row->library_id)->where('origin_kind', 'provider')
                ->where('provider_asset_digest', $assetDigest)->first(['id', 'content_sha256']);
            if ($existing instanceof stdClass && !hash_equals((string) $existing->content_sha256, $contentSha)) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
            }
            $candidateId = $existing instanceof stdClass ? (string) $existing->id : (string) new Ulid();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if (!$existing instanceof stdClass) {
                $crop = $this->centerCrop((int) $row->width, (int) $row->height);
                $candidateBytes = $this->blobs->put(
                    $normalized,
                    'image/webp',
                    ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                    ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                    $contentSha,
                );
                Db::table('media_manual_artwork_candidates')->insert([
                    'id' => $candidateId, 'song_id' => $type === 'song' ? $entityId : null,
                    'album_id' => $type === 'album' ? $entityId : null,
                    'artist_id' => $type === 'artist' ? $entityId : null, 'library_id' => (string) $row->library_id,
                    'created_by' => (string) $row->requested_by, 'mime_type' => 'image/webp',
                    'image_bytes' => $candidateBytes, 'byte_size' => strlen($normalized),
                    'width' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE,
                    'height' => ArtworkCandidateImageNormalizer::OUTPUT_SIZE, 'content_sha256' => $contentSha,
                    'crop_x' => $crop['x'], 'crop_y' => $crop['y'], 'crop_width' => $crop['width'],
                    'crop_height' => $crop['height'], 'origin_kind' => 'provider',
                    'provider_key' => (string) $row->provider_key, 'provider_asset_digest' => $assetDigest,
                    'attribution_required' => (int) $row->attribution_required,
                    'attribution_text' => (string) $row->attribution_text,
                    'attribution_url' => $row->attribution_url, 'created_at' => $now,
                ]);
            }
            if ((int) ($row->auto_select ?? 0) === 1
                && $this->canAutomaticallySelect($type, $entityId, (string) $row->library_id)) {
                /** @var stdClass|null $selection */
                $selection = Db::table('media_artwork_selection_overrides')->where($type . '_id', $entityId)
                    ->where('library_id', (string) $row->library_id)->first(['id', 'candidate_id']);
                $selectionValues = [
                    'candidate_id' => $candidateId, 'updated_by' => (string) $row->requested_by,
                    'updated_at' => $now,
                ];
                if ($selection instanceof stdClass) {
                    if (!hash_equals((string) $selection->candidate_id, $candidateId)) {
                        Db::table('media_artwork_selection_overrides')->where('id', (string) $selection->id)
                            ->update($selectionValues + ['version' => Db::raw('version + 1')]);
                    }
                } else {
                    Db::table('media_artwork_selection_overrides')->insert([
                    'id' => (string) new Ulid(), 'song_id' => $type === 'song' ? $entityId : null,
                    'album_id' => $type === 'album' ? $entityId : null,
                    'artist_id' => $type === 'artist' ? $entityId : null,
                    'library_id' => (string) $row->library_id, 'version' => 1, 'created_at' => $now,
                    ] + $selectionValues);
                }
                $this->audit->record((string) $row->requested_by, 'artwork.provider.backfill.select',
                    $type, $entityId, 'success', (string) $row->request_id, [
                        'candidateId' => $candidateId, 'libraryId' => (string) $row->library_id,
                    ]);
            }
            $changed = Db::table('artwork_provider_import_jobs')->where('id', (string) $row->id)
                ->where('status', 'running')->where('worker_id', (string) $row->worker_id)->update([
                    'status' => 'succeeded', 'phase' => 'completed', 'imported_candidate_id' => $candidateId,
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null,
                    'version' => Db::raw('version + 1'), 'finished_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_STATE_CONFLICT', false);
            $this->audit->record((string) $row->requested_by, 'artwork.provider.import.complete',
                'artwork_provider_import_job', (string) $row->id, 'success', (string) $row->request_id, [
                    'entityType' => $type, 'entityId' => $entityId, 'libraryId' => (string) $row->library_id,
                    'searchJobId' => (string) $row->search_job_id, 'candidateId' => (string) $row->candidate_id,
                    'importedCandidateId' => $candidateId, 'providerKey' => (string) $row->provider_key,
                ]);
        });
    }

    /**
     * 判断自动 Provider 导入是否可成为实体当前封面。
     *
     * 显式手工候选永远保持最高优先级。歌曲与专辑没有显式选择但存在扫描图时，仅相应三方优先配置
     * 允许创建覆盖；已有 Provider 选择也只在三方优先时更新。艺人图未开放此配置，继续只补空缺。
     */
    private function canAutomaticallySelect(string $type, string $entityId, string $libraryId): bool
    {
        /** @var stdClass|null $selection */
        $selection = Db::table('media_artwork_selection_overrides')->where($type . '_id', $entityId)
            ->where('library_id', $libraryId)->first(['candidate_id']);
        $field = $type === 'song' ? 'songArtwork' : ($type === 'album' ? 'albumArtwork' : null);
        if ($selection instanceof stdClass) {
            $origin = Db::table('media_manual_artwork_candidates')->where('id', (string) $selection->candidate_id)
                ->value('origin_kind');
            return $field !== null && $origin === 'provider'
                && $this->scrapePolicy->providerOverridesMetadata($field);
        }
        if (!$this->currentArtwork->exists($type, $entityId, $libraryId)) return true;
        return $field !== null && $this->scrapePolicy->providerOverridesMetadata($field);
    }

    /**
     * 为自动搜索的第一候选创建可恢复导入任务。
     *
     * 同实体已有活动导入时直接让人工流程优先；搜索证据摘要、请求账号与库范围原样继承，导入 Worker
     * 仍会重新验证权限、远端摘要和图片字节。方法只在搜索完成事务中调用，不访问网络。
     */
    private function queueAutomaticImport(stdClass $search, string $candidateId, string $now): void
    {
        $type = $this->type($search);
        $entityId = $this->entityId($search);
        $active = Db::table('artwork_provider_import_jobs')->where($type . '_id', $entityId)
            ->where('library_id', (string) $search->library_id)->whereIn('status', ['queued', 'running'])->exists();
        if ($active) return;
        $digest = hash('sha256', 'artwork-auto-import|' . (string) $search->id . '|' . $candidateId);
        if (Db::table('artwork_provider_import_jobs')->where('requested_by', (string) $search->requested_by)
            ->where('idempotency_key_sha256', $digest)->exists()) return;

        $jobId = (string) new Ulid();
        $row = [
            'id' => $jobId, 'search_job_id' => (string) $search->id, 'candidate_id' => $candidateId,
            'song_id' => $type === 'song' ? $entityId : null,
            'album_id' => $type === 'album' ? $entityId : null,
            'artist_id' => $type === 'artist' ? $entityId : null,
            'library_id' => (string) $search->library_id, 'requested_by' => (string) $search->requested_by,
            'request_id' => (string) $search->request_id, 'idempotency_key_sha256' => $digest,
            'evidence_sha256' => (string) $search->evidence_sha256, 'auto_select' => 1,
            'status' => 'queued', 'phase' => 'queued', 'imported_candidate_id' => null,
            'failure_count' => 0, 'next_attempt_at' => $now, 'worker_id' => null, 'heartbeat_at' => null,
            'error_code' => null, 'version' => 1, 'started_at' => null, 'finished_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
        if (Db::connection()->getSchemaBuilder()->hasColumn('artwork_provider_import_jobs', 'scrape_target_id')) {
            $row['scrape_target_id'] = property_exists($search, 'scrape_target_id')
                && $search->scrape_target_id !== null ? (string) $search->scrape_target_id : null;
        }
        Db::table('artwork_provider_import_jobs')->insert($row);
        $this->audit->record((string) $search->requested_by, 'artwork.provider.backfill.import.queue',
            'artwork_provider_import_job', $jobId, 'success', (string) $search->request_id, [
                'entityType' => $type, 'entityId' => $entityId, 'libraryId' => (string) $search->library_id,
                'searchJobId' => (string) $search->id, 'candidateId' => $candidateId,
            ]);
    }

    /**
     * 仅在关联图片任务全部进入终态后唤醒父逐曲目标。
     *
     * 先把 pending 父目标的 next_attempt_at 推到当前时间，再发布可丢失 Redis 标记；因此 Redis 故障时
     * 周期 Worker 仍能在下一轮立即领取。若同一 target 还有搜索或导入活动，保持原等待时间，避免每个
     * 子步骤都让 Metadata Worker 空转。更新使用目标状态和资源阶段作为 CAS，不会复活已完成或人工等待
     * 的目标，也不在 Redis 中写 target ID。
     */
    private function wakeParentIfSettled(string $table, string $jobId): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasColumn($table, 'scrape_target_id')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'phase')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'next_attempt_at')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'updated_at')) return;
        /** @var stdClass|null $job */
        $job = Db::table($table)->where('id', $jobId)
            ->whereIn('status', ['succeeded', 'failed', 'cancelled'])
            ->whereNotNull('scrape_target_id')->first(['scrape_target_id']);
        if (!$job instanceof stdClass) return;
        $targetId = (string) $job->scrape_target_id;
        $searchActive = Db::table('artwork_provider_search_jobs')->where('scrape_target_id', $targetId)
            ->whereIn('status', ['queued', 'running'])->exists();
        $importActive = Db::table('artwork_provider_import_jobs')->where('scrape_target_id', $targetId)
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($searchActive || $importActive) return;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('metadata_sync_scrape_targets')->where('id', $targetId)
            ->where('status', 'pending')->whereIn('phase', ['applying', 'completing_resources'])
            ->update(['next_attempt_at' => $now, 'updated_at' => $now]);
        if ($changed > 0) $this->metadataWake->notify();
    }

    /** 远端可重试失败释放租约并有界退避；达到上限或不可重试时进入终态。 */
    private function remoteFailure(string $table, stdClass $row, ArtworkProviderRemoteFailure $failure): void
    {
        $next = (int) $row->failure_count + 1;
        if ($failure->retryable && $next < self::MAX_FAILURES) {
            Db::table($table)->where('id', (string) $row->id)->where('status', 'running')
                ->where('worker_id', (string) $row->worker_id)->update([
                    'failure_count' => $next, 'error_code' => $failure->reasonCode,
                    'worker_id' => null, 'heartbeat_at' => null,
                    'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $failure->retryAfterSeconds),
                    'version' => Db::raw('version + 1'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            return;
        }
        $this->fail($table, $row, $failure->reasonCode);
    }

    /** 终结任务并写脱敏失败审计；远端身份和响应不进入审计。 */
    private function fail(string $table, stdClass $row, string $errorCode, ?array $remote = null): void
    {
        Db::transaction(function () use ($errorCode, $remote, $row, $table): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updates = ['status' => 'failed', 'phase' => 'failed', 'worker_id' => null,
                'heartbeat_at' => null, 'error_code' => $errorCode, 'version' => Db::raw('version + 1'),
                'finished_at' => $now, 'updated_at' => $now];
            if ($table === 'artwork_provider_search_jobs' && is_array($remote)) {
                $updates['remote_job_id'] = $remote['id'];
                $updates['remote_result_id'] = $remote['resultId'];
            }
            $changed = Db::table($table)->where('id', (string) $row->id)->where('status', 'running')
                ->where('worker_id', (string) $row->worker_id)->update($updates);
            if ($changed !== 1) return;
            $kind = $table === 'artwork_provider_search_jobs' ? 'search' : 'import';
            $this->audit->record((string) $row->requested_by, 'artwork.provider.' . $kind . '.complete',
                $table === 'artwork_provider_search_jobs' ? 'artwork_provider_search_job' : 'artwork_provider_import_job',
                (string) $row->id, 'failure', (string) $row->request_id, [
                    'entityType' => $this->type($row), 'entityId' => $this->entityId($row),
                    'libraryId' => (string) $row->library_id, 'errorCode' => $errorCode,
                ]);
        });
    }

    /** 更新阶段与租约心跳；迟到 Worker 不能复活已终结任务。 */
    private function phase(string $table, string $jobId, string $phase): void
    {
        $changed = Db::table($table)->where('id', $jobId)->where('status', 'running')->whereNotNull('worker_id')
            ->update(['phase' => $phase, 'heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        if ($changed !== 1) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_STATE_CONFLICT', false);
    }

    /** 验证搜索摘要与导入前重新读取的结果完全一致。 */
    private function summaryMatches(stdClass $row, array $summary): bool
    {
        return $summary['id'] === (string) $row->remote_asset_id
            && $summary['providerKey'] === (string) $row->provider_key
            && $summary['kind'] === (string) $row->artwork_kind && $summary['mimeType'] === (string) $row->mime_type
            && $summary['width'] === (int) $row->width && $summary['height'] === (int) $row->height
            && $summary['sizeBytes'] === (int) $row->size_bytes
            && hash_equals((string) $row->resource_sha256, $summary['sha256'])
            && (int) $row->attribution_required === ($summary['attribution']['required'] ? 1 : 0)
            && (string) $row->attribution_text === $summary['attribution']['text']
            && ($row->attribution_url === null ? null : (string) $row->attribution_url) === $summary['attribution']['url'];
    }

    /** 验证实际字节事实与冻结摘要完全一致。 */
    private function assetMatches(stdClass $row, array $asset): bool
    {
        return $asset['id'] === (string) $row->remote_asset_id
            && $asset['mimeType'] === (string) $row->mime_type && $asset['width'] === (int) $row->width
            && $asset['height'] === (int) $row->height && $asset['sizeBytes'] === (int) $row->size_bytes
            && hash_equals((string) $row->resource_sha256, $asset['sha256']);
    }

    /** @return array{x:int,y:int,width:int,height:int} 保存与规范化实际一致的万分比居中裁剪框。 */
    private function centerCrop(int $width, int $height): array
    {
        if ($width === $height) return ['x' => 0, 'y' => 0, 'width' => 10_000, 'height' => 10_000];
        if ($width > $height) {
            $cropWidth = max(1, (int) round(10_000 * $height / $width));
            return ['x' => intdiv(10_000 - $cropWidth, 2), 'y' => 0, 'width' => $cropWidth, 'height' => 10_000];
        }
        $cropHeight = max(1, (int) round(10_000 * $width / $height));
        return ['x' => 0, 'y' => intdiv(10_000 - $cropHeight, 2), 'width' => 10_000, 'height' => $cropHeight];
    }

    /** 校验内部领取消息只含任务 ULID。 */
    private function claimedId(array $claimed): string
    {
        $id = $claimed['id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_JOB_INVALID', false);
        }
        return $id;
    }

    /** 延迟创建内置网关；没有在线封面任务时不启动查询器、DNS 或图片下载。 */
    private function gateway(): ArtworkProviderGateway
    {
        return $this->gateway ??= new BuiltinArtworkProviderGateway();
    }

    private function type(stdClass $row): string
    {
        if (property_exists($row, 'song_id') && $row->song_id !== null) return 'song';
        return $row->album_id === null ? 'artist' : 'album';
    }

    private function entityId(stdClass $row): string
    {
        return (string) ($row->song_id ?? $row->album_id ?? $row->artist_id);
    }
}
