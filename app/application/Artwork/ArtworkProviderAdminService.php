<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Auth\AuthorizationDenied;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 编排实体封面的远程 Provider 搜索、显式预览和导入命令。
 *
 * Controller 先验证 `edit_metadata`，本服务额外要求 `run_scrape`，并通过证据服务复验实体全部关联库
 * 的 manage 范围。该接口是历史远程封面工作台的兼容边界；搜索和导入请求只创建持久任务，不执行网络
 * 或图片处理，Worker 只调用应用内置固定平台查询器且不读取外部项目配置。图片字节不会进入任务表、
 * 审计、日志或错误响应；只有显式导入后的规范 WebP 候选随 SQLite 备份保存。
 */
final class ArtworkProviderAdminService
{
    /**
     * 自动补图规则的幂等命名空间版本。
     *
     * 自动搜索会长期保留“成功但零候选”的终态；当艺人身份硬门槛或 Provider 匹配算法发生语义变化时，
     * 必须递增此版本，让旧规则冻结的空结果只重新评估一次。同一版本仍绑定实体、音乐库和证据摘要，
     * 因此普通轮询、进程重启和并发重试不会重复访问第三方或创建重复任务。
     */
    private const AUTOMATIC_SEARCH_RULE_VERSION = 5;

    private ?ArtworkProviderGateway $gateway;

    public function __construct(
        private readonly ArtworkProviderEvidenceService $evidence = new ArtworkProviderEvidenceService(),
        ?ArtworkProviderGateway $gateway = null,
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
        $this->gateway = $gateway;
    }

    /**
     * 创建一个实体/库级远程封面搜索任务。
     *
     * 相同账号和 Idempotency-Key 只重放同一实体、库与证据；同一作用域已有活动搜索时返回冲突。
     * 任务只冻结摘要和代表歌曲 ID，HTTP 请求不会访问第三方。任务与脱敏审计同事务提交。
     *
     * @param array<string,mixed> $actor 当前身份快照。
     * @return array<string,mixed> 无图片字节和远端身份的任务投影。
     */
    public function createSearch(string $type, string $entityId, string $libraryId, string $idempotencyKey,
        array $actor, string $requestId, ?string $scrapeTargetId = null): array
    {
        $this->requireRunScrape($actor);
        $facts = $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        return $this->queueSearch($facts, $idempotencyKey, $actor, $requestId, false, $scrapeTargetId)['job'];
    }

    /**
     * 为系统缺图补全创建高置信度搜索，并返回本次是否实际新增任务。
     *
     * 该入口不暴露给 HTTP；调用方必须传入真实活动超级管理员快照。幂等键绑定实体、库和当前证据摘要，
     * 因此空结果不会在每个轮询周期重复请求，而代表歌曲或元数据变化后可以重新评估。自动标记只允许
     * Worker 导入并填补空缺，不改变手工搜索、预览和选择的原有语义。
     *
     * @param array<string,mixed> $actor 当前超级管理员身份快照。
     */
    public function createAutomaticSearch(string $type, string $entityId, string $libraryId, array $actor,
        string $requestId, ?string $scrapeTargetId = null): bool
    {
        $this->requireRunScrape($actor);
        if (($actor['isSuperAdmin'] ?? false) !== true) throw new AuthorizationDenied('自动补图仅允许系统管理员。');
        $facts = $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        $key = 'artwork-auto-v' . self::AUTOMATIC_SEARCH_RULE_VERSION . '-' . hash('sha256', implode('|', [
            $type, $entityId, $libraryId, $facts['evidenceSha256'], $scrapeTargetId ?? 'independent',
        ]));
        return $this->queueSearch($facts, $key, $actor, $requestId, true, $scrapeTargetId)['created'];
    }

    /**
     * 原子创建手工或自动搜索，并把幂等重放限制在完全相同的任务语义。
     *
     * @param array<string,mixed> $facts 已通过实体全部库权限验证的冻结证据。
     * @param array<string,mixed> $actor 当前身份快照。
     * @return array{job:array<string,mixed>,created:bool}
     */
    private function queueSearch(array $facts, string $idempotencyKey, array $actor, string $requestId,
        bool $autoImport, ?string $scrapeTargetId = null): array
    {
        $digest = $this->idempotencyDigest($idempotencyKey);
        /** @var stdClass|null $existing */
        $existing = Db::table('artwork_provider_search_jobs')->where('requested_by', (string) $actor['id'])
            ->where('idempotency_key_sha256', $digest)->first();
        if ($existing instanceof stdClass) {
            if ($this->type($existing) !== $facts['type'] || $this->entityId($existing) !== $facts['entityId']
                || (string) $existing->library_id !== $facts['libraryId']
                || (int) ($existing->auto_import ?? 0) !== ($autoImport ? 1 : 0)
                || ($this->supportsScrapeTargetLink()
                    && ($existing->scrape_target_id === null ? null : (string) $existing->scrape_target_id)
                        !== $scrapeTargetId)
                || !hash_equals((string) $existing->evidence_sha256, $facts['evidenceSha256'])) {
                throw new ArtworkAdminConflict('幂等键已用于另一项远程封面搜索。');
            }
            return ['job' => $this->searchProjection($existing), 'created' => false];
        }
        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actor, $autoImport, $digest, $facts, $jobId, $now, $requestId,
                $scrapeTargetId): void {
                $this->evidence->scoped($facts['type'], $facts['entityId'], $facts['libraryId'], $actor);
                $row = [
                    'id' => $jobId, 'song_id' => $facts['type'] === 'song' ? $facts['entityId'] : null,
                    'album_id' => $facts['type'] === 'album' ? $facts['entityId'] : null,
                    'artist_id' => $facts['type'] === 'artist' ? $facts['entityId'] : null,
                    'library_id' => $facts['libraryId'], 'evidence_song_id' => $facts['songId'],
                    'requested_by' => (string) $actor['id'], 'request_id' => $requestId,
                    'idempotency_key_sha256' => $digest, 'evidence_sha256' => $facts['evidenceSha256'],
                    'locale' => $facts['locale'], 'region' => $facts['region'], 'status' => 'queued',
                    'phase' => 'queued', 'remote_job_id' => null, 'remote_result_id' => null,
                    'auto_import' => $autoImport ? 1 : 0,
                    'candidate_count' => 0, 'failure_count' => 0, 'next_attempt_at' => $now,
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null, 'version' => 1,
                    'started_at' => null, 'finished_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                if ($this->supportsScrapeTargetLink()) $row['scrape_target_id'] = $scrapeTargetId;
                Db::table('artwork_provider_search_jobs')->insert($row);
                $this->audit->record((string) $actor['id'], $autoImport
                    ? 'artwork.provider.backfill.queue' : 'artwork.provider.search.queue',
                    'artwork_provider_search_job', $jobId, 'success', $requestId, [
                        'entityType' => $facts['type'], 'entityId' => $facts['entityId'],
                        'libraryId' => $facts['libraryId'],
                    ]);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ArtworkAdminConflict('该实体已有进行中的远程封面搜索。', previous: $exception);
            }
            throw $exception;
        }
        /** @var stdClass $created */
        $created = Db::table('artwork_provider_search_jobs')->where('id', $jobId)->first();
        return ['job' => $this->searchProjection($created), 'created' => true];
    }

    /**
     * 返回最近或指定搜索及无字节候选摘要。
     *
     * 跨实体、跨库、不存在和失权统一表现为无对象。响应不包含远端 asset ID、摘要、服务地址或原始
     * Provider 响应；许可和 attribution 可展示，预览 URL 始终指向本项目受控端点。
     *
     * @return array{job:?array<string,mixed>,candidates:list<array<string,mixed>>}
     */
    public function search(string $type, string $entityId, string $libraryId, ?string $jobId, array $actor): array
    {
        $this->requireRunScrape($actor);
        $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        $idColumn = $type . '_id';
        $query = Db::table('artwork_provider_search_jobs')->where($idColumn, $entityId)
            ->where('library_id', $libraryId);
        if ($jobId !== null) { $this->requireUlid($jobId); $query->where('id', $jobId); }
        /** @var stdClass|null $job */
        $job = $query->orderByDesc('created_at')->first();
        if (!$job instanceof stdClass) return ['job' => null, 'candidates' => []];
        /** @var list<stdClass> $rows */
        $rows = Db::table('artwork_provider_candidates')->where('search_job_id', (string) $job->id)
            ->orderBy('provider_key')->orderBy('id')->get()->all();
        return ['job' => $this->searchProjection($job),
            'candidates' => array_map(fn (stdClass $row): array => $this->candidateProjection(
                $type, $entityId, $libraryId, (string) $job->id, $row,
            ), $rows)];
    }

    /**
     * 显式读取一张候选原图用于人工预览。
     *
     * 网络读取前后都复验实体范围和冻结证据，并把返回字节与候选的 MIME、尺寸、大小、SHA-256 完全
     * 对照。许可撤回、摘要变化或授权撤回均失败关闭；成功字节只交给 Controller 当前响应。
     *
     * @return array{bytes:string,mimeType:string,etag:string,attribution:array<string,mixed>}
     */
    public function preview(string $type, string $entityId, string $libraryId, string $searchJobId,
        string $candidateId, array $actor): array
    {
        $this->requireRunScrape($actor);
        $before = $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        $row = $this->candidateRow($type, $entityId, $libraryId, $searchJobId, $candidateId);
        if (!hash_equals((string) $row->evidence_sha256, $before['evidenceSha256'])) {
            throw new ArtworkAdminConflict('实体匹配证据已变化，请重新搜索。');
        }
        $asset = $this->gateway()->asset((string) $row->remote_asset_id);
        $after = $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        if (!hash_equals($before['evidenceSha256'], $after['evidenceSha256']) || !$this->assetMatches($row, $asset)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
        }
        return ['bytes' => $asset['bytes'], 'mimeType' => $asset['mimeType'],
            'etag' => '"provider-' . substr((string) $row->resource_sha256, 0, 32) . '"',
            'attribution' => ['required' => (int) $row->attribution_required === 1,
                'text' => (string) $row->attribution_text,
                'url' => $row->attribution_url === null ? null : (string) $row->attribution_url]];
    }

    /**
     * 为冻结候选创建显式导入任务。
     *
     * expectedSearchVersion 防止用户从已变化搜索中导入；Worker 会重新读取结果和原图并再次检查许可、
     * 摘要、账号和实体范围。成功只新增手工候选，不自动选择，创建响应不代表导入完成。
     *
     * @return array<string,mixed>
     */
    public function createImport(string $type, string $entityId, string $libraryId, string $searchJobId,
        string $candidateId, int $expectedSearchVersion, string $idempotencyKey, array $actor,
        string $requestId, ?string $scrapeTargetId = null): array
    {
        $this->requireRunScrape($actor);
        if ($expectedSearchVersion < 1) throw new ArtworkAdminInvalid('远程封面搜索版本无效。');
        $facts = $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        $candidate = $this->candidateRow($type, $entityId, $libraryId, $searchJobId, $candidateId);
        if ((int) $candidate->search_version !== $expectedSearchVersion
            || !hash_equals((string) $candidate->evidence_sha256, $facts['evidenceSha256'])) {
            throw new ArtworkAdminConflict('远程封面搜索结果已变化，请刷新。');
        }
        $digest = $this->idempotencyDigest($idempotencyKey);
        /** @var stdClass|null $existing */
        $existing = Db::table('artwork_provider_import_jobs')->where('requested_by', (string) $actor['id'])
            ->where('idempotency_key_sha256', $digest)->first();
        if ($existing instanceof stdClass) {
            if ((string) $existing->candidate_id !== $candidateId || $this->type($existing) !== $type
                || $this->entityId($existing) !== $entityId || (string) $existing->library_id !== $libraryId
                || ($this->supportsScrapeTargetLink()
                    && ($existing->scrape_target_id === null ? null : (string) $existing->scrape_target_id)
                        !== $scrapeTargetId)) {
                throw new ArtworkAdminConflict('幂等键已用于另一项远程封面导入。');
            }
            return $this->importProjection($existing);
        }
        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actor, $candidateId, $digest, $facts, $jobId, $now,
                $requestId, $scrapeTargetId, $searchJobId): void {
                $this->evidence->scoped($facts['type'], $facts['entityId'], $facts['libraryId'], $actor);
                $row = [
                    'id' => $jobId, 'search_job_id' => $searchJobId, 'candidate_id' => $candidateId,
                    'song_id' => $facts['type'] === 'song' ? $facts['entityId'] : null,
                    'album_id' => $facts['type'] === 'album' ? $facts['entityId'] : null,
                    'artist_id' => $facts['type'] === 'artist' ? $facts['entityId'] : null,
                    'library_id' => $facts['libraryId'], 'requested_by' => (string) $actor['id'],
                    'request_id' => $requestId, 'idempotency_key_sha256' => $digest,
                    'evidence_sha256' => $facts['evidenceSha256'], 'status' => 'queued', 'phase' => 'queued',
                    'imported_candidate_id' => null, 'failure_count' => 0, 'next_attempt_at' => $now,
                    'worker_id' => null, 'heartbeat_at' => null, 'error_code' => null, 'version' => 1,
                    'started_at' => null, 'finished_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                if ($this->supportsScrapeTargetLink()) $row['scrape_target_id'] = $scrapeTargetId;
                Db::table('artwork_provider_import_jobs')->insert($row);
                $this->audit->record((string) $actor['id'], 'artwork.provider.import.queue',
                    'artwork_provider_import_job', $jobId, 'success', $requestId, [
                        'entityType' => $facts['type'], 'entityId' => $facts['entityId'],
                        'libraryId' => $facts['libraryId'], 'searchJobId' => $searchJobId,
                        'candidateId' => $candidateId,
                    ]);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ArtworkAdminConflict('该实体已有进行中的远程封面导入。', previous: $exception);
            }
            throw $exception;
        }
        /** @var stdClass $job */
        $job = Db::table('artwork_provider_import_jobs')->where('id', $jobId)->first();
        return $this->importProjection($job);
    }

    /** 返回重新授权后的导入任务快照，不暴露远端 ID、摘要、Worker 或幂等字段。 */
    public function import(string $type, string $entityId, string $libraryId, string $jobId, array $actor): array
    {
        $this->requireRunScrape($actor);
        $this->evidence->scoped($type, $entityId, $libraryId, $actor);
        $this->requireUlid($jobId);
        /** @var stdClass|null $job */
        $job = Db::table('artwork_provider_import_jobs')->where('id', $jobId)
            ->where($type . '_id', $entityId)->where('library_id', $libraryId)->first();
        if (!$job instanceof stdClass) throw new ArtworkAdminNotFound('远程封面导入任务不存在。');
        return $this->importProjection($job);
    }

    /** 读取成功搜索下的内部候选，并统一隐藏跨实体、跨库或不存在对象。 */
    private function candidateRow(string $type, string $entityId, string $libraryId, string $searchJobId,
        string $candidateId): stdClass
    {
        $this->requireUlid($searchJobId);
        $this->requireUlid($candidateId);
        /** @var stdClass|null $row */
        $row = Db::table('artwork_provider_candidates as candidates')
            ->join('artwork_provider_search_jobs as jobs', 'jobs.id', '=', 'candidates.search_job_id')
            ->where('jobs.id', $searchJobId)->where('jobs.' . $type . '_id', $entityId)
            ->where('jobs.library_id', $libraryId)->where('jobs.status', 'succeeded')
            ->where('candidates.id', $candidateId)->first([
                'candidates.*', 'jobs.remote_job_id', 'jobs.remote_result_id', 'jobs.evidence_sha256',
                'jobs.version as search_version',
            ]);
        if (!$row instanceof stdClass) throw new ArtworkAdminNotFound('远程封面候选不存在。');
        return $row;
    }

    /** @return array<string,mixed> */
    private function searchProjection(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'status' => (string) $row->status, 'phase' => (string) $row->phase,
            'candidateCount' => (int) $row->candidate_count, 'errorCode' => $row->error_code,
            'version' => (int) $row->version, 'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at, 'finishedAt' => $row->finished_at];
    }

    /** @return array<string,mixed> */
    private function candidateProjection(string $type, string $entityId, string $libraryId, string $jobId,
        stdClass $row): array
    {
        $base = '/api/v1/admin/artworks/' . $type . '/' . rawurlencode($entityId) . '/provider-searches/'
            . rawurlencode($jobId) . '/candidates/' . rawurlencode((string) $row->id) . '/preview';
        return ['id' => (string) $row->id, 'providerKey' => (string) $row->provider_key,
            'kind' => (string) $row->artwork_kind, 'mimeType' => (string) $row->mime_type,
            'width' => (int) $row->width, 'height' => (int) $row->height, 'sizeBytes' => (int) $row->size_bytes,
            'previewUrl' => $base . '?libraryId=' . rawurlencode($libraryId),
            'attribution' => ['required' => (int) $row->attribution_required === 1,
                'text' => (string) $row->attribution_text,
                'url' => $row->attribution_url === null ? null : (string) $row->attribution_url]];
    }

    /** @return array<string,mixed> */
    private function importProjection(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'searchJobId' => (string) $row->search_job_id,
            'candidateId' => (string) $row->candidate_id, 'status' => (string) $row->status,
            'phase' => (string) $row->phase, 'importedCandidateId' => $row->imported_candidate_id,
            'errorCode' => $row->error_code, 'version' => (int) $row->version,
            'createdAt' => (string) $row->created_at, 'startedAt' => $row->started_at,
            'finishedAt' => $row->finished_at];
    }

    /** 验证 asset 字节仍与搜索时冻结摘要相同。 */
    private function assetMatches(stdClass $row, array $asset): bool
    {
        return $asset['id'] === (string) $row->remote_asset_id
            && $asset['mimeType'] === (string) $row->mime_type && $asset['width'] === (int) $row->width
            && $asset['height'] === (int) $row->height && $asset['sizeBytes'] === (int) $row->size_bytes
            && hash_equals((string) $row->resource_sha256, $asset['sha256']);
    }

    /** 外部搜索额外要求 run_scrape；只有 edit_metadata 的本地编辑者不能触发网络任务。 */
    private function requireRunScrape(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('run_scrape', $capabilities, true)) throw new AuthorizationDenied('缺少在线刮削权限。');
    }

    /** Idempotency-Key 只持久化 SHA-256，原值不会进入数据库、审计或日志。 */
    private function idempotencyDigest(string $key): string
    {
        if (strlen($key) < 16 || strlen($key) > 128 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
            throw new ArtworkAdminInvalid('Idempotency-Key 无效。');
        }
        return hash('sha256', $key);
    }

    /** 延迟创建内置网关；普通封面详情读取不会触发 DNS、平台查询或图片下载。 */
    private function gateway(): ArtworkProviderGateway
    {
        return $this->gateway ??= new BuiltinArtworkProviderGateway();
    }

    /**
     * 判断当前数据库是否已经具备逐曲父目标关联列。
     *
     * 滚动部署及单元测试可能短暂运行旧 schema；旧环境仍能执行独立封面操作，但不能伪造已经接入逐曲
     * 状态机。检查只读取 schema 缓存，不修改任务，迁移完成后所有新编排任务都会保存父目标外键。
     */
    private function supportsScrapeTargetLink(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('artwork_provider_search_jobs', 'scrape_target_id')
            && $schema->hasColumn('artwork_provider_import_jobs', 'scrape_target_id');
    }

    /** 返回任务实体类型；迁移约束保证三列恰好一个非空。 */
    private function type(stdClass $row): string
    {
        if (property_exists($row, 'song_id') && $row->song_id !== null) return 'song';
        return $row->album_id === null ? 'artist' : 'album';
    }

    /** 返回任务实体 ID，不把数据库内部列名泄漏给调用方。 */
    private function entityId(stdClass $row): string
    {
        return (string) ($row->song_id ?? $row->album_id ?? $row->artist_id);
    }

    /** 校验公开对象标识形状；存在与权限由联合范围查询决定。 */
    private function requireUlid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new ArtworkAdminInvalid('对象标识无效。');
    }
}
