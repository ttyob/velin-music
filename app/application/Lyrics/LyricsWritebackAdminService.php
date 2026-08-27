<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\application\User\HighCostJobPolicy;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 编排管理员歌词 sidecar 写回的不可变预览、强确认和任务查询。
 *
 * 浏览器只能提交歌曲、歌词和预期版本等不透明标识，不能提交服务器路径、目标文件名或歌词正文。
 * 每个查询都重复应用当前音乐库授权范围；全局 `edit_metadata` 由 Controller 校验，普通身份还必须
 * 持有该库任一级实时 grant。预览从媒体清单生成目标并冻结歌词/音乐库版本、
 * 音频四元身份和输出摘要，确认再次读取数据库与文件系统。HTTP 请求只提交持久任务，实际写盘只能
 * 由 Durable Worker 完成。审计元数据不含歌词正文、物理路径或原始幂等键。
 */
final class LyricsWritebackAdminService
{
    public const CONFIRMATION_TEXT = 'WRITE LYRICS';
    public const REPLACEMENT_CONFIRMATION_TEXT = 'REPLACE LYRICS';
    private const PLAN_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly HighCostJobPolicy $highCostJobs = new HighCostJobPolicy(),
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    ) {
    }

    /**
     * 生成一个无正文、24 小时有效的 Dry Run 方案。
     *
     * 预览会读取歌词正文进行确定性序列化，但只持久化输入/输出摘要和字节数。目标已存在时仍返回
     * `conflict` 预览供界面解释，但该方案不可确认；本版本没有覆盖开关。
     *
     * @param array<string,mixed> $actor 已通过全局 `edit_metadata` capability 的身份快照。
     * @return array<string,mixed> 不包含真实路径和歌词正文的管理投影。
     */
    public function createPlan(
        string $songId,
        string $lyricId,
        int $expectedLyricVersion,
        array $actor,
        string $requestId,
        bool $replaceExisting = false,
    ): array {
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::PLAN_TTL_SECONDS);
        $prepared = $this->preparePlan(
            $songId,
            $lyricId,
            $expectedLyricVersion,
            $actor,
            $expiresAt,
            $replaceExisting,
        );

        Db::transaction(function () use ($actor, $prepared, $requestId): void {
            $this->insertPreparedPlan($prepared, $actor);
            $this->audit->record(
                (string) $actor['id'],
                'lyrics.writeback.plan.create',
                'lyrics_writeback_plan',
                $prepared['id'],
                'success',
                $requestId,
                [
                    'songId' => $prepared['songId'],
                    'lyricId' => $prepared['lyricId'],
                    'libraryId' => $prepared['libraryId'],
                    'lyricVersion' => $prepared['lyricVersion'],
                    'targetState' => $prepared['targetState'],
                    'replaceExisting' => $prepared['replaceExisting'],
                ],
            );
        });

        return $this->detail($prepared['id'], $actor);
    }

    /**
     * 在任何写事务之外生成一份单曲不可变方案快照。
     *
     * 该步骤会读取歌词正文和文件系统进行确定性序列化及只读预检，因此调用方必须在开启 SQLite
     * 写事务前完成。返回数组只在当前调用栈内存在；`content` 不得写入方案、审计、日志或异常。
     * 批量预览与单曲预览共用此入口，保证许可、路径和文件身份规则不会分叉。
     *
     * @param array<string,mixed> $actor 已通过全局能力校验且包含实时音乐库范围的身份快照。
     * @return array<string,mixed> 包含持久化字段和仅用于计算摘要的临时正文。
     */
    private function preparePlan(
        string $songId,
        string $lyricId,
        int $expectedLyricVersion,
        array $actor,
        string $expiresAt,
        bool $replaceExisting = false,
    ): array {
        $this->requireUlid($songId, '歌曲标识无效。');
        $this->requireUlid($lyricId, '歌词标识无效。');
        if ($expectedLyricVersion < 1) {
            throw new LyricsWritebackInvalid('歌词版本无效。');
        }
        $source = $this->findScopedSource($songId, $lyricId, $actor);
        if ((int) $source->lyric_version !== $expectedLyricVersion) {
            throw new LyricsWritebackConflict('歌词版本已变化，请刷新后重新预览。');
        }
        if (!in_array((string) $source->license_policy, ['local_controlled', 'redistributable'], true)) {
            throw new LyricsWritebackInvalid('该歌词许可不允许写入文件。');
        }

        $content = $this->serializeSource($source);
        $targetRelativePath = $this->targetRelativePath(
            (string) $source->relative_path,
            (string) $source->language,
        );
        $target = $this->preflightFilesystem($source, $targetRelativePath);
        $targetState = $target['state'];
        if ($replaceExisting && ($targetState !== 'conflict' || $target['identity'] === null)) {
            throw new LyricsWritebackConflict('已有歌词不是可安全替换的普通小文件，请刷新后核对。');
        }
        $planId = (string) new Ulid();
        $fingerprint = [
            'planId' => $planId,
            'songId' => $songId,
            'lyricId' => $lyricId,
            'libraryId' => (string) $source->library_id,
            'inventoryFileId' => (string) $source->inventory_file_id,
            'lyricVersion' => (int) $source->lyric_version,
            'libraryVersion' => (int) $source->library_version,
            'sourceKind' => (string) $source->source_kind,
            'sourceFormat' => (string) $source->source_format,
            'language' => (string) $source->language,
            'lyricKind' => (string) $source->lyric_kind,
            'matchScore' => $source->match_score === null ? null : (float) $source->match_score,
            'licensePolicy' => (string) $source->license_policy,
            'lyricContentSha256' => (string) $source->content_sha256,
            'outputSha256' => hash('sha256', $content),
            'outputSizeBytes' => strlen($content),
            'audioIdentity' => [
                (int) $source->device_id,
                (int) $source->inode,
                (int) $source->file_size,
                (int) $source->modified_at,
            ],
            'targetRelativePath' => $targetRelativePath,
            'targetState' => $targetState,
            'replaceExisting' => $replaceExisting,
            'existingTargetIdentity' => $target['identity'],
            'expiresAt' => $expiresAt,
        ];
        $planHash = hash('sha256', json_encode(
            $fingerprint,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return [
            'id' => $planId,
            'songId' => $songId,
            'songTitle' => (string) $source->song_title,
            'lyricId' => $lyricId,
            'libraryId' => (string) $source->library_id,
            'libraryName' => (string) $source->library_name,
            'inventoryFileId' => (string) $source->inventory_file_id,
            'lyricVersion' => (int) $source->lyric_version,
            'libraryVersion' => (int) $source->library_version,
            'sourceKind' => (string) $source->source_kind,
            'sourceFormat' => (string) $source->source_format,
            'language' => (string) $source->language,
            'lyricKind' => (string) $source->lyric_kind,
            'matchScore' => $source->match_score === null ? null : (float) $source->match_score,
            'licensePolicy' => (string) $source->license_policy,
            'lyricContentSha256' => (string) $source->content_sha256,
            'outputSha256' => hash('sha256', $content),
            'outputSizeBytes' => strlen($content),
            'sourceDevice' => (int) $source->device_id,
            'sourceInode' => (int) $source->inode,
            'sourceFileSize' => (int) $source->file_size,
            'sourceModifiedAt' => (int) $source->modified_at,
            'targetRelativePath' => $targetRelativePath,
            'targetState' => $targetState,
            'replaceExisting' => $replaceExisting,
            'replacementAvailable' => $target['identity'] !== null,
            'existingTargetDevice' => $target['identity']['device'] ?? null,
            'existingTargetInode' => $target['identity']['inode'] ?? null,
            'existingTargetSize' => $target['identity']['size'] ?? null,
            'existingTargetModifiedAt' => $target['identity']['modifiedAt'] ?? null,
            'existingTargetSha256' => $target['identity']['sha256'] ?? null,
            'planHash' => $planHash,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * 在调用方已经开启的短事务内插入准备完成的单曲方案。
     *
     * 该方法不执行文件 I/O、不读取歌词正文，也不自行开启事务。准备快照只可来自 `preparePlan()`；
     * 浏览器数据不能直接传入。失败由外层事务整体回滚，不会留下半个批次。
     *
     * @param array<string,mixed> $prepared 当前调用栈内生成的可信快照。
     * @param array<string,mixed> $actor 创建者身份快照。
     */
    private function insertPreparedPlan(array $prepared, array $actor): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('lyrics_writeback_plans')->insert([
            'id' => $prepared['id'],
            'song_id' => $prepared['songId'],
            'lyric_id' => $prepared['lyricId'],
            'library_id' => $prepared['libraryId'],
            'inventory_file_id' => $prepared['inventoryFileId'],
            'created_by' => (string) $actor['id'],
            'status' => 'planned',
            'expected_lyric_version' => $prepared['lyricVersion'],
            'expected_library_version' => $prepared['libraryVersion'],
            'source_kind' => $prepared['sourceKind'],
            'source_format' => $prepared['sourceFormat'],
            'language' => $prepared['language'],
            'lyric_kind' => $prepared['lyricKind'],
            'match_score' => $prepared['matchScore'],
            'license_policy' => $prepared['licensePolicy'],
            'lyric_content_sha256' => $prepared['lyricContentSha256'],
            'output_sha256' => $prepared['outputSha256'],
            'output_size_bytes' => $prepared['outputSizeBytes'],
            'source_device' => $prepared['sourceDevice'],
            'source_inode' => $prepared['sourceInode'],
            'source_file_size' => $prepared['sourceFileSize'],
            'source_modified_at' => $prepared['sourceModifiedAt'],
            'target_relative_path' => $prepared['targetRelativePath'],
            'target_state' => $prepared['targetState'],
            'replace_existing' => $prepared['replaceExisting'] ? 1 : 0,
            'existing_target_device' => $prepared['existingTargetDevice'],
            'existing_target_inode' => $prepared['existingTargetInode'],
            'existing_target_size' => $prepared['existingTargetSize'],
            'existing_target_modified_at' => $prepared['existingTargetModifiedAt'],
            'existing_target_sha256' => $prepared['existingTargetSha256'],
            'plan_hash' => $prepared['planHash'],
            'version' => 1,
            'expires_at' => $prepared['expiresAt'],
            'confirmed_at' => null,
            'finished_at' => null,
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** 返回一个当前管理范围内的方案及可选任务状态，不读取或返回歌词正文。 */
    public function detail(string $planId, array $actor): array
    {
        $plan = $this->findScopedPlan($planId, $actor);
        /** @var stdClass|null $job */
        $job = Db::table('lyrics_writeback_jobs')->where('plan_id', $planId)->first([
            'id', 'status', 'phase', 'attempt', 'error_code', 'created_at', 'started_at', 'finished_at',
        ]);

        return $this->mapPlan($plan, $job);
    }

    /**
     * 为一组明确选择的歌曲/歌词版本创建不可变批量 sidecar 预览。
     *
     * 输入最多 50 首且同一歌曲只能出现一次。所有歌词与文件系统预检先在写事务外完成；随后单曲
     * 方案、父方案、目标关联和一条无正文审计在同一短事务写入。已有目标会作为 `conflict` 保留在
     * 预览中，但确认时不会排队，方便管理员一次看清部分可执行结果。
     *
     * @param list<array{songId:string,lyricId:string,expectedLyricVersion:int}> $targets
     * @param array<string,mixed> $actor 已通过 `edit_metadata` 的实时身份快照。
     * @return array<string,mixed> 不含正文、绝对路径、内容摘要和文件身份的批量投影。
     */
    public function createBatchPlan(
        array $targets,
        array $actor,
        string $requestId,
        bool $replaceExisting = false,
    ): array
    {
        if ($targets === [] || count($targets) > 50 || !array_is_list($targets)) {
            throw new LyricsWritebackInvalid('批量写回目标必须为 1 到 50 首歌曲。');
        }
        $seenSongs = [];
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::PLAN_TTL_SECONDS);
        $preparedTargets = [];
        foreach ($targets as $target) {
            if (!is_array($target) || array_keys($target) !== ['songId', 'lyricId', 'expectedLyricVersion']) {
                throw new LyricsWritebackInvalid('批量写回目标结构无效。');
            }
            if (!is_string($target['songId']) || !is_string($target['lyricId'])
                || !is_int($target['expectedLyricVersion']) || $target['expectedLyricVersion'] < 1) {
                throw new LyricsWritebackInvalid('批量写回目标标识或版本无效。');
            }
            if (isset($seenSongs[$target['songId']])) {
                throw new LyricsWritebackInvalid('同一歌曲不能在一个批量方案中重复出现。');
            }
            $seenSongs[$target['songId']] = true;
            $prepared = $this->preparePlan(
                $target['songId'],
                $target['lyricId'],
                $target['expectedLyricVersion'],
                $actor,
                $expiresAt,
            );
            if ($replaceExisting && $prepared['targetState'] === 'conflict'
                && $prepared['replacementAvailable'] === true) {
                $prepared = $this->preparePlan(
                    $target['songId'],
                    $target['lyricId'],
                    $target['expectedLyricVersion'],
                    $actor,
                    $expiresAt,
                    true,
                );
            }
            $preparedTargets[] = $prepared;
        }

        $batchId = (string) new Ulid();
        $confirmableCount = count(array_filter(
            $preparedTargets,
            static fn (array $target): bool => self::preparedConfirmable($target),
        ));
        $replacementCount = count(array_filter(
            $preparedTargets,
            static fn (array $target): bool => $target['replaceExisting'] === true,
        ));
        $fingerprint = [
            'batchId' => $batchId,
            'expiresAt' => $expiresAt,
            'targets' => array_map(
                static fn (array $target, int $position): array => [
                    'position' => $position,
                    'writebackPlanId' => $target['id'],
                    'planHash' => $target['planHash'],
                    'targetState' => $target['targetState'],
                    'replaceExisting' => $target['replaceExisting'],
                ],
                $preparedTargets,
                array_keys($preparedTargets),
            ),
        ];
        $batchHash = hash('sha256', json_encode(
            $fingerprint,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use (
            $actor,
            $batchHash,
            $batchId,
            $confirmableCount,
            $expiresAt,
            $now,
            $preparedTargets,
            $replacementCount,
            $requestId,
        ): void {
            Db::table('lyrics_writeback_batch_plans')->insert([
                'id' => $batchId,
                'requested_by' => (string) $actor['id'],
                'request_id' => $requestId,
                'plan_hash' => $batchHash,
                'idempotency_key_sha256' => null,
                'target_count' => count($preparedTargets),
                'confirmable_count' => $confirmableCount,
                'conflict_count' => count($preparedTargets) - $confirmableCount,
                'replacement_count' => $replacementCount,
                'processed_count' => 0,
                'succeeded_count' => 0,
                'failed_count' => 0,
                'status' => 'draft',
                'version' => 1,
                'expires_at' => $expiresAt,
                'confirmed_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($preparedTargets as $position => $prepared) {
                $this->insertPreparedPlan($prepared, $actor);
                Db::table('lyrics_writeback_batch_targets')->insert([
                    'batch_plan_id' => $batchId,
                    'position' => $position,
                    'writeback_plan_id' => $prepared['id'],
                    'song_id' => $prepared['songId'],
                    'lyric_id' => $prepared['lyricId'],
                    'library_id' => $prepared['libraryId'],
                    'status' => self::preparedConfirmable($prepared) ? 'planned' : 'conflict',
                    'error_code' => self::preparedConfirmable($prepared) ? null : 'LYRICS_TARGET_CONFLICT',
                    'updated_at' => $now,
                ]);
            }
            $this->audit->record(
                (string) $actor['id'],
                'lyrics.writeback.batch.create',
                'lyrics_writeback_batch_plan',
                $batchId,
                'success',
                $requestId,
                [
                    'targetCount' => count($preparedTargets),
                    'confirmableCount' => $confirmableCount,
                    'conflictCount' => count($preparedTargets) - $confirmableCount,
                    'replacementCount' => $replacementCount,
                ],
            );
        });

        return $this->batchDetail($batchId, $actor);
    }

    /**
     * 返回批量方案及逐项安全结果，并在读取前从子任务事实重新汇总父状态。
     *
     * 非超级管理员必须仍能读取批次涉及的全部音乐库；任一目标失权会把整个批次隐藏为不存在，避免
     * 通过汇总计数推断其他库。同步只读取数据库，不访问歌词正文、媒体文件或第三方网络。
     */
    public function batchDetail(string $batchId, array $actor): array
    {
        $batch = $this->findScopedBatch($batchId, $actor);
        if ((string) $batch->status !== 'draft') {
            (new LyricsWritebackBatchStateService())->synchronize($batchId);
            $batch = $this->findScopedBatch($batchId, $actor);
        }
        /** @var list<stdClass> $targets */
        $targets = Db::table('lyrics_writeback_batch_targets as targets')
            ->join('lyrics_writeback_plans as plans', 'plans.id', '=', 'targets.writeback_plan_id')
            ->join('media_songs as songs', 'songs.id', '=', 'targets.song_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'targets.library_id')
            ->leftJoin('lyrics_writeback_jobs as jobs', 'jobs.plan_id', '=', 'plans.id')
            ->where('targets.batch_plan_id', $batchId)->orderBy('targets.position')->get([
                'targets.position', 'targets.status as target_status', 'targets.error_code as target_error_code',
                'plans.id as writeback_plan_id', 'plans.source_kind', 'plans.source_format', 'plans.language',
                'plans.lyric_kind', 'plans.target_relative_path', 'plans.target_state', 'plans.output_size_bytes',
                'plans.lyric_id', 'plans.expected_lyric_version', 'plans.replace_existing',
                'plans.existing_target_sha256',
                'songs.id as song_id', 'songs.title as song_title', 'libraries.id as library_id',
                'libraries.name as library_name', 'jobs.id as job_id', 'jobs.status as job_status',
            ])->all();

        return $this->mapBatch($batch, $targets);
    }

    /**
     * 确认一个批量预览并原子创建所有可执行的单曲 Durable 子任务。
     *
     * 确认前在事务外逐项复验授权、歌词/库版本、音频身份和目标仍为空；任一可执行目标漂移会拒绝
     * 整批确认，避免用户确认的快照被悄悄缩减。预览时已存在的目标按固定冲突失败计入父任务，不会
     * 创建写回任务。账号高成本任务额度按实际子任务数一次校验。
     *
     * @param array<string,mixed> $actor 当前实时身份快照。
     * @return array<string,mixed> 已排队父方案及逐项任务标识，不含正文和文件身份。
     */
    public function confirmBatch(
        string $batchId,
        int $expectedVersion,
        string $planHash,
        string $confirmation,
        string $idempotencyKey,
        array $actor,
        string $requestId,
    ): array {
        $this->requireUlid($batchId, '批量写回方案标识无效。');
        if ($expectedVersion < 1 || preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1) {
            throw new LyricsWritebackInvalid('批量写回方案版本或摘要无效。');
        }
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128
            || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1) {
            throw new LyricsWritebackInvalid('幂等键必须是 16 到 128 个可见 ASCII 字符。');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $batch = $this->findScopedBatch($batchId, $actor);
        $expectedConfirmation = (int) ($batch->replacement_count ?? 0) > 0
            ? self::REPLACEMENT_CONFIRMATION_TEXT
            : self::CONFIRMATION_TEXT;
        if (!hash_equals($expectedConfirmation, $confirmation)) {
            throw new LyricsWritebackInvalid('歌词写回确认文本不正确。');
        }
        if ($batch->idempotency_key_sha256 !== null) {
            if ((string) $batch->requested_by === (string) $actor['id']
                && hash_equals((string) $batch->idempotency_key_sha256, $keyHash)) {
                return $this->batchDetail($batchId, $actor);
            }
            throw new LyricsWritebackConflict('批量方案已经确认或幂等键不匹配。');
        }
        if ((string) $batch->status !== 'draft' || (int) $batch->version !== $expectedVersion
            || !hash_equals((string) $batch->plan_hash, $planHash)) {
            throw new LyricsWritebackConflict('批量写回方案已变化，请重新预览。');
        }
        if ((string) $batch->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new LyricsWritebackConflict('批量写回方案已过期，请重新预览。');
        }
        if ((int) $batch->confirmable_count < 1) {
            throw new LyricsWritebackConflict('批量方案没有可写回目标。');
        }
        $plans = $this->findBatchPlans($batchId, 'planned');
        if (count($plans) !== (int) $batch->confirmable_count) {
            throw new LyricsWritebackConflict('批量写回目标已变化，请重新预览。');
        }
        foreach ($plans as $plan) {
            $this->assertPlanCurrent($plan, $actor);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use (
                $actor,
                $batch,
                $batchId,
                $expectedVersion,
                $keyHash,
                $now,
                $plans,
                $requestId,
            ): void {
                $this->highCostJobs->assertCanQueue((string) $actor['id'], count($plans));
                $changed = Db::table('lyrics_writeback_batch_plans')->where('id', $batchId)
                    ->where('status', 'draft')->where('version', $expectedVersion)
                    ->whereNull('idempotency_key_sha256')->update([
                        'status' => 'queued',
                        'idempotency_key_sha256' => $keyHash,
                        'processed_count' => (int) $batch->conflict_count,
                        'failed_count' => (int) $batch->conflict_count,
                        'version' => Db::raw('version + 1'),
                        'confirmed_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($changed !== 1) {
                    throw new LyricsWritebackConflict('批量写回方案已变化，请重新预览。');
                }
                Db::table('lyrics_writeback_batch_targets')->where('batch_plan_id', $batchId)
                    ->where('status', 'conflict')->update([
                        'status' => 'failed',
                        'error_code' => 'LYRICS_TARGET_CONFLICT',
                        'updated_at' => $now,
                    ]);
                foreach ($plans as $plan) {
                    $jobId = (string) new Ulid();
                    $planChanged = Db::table('lyrics_writeback_plans')->where('id', (string) $plan->id)
                        ->where('status', 'planned')->where('version', 1)->update([
                            'status' => 'queued',
                            'version' => Db::raw('version + 1'),
                            'confirmed_at' => $now,
                            'updated_at' => $now,
                        ]);
                    if ($planChanged !== 1) {
                        throw new LyricsWritebackConflict('批量中的单曲方案已变化，请重新预览。');
                    }
                    Db::table('lyrics_writeback_jobs')->insert([
                        'id' => $jobId,
                        'plan_id' => (string) $plan->id,
                        'library_id' => (string) $plan->library_id,
                        'song_id' => (string) $plan->song_id,
                        'lyric_id' => (string) $plan->lyric_id,
                        'requested_by' => (string) $actor['id'],
                        'request_id' => $requestId,
                        'idempotency_key_sha256' => hash('sha256', $keyHash . ':' . (string) $plan->id),
                        'status' => 'queued',
                        'phase' => 'queued',
                        'attempt' => 0,
                        'worker_id' => null,
                        'heartbeat_at' => null,
                        'started_at' => null,
                        'finished_at' => null,
                        'error_code' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    Db::table('lyrics_writeback_batch_targets')->where('batch_plan_id', $batchId)
                        ->where('writeback_plan_id', (string) $plan->id)->where('status', 'planned')->update([
                            'status' => 'queued',
                            'error_code' => null,
                            'updated_at' => $now,
                        ]);
                }
                $this->audit->record(
                    (string) $actor['id'],
                    'lyrics.writeback.batch.confirm',
                    'lyrics_writeback_batch_plan',
                    $batchId,
                    'success',
                    $requestId,
                    [
                        'targetCount' => (int) $batch->target_count,
                        'queuedCount' => count($plans),
                        'conflictCount' => (int) $batch->conflict_count,
                        'planVersion' => $expectedVersion,
                    ],
                );
            });
        } catch (QueryException $exception) {
            $replay = $this->findScopedBatch($batchId, $actor);
            if ($replay->idempotency_key_sha256 !== null
                && hash_equals((string) $replay->idempotency_key_sha256, $keyHash)) {
                return $this->batchDetail($batchId, $actor);
            }
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new LyricsWritebackConflict('批量方案已有任务或幂等键已被占用。', previous: $exception);
            }
            throw $exception;
        }

        return $this->batchDetail($batchId, $actor);
    }

    /**
     * 强确认一个仍然有效且无冲突的方案，并幂等地提交给 Worker。
     *
     * 相同操作者和原始 `Idempotency-Key` 只会返回第一次创建的任务；同一键用于另一方案会冲突。
     * 原始键只在请求内存中存在，数据库和审计仅接触 SHA-256。方案状态、任务和审计在同一短事务
     * 提交，事务中不执行文件 I/O。
     *
     * @return array<string,mixed> 不包含物理路径、歌词正文和原始幂等键的任务投影。
     */
    public function confirm(
        string $planId,
        int $expectedPlanVersion,
        string $planHash,
        string $confirmation,
        string $idempotencyKey,
        array $actor,
        string $requestId,
    ): array {
        $this->requireUlid($planId, '写回方案标识无效。');
        if ($expectedPlanVersion < 1 || preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1) {
            throw new LyricsWritebackInvalid('写回方案版本或摘要无效。');
        }
        if (
            strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128
            || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1
        ) {
            throw new LyricsWritebackInvalid('幂等键必须是 16 到 128 个可见 ASCII 字符。');
        }
        $plan = $this->findScopedPlan($planId, $actor);
        $expectedConfirmation = (int) ($plan->replace_existing ?? 0) === 1
            ? self::REPLACEMENT_CONFIRMATION_TEXT
            : self::CONFIRMATION_TEXT;
        if (!hash_equals($expectedConfirmation, $confirmation)) {
            throw new LyricsWritebackInvalid('歌词写回确认文本不正确。');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $replay = $this->findReplay((string) $actor['id'], $keyHash, $planId);
        if ($replay !== null) {
            return $replay;
        }

        if (
            (string) $plan->status !== 'planned'
            || (int) $plan->version !== $expectedPlanVersion
            || !hash_equals((string) $plan->plan_hash, $planHash)
        ) {
            throw new LyricsWritebackConflict('写回方案已变化，请重新预览。');
        }
        if ((string) $plan->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new LyricsWritebackConflict('写回方案已过期，请重新预览。');
        }
        if ((string) $plan->target_state !== 'absent' && (int) ($plan->replace_existing ?? 0) !== 1) {
            throw new LyricsWritebackConflict('歌词目标已存在，本版本不允许覆盖。');
        }
        $this->assertPlanCurrent($plan, $actor);

        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use (
                $actor,
                $expectedPlanVersion,
                $jobId,
                $keyHash,
                $now,
                $plan,
                $planId,
                $requestId,
            ): void {
                $this->highCostJobs->assertCanQueue((string) $actor['id']);
                $changed = Db::table('lyrics_writeback_plans')->where('id', $planId)
                    ->where('status', 'planned')->where('version', $expectedPlanVersion)->update([
                        'status' => 'queued',
                        'version' => Db::raw('version + 1'),
                        'confirmed_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($changed !== 1) {
                    throw new LyricsWritebackConflict('写回方案已变化，请重新预览。');
                }
                Db::table('lyrics_writeback_jobs')->insert([
                    'id' => $jobId,
                    'plan_id' => $planId,
                    'library_id' => (string) $plan->library_id,
                    'song_id' => (string) $plan->song_id,
                    'lyric_id' => (string) $plan->lyric_id,
                    'requested_by' => (string) $actor['id'],
                    'request_id' => $requestId,
                    'idempotency_key_sha256' => $keyHash,
                    'status' => 'queued',
                    'phase' => 'queued',
                    'attempt' => 0,
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'error_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->audit->record(
                    (string) $actor['id'],
                    'lyrics.writeback.plan.confirm',
                    'lyrics_writeback_plan',
                    $planId,
                    'success',
                    $requestId,
                    [
                        'jobId' => $jobId,
                        'songId' => (string) $plan->song_id,
                        'lyricId' => (string) $plan->lyric_id,
                        'libraryId' => (string) $plan->library_id,
                        'planVersion' => $expectedPlanVersion,
                        'replaceExisting' => (int) ($plan->replace_existing ?? 0) === 1,
                    ],
                );
            });
        } catch (QueryException $exception) {
            $replay = $this->findReplay((string) $actor['id'], $keyHash, $planId);
            if ($replay !== null) {
                return $replay;
            }
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new LyricsWritebackConflict('该方案已有任务或幂等键已被占用。', previous: $exception);
            }
            throw $exception;
        }

        return [
            'id' => $jobId,
            'planId' => $planId,
            'status' => 'queued',
            'phase' => 'queued',
            'createdAt' => $now,
        ];
    }

    /**
     * 确认前重建歌词输出与文件预检，证明预览中的 CAS 快照仍成立。
     *
     * 该方法在数据库事务外执行只读文件检查；随后的 Worker 仍会再次校验，因而预检后的竞争只会
     * 让任务安全失败，不会覆盖文件。
     */
    private function assertPlanCurrent(stdClass $plan, array $actor): void
    {
        $source = $this->findScopedSource((string) $plan->song_id, (string) $plan->lyric_id, $actor);
        $content = $this->serializeSource($source);
        if (
            (int) $source->lyric_version !== (int) $plan->expected_lyric_version
            || (int) $source->library_version !== (int) $plan->expected_library_version
            || !hash_equals((string) $plan->lyric_content_sha256, (string) $source->content_sha256)
            || !hash_equals((string) $plan->output_sha256, hash('sha256', $content))
            || strlen($content) !== (int) $plan->output_size_bytes
            || (string) $source->source_kind !== (string) $plan->source_kind
            || (string) $source->source_format !== (string) $plan->source_format
            || (string) $source->language !== (string) $plan->language
            || (string) $source->lyric_kind !== (string) $plan->lyric_kind
            || ($source->match_score === null ? null : (float) $source->match_score)
                !== ($plan->match_score === null ? null : (float) $plan->match_score)
            || (int) $source->device_id !== (int) $plan->source_device
            || (int) $source->inode !== (int) $plan->source_inode
            || (int) $source->file_size !== (int) $plan->source_file_size
            || (int) $source->modified_at !== (int) $plan->source_modified_at
            || (string) $source->license_policy !== (string) $plan->license_policy
        ) {
            throw new LyricsWritebackConflict('歌词、音乐库或音频身份已变化，请重新预览。');
        }
        $target = $this->preflightFilesystem($source, (string) $plan->target_relative_path);
        if ((int) ($plan->replace_existing ?? 0) === 1) {
            $expectedTarget = $this->existingTargetIdentity($plan);
            if ($target['state'] !== 'conflict' || $target['identity'] === null
                || $expectedTarget === null || $target['identity'] !== $expectedTarget) {
                throw new LyricsWritebackConflict('已有歌词身份已变化，请重新创建替换预览。');
            }
        } elseif ($target['state'] !== 'absent') {
            throw new LyricsWritebackConflict('歌词目标已存在，本方案不允许覆盖。');
        }
    }

    /** 读取歌曲、歌词、清单和音乐库的同一授权对象，不允许跨歌曲拼接歌词 ID。 */
    private function findScopedSource(string $songId, string $lyricId, array $actor): stdClass
    {
        $query = Db::table('media_lyrics as lyrics')
            ->join('media_songs as songs', 'songs.id', '=', 'lyrics.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)
            ->where('lyrics.id', $lyricId)
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')
            ->where('libraries.status', 'active')
            ->where('libraries.source_type', 'local');
        $this->scope($query, $actor, 'songs.library_id');
        /** @var stdClass|null $row */
        $row = $query->first([
            'songs.id as song_id', 'songs.title as song_title', 'songs.library_id',
            'songs.inventory_file_id', 'lyrics.id as lyric_id', 'lyrics.source_kind',
            'lyrics.language', 'lyrics.lyric_kind', 'lyrics.content_sha256',
            'lyrics.source_format', 'lyrics.match_score', 'lyrics.license_policy',
            'lyrics.version as lyric_version',
            'files.relative_path', 'files.resolved_path', 'files.device_id', 'files.inode',
            'files.file_size', 'files.modified_at', 'libraries.name as library_name',
            'libraries.resolved_root_path', 'libraries.version as library_version',
        ]);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('歌曲或歌词不存在。');
        }

        return $row;
    }

    /** 读取当前管理范围内的方案；无权限与不存在使用同一结果以防对象枚举。 */
    private function findScopedPlan(string $planId, array $actor): stdClass
    {
        $this->requireUlid($planId, '写回方案标识无效。');
        $query = Db::table('lyrics_writeback_plans as plans')
            ->join('media_songs as songs', 'songs.id', '=', 'plans.song_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'plans.library_id')
            ->where('plans.id', $planId);
        $this->scope($query, $actor, 'plans.library_id');
        /** @var stdClass|null $row */
        $row = $query->first([
            'plans.*', 'songs.title as song_title', 'libraries.name as library_name',
        ]);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('歌词写回方案不存在。');
        }

        return $row;
    }

    /**
     * 读取一个当前身份仍可完整访问的批量方案。
     *
     * 批次可能跨库；普通管理员只要失去其中任一库的 read/manage grant，整个批次即按不存在处理，
     * 而不是返回部分目标和失真的计数。超级管理员仍由 Controller 的全局能力约束。
     */
    private function findScopedBatch(string $batchId, array $actor): stdClass
    {
        $this->requireUlid($batchId, '批量写回方案标识无效。');
        $query = Db::table('lyrics_writeback_batch_plans as batches')->where('batches.id', $batchId);
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && is_string($library['id'] ?? null)
                    && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)) {
                    $ids[] = $library['id'];
                }
            }
            $ids = array_values(array_unique($ids));
            $query->whereNotExists(function (mixed $targets) use ($ids): void {
                $targets->selectRaw('1')->from('lyrics_writeback_batch_targets as scope_targets')
                    ->whereColumn('scope_targets.batch_plan_id', 'batches.id')
                    ->whereNotIn('scope_targets.library_id', $ids ?: ['']);
            });
        }
        /** @var stdClass|null $row */
        $row = $query->first(['batches.*']);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('批量歌词写回方案不存在。');
        }

        return $row;
    }

    /**
     * 按父方案和冻结目标状态读取原始单曲方案，供确认前 CAS 复验。
     *
     * 返回字段来自服务端创建的事实表，不接受浏览器覆盖；调用者仍必须逐项执行 `assertPlanCurrent()`，
     * 因为数据库快照不能证明当前文件系统身份和目标占用状态。
     *
     * @return list<stdClass>
     */
    private function findBatchPlans(string $batchId, string $targetStatus): array
    {
        return Db::table('lyrics_writeback_batch_targets as targets')
            ->join('lyrics_writeback_plans as plans', 'plans.id', '=', 'targets.writeback_plan_id')
            ->where('targets.batch_plan_id', $batchId)->where('targets.status', $targetStatus)
            ->orderBy('targets.position')->get(['plans.*'])->all();
    }

    /** 从受控文件即时读取并确定性序列化正文；异常消息固定，不能把内容回显给 API 或日志。 */
    private function serializeSource(stdClass $source): string
    {
        try {
            $parsed = $this->files->readById((string) $source->lyric_id);
        } catch (LyricsFileUnavailable $exception) {
            throw new LyricsWritebackInvalid('歌词文件不可用，请重新扫描或刮削。', previous: $exception);
        }
        if ($parsed->kind !== (string) $source->lyric_kind) {
            throw new LyricsWritebackInvalid('歌词文件解析类型与索引不一致。');
        }
        return $this->serializer->serialize($parsed->kind, $parsed->lines);
    }

    /** 从音频清单相对路径生成同目录、同基名且可选语言后缀的目标。 */
    private function targetRelativePath(string $audioRelativePath, string $language): string
    {
        $normalized = str_replace('\\', '/', $audioRelativePath);
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new LyricsWritebackInvalid('媒体清单路径无效。');
            }
        }
        if ($language !== 'und' && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            throw new LyricsWritebackInvalid('歌词语言标识不能用于文件名。');
        }
        $filename = array_pop($segments);
        $stem = pathinfo((string) $filename, PATHINFO_FILENAME);
        if ($stem === '') {
            throw new LyricsWritebackInvalid('媒体清单文件名无效。');
        }
        $target = $stem . ($language === 'und' ? '' : '.' . $language) . '.lrc';
        $segments[] = $target;

        return implode('/', $segments);
    }

    /**
     * 在预览/确认时验证真实根、字面音频路径、四元身份和目标状态。
     *
     * 返回 `conflict` 只代表目标已占用；其他路径或身份问题直接拒绝方案，避免创建看似可执行但
     * 已越过音乐库边界的预览。
     */
    private function preflightFilesystem(stdClass $source, string $targetRelativePath): array
    {
        $configuredRoot = rtrim((string) $source->resolved_root_path, DIRECTORY_SEPARATOR);
        $root = realpath($configuredRoot);
        if ($root === false || $root !== $configuredRoot) {
            throw new LyricsWritebackConflict('音乐库根目录已变化，请先重新扫描。');
        }
        $audio = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $source->relative_path);
        if (
            realpath($audio) !== $audio || (string) $source->resolved_path !== $audio
            || is_link($audio) || !is_file($audio)
        ) {
            throw new LyricsWritebackConflict('音频路径或类型已变化，请先重新扫描。');
        }
        $stat = @stat($audio);
        if (
            !is_array($stat)
            || (int) ($stat['dev'] ?? -1) !== (int) $source->device_id
            || (int) ($stat['ino'] ?? -1) !== (int) $source->inode
            || (int) ($stat['size'] ?? -1) !== (int) $source->file_size
            || (int) ($stat['mtime'] ?? -1) !== (int) $source->modified_at
        ) {
            throw new LyricsWritebackConflict('音频文件身份已变化，请先重新扫描。');
        }
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetRelativePath);
        if (dirname($target) !== dirname($audio)) {
            throw new LyricsWritebackInvalid('歌词目标不在音频同目录。');
        }

        if (!file_exists($target) && !is_link($target)) {
            return ['state' => 'absent', 'identity' => null];
        }
        $targetStat = @lstat($target);
        if (!is_array($targetStat) || is_link($target) || !is_file($target)
            || realpath($target) !== $target || (int) ($targetStat['size'] ?? -1) > 1_048_576) {
            return ['state' => 'conflict', 'identity' => null];
        }
        $targetHash = @hash_file('sha256', $target);
        if (!is_string($targetHash)) {
            return ['state' => 'conflict', 'identity' => null];
        }

        return ['state' => 'conflict', 'identity' => [
            'device' => (int) $targetStat['dev'],
            'inode' => (int) $targetStat['ino'],
            'size' => (int) $targetStat['size'],
            'modifiedAt' => (int) $targetStat['mtime'],
            'sha256' => $targetHash,
        ]];
    }

    /** 从持久方案重建旧目标身份；任一字段缺失都按不可替换处理。 */
    private function existingTargetIdentity(stdClass $plan): ?array
    {
        if (
            $plan->existing_target_device === null || $plan->existing_target_inode === null
            || $plan->existing_target_size === null || $plan->existing_target_modified_at === null
            || $plan->existing_target_sha256 === null
        ) {
            return null;
        }

        return [
            'device' => (int) $plan->existing_target_device,
            'inode' => (int) $plan->existing_target_inode,
            'size' => (int) $plan->existing_target_size,
            'modifiedAt' => (int) $plan->existing_target_modified_at,
            'sha256' => (string) $plan->existing_target_sha256,
        ];
    }

    /**
     * 应用实时音乐库授权，不信任浏览器或方案记录中的音乐库 ID。
     *
     * `edit_metadata` 是全局动作能力，库 grant 是对象可见范围；两者由 Controller 和本方法共同构成
     * 授权。只读 grant 仍可配合专门的 edit capability 修改元数据，避免把库配置管理能力误当作
     * 媒体编辑前提。超级管理员保留全库对象范围。
     */
    private function scope(mixed $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) {
            return;
        }
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (
                is_array($library) && is_string($library['id'] ?? null)
                && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)
            ) {
                $ids[] = $library['id'];
            }
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /** 返回幂等重放任务；同一键绑定另一方案时拒绝，避免客户端误关联。 */
    private function findReplay(string $actorId, string $keyHash, string $planId): ?array
    {
        /** @var stdClass|null $row */
        $row = Db::table('lyrics_writeback_jobs')->where('requested_by', $actorId)
            ->where('idempotency_key_sha256', $keyHash)->first([
                'id', 'plan_id', 'status', 'phase', 'created_at',
            ]);
        if (!$row instanceof stdClass) {
            return null;
        }
        if ((string) $row->plan_id !== $planId) {
            throw new LyricsWritebackConflict('该幂等键已用于另一写回方案。');
        }

        return [
            'id' => (string) $row->id,
            'planId' => (string) $row->plan_id,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'createdAt' => (string) $row->created_at,
        ];
    }

    /** 生成不含物理路径、文件身份、正文摘要和内部错误详情的管理投影。 */
    private function mapPlan(stdClass $plan, ?stdClass $job): array
    {
        return [
            'id' => (string) $plan->id,
            'song' => ['id' => (string) $plan->song_id, 'title' => (string) $plan->song_title],
            'lyricId' => (string) $plan->lyric_id,
            'lyricVersion' => (int) $plan->expected_lyric_version,
            'library' => ['id' => (string) $plan->library_id, 'name' => (string) $plan->library_name],
            'source' => (string) $plan->source_kind,
            'format' => (string) $plan->source_format,
            'language' => (string) $plan->language,
            'kind' => (string) $plan->lyric_kind,
            'matchScore' => $plan->match_score === null ? null : (float) $plan->match_score,
            'licensePolicy' => (string) $plan->license_policy,
            'targetPreview' => (string) $plan->target_relative_path,
            'targetState' => (string) $plan->target_state,
            'replaceExisting' => (int) ($plan->replace_existing ?? 0) === 1,
            'replacementAvailable' => (string) $plan->target_state === 'conflict'
                && $plan->existing_target_sha256 !== null,
            'outputSizeBytes' => (int) $plan->output_size_bytes,
            'status' => (string) $plan->status,
            'planHash' => (string) $plan->plan_hash,
            'version' => (int) $plan->version,
            'expiresAt' => (string) $plan->expires_at,
            'confirmation' => (int) ($plan->replace_existing ?? 0) === 1
                ? self::REPLACEMENT_CONFIRMATION_TEXT
                : self::CONFIRMATION_TEXT,
            'confirmable' => (string) $plan->status === 'planned'
                && ((string) $plan->target_state === 'absent' || (int) ($plan->replace_existing ?? 0) === 1)
                && (string) $plan->expires_at > gmdate('Y-m-d\TH:i:s\Z'),
            'job' => $job === null ? null : [
                'id' => (string) $job->id,
                'status' => (string) $job->status,
                'phase' => (string) $job->phase,
                'attempt' => (int) $job->attempt,
                'errorCode' => $job->error_code === null ? null : (string) $job->error_code,
                'createdAt' => (string) $job->created_at,
                'startedAt' => $job->started_at === null ? null : (string) $job->started_at,
                'finishedAt' => $job->finished_at === null ? null : (string) $job->finished_at,
            ],
            'createdAt' => (string) $plan->created_at,
            'finishedAt' => $plan->finished_at === null ? null : (string) $plan->finished_at,
        ];
    }

    /**
     * 生成批量预览与结果投影。
     *
     * 相对目标名与单曲预览一致，可用于人工核对；绝对根、音频身份、内容摘要、幂等摘要和歌词正文
     * 始终排除。逐项错误只返回 Worker 的稳定错误码，不能回显异常消息。
     *
     * @param list<stdClass> $targets 已按冻结 position 排序的目标与可选任务连接结果。
     * @return array<string,mixed>
     */
    private function mapBatch(stdClass $batch, array $targets): array
    {
        return [
            'id' => (string) $batch->id,
            'status' => (string) $batch->status,
            'version' => (int) $batch->version,
            'planHash' => (string) $batch->plan_hash,
            'targetCount' => (int) $batch->target_count,
            'confirmableCount' => (int) $batch->confirmable_count,
            'conflictCount' => (int) $batch->conflict_count,
            'replacementCount' => (int) ($batch->replacement_count ?? 0),
            'processedCount' => (int) $batch->processed_count,
            'succeededCount' => (int) $batch->succeeded_count,
            'failedCount' => (int) $batch->failed_count,
            'expiresAt' => (string) $batch->expires_at,
            'confirmation' => (int) ($batch->replacement_count ?? 0) > 0
                ? self::REPLACEMENT_CONFIRMATION_TEXT
                : self::CONFIRMATION_TEXT,
            'confirmable' => (string) $batch->status === 'draft'
                && (int) $batch->confirmable_count > 0
                && (string) $batch->expires_at > gmdate('Y-m-d\TH:i:s\Z'),
            'createdAt' => (string) $batch->created_at,
            'confirmedAt' => $batch->confirmed_at === null ? null : (string) $batch->confirmed_at,
            'startedAt' => $batch->started_at === null ? null : (string) $batch->started_at,
            'finishedAt' => $batch->finished_at === null ? null : (string) $batch->finished_at,
            'targets' => array_map(static fn (stdClass $target): array => [
                'position' => (int) $target->position,
                'writebackPlanId' => (string) $target->writeback_plan_id,
                'lyricId' => (string) $target->lyric_id,
                'lyricVersion' => (int) $target->expected_lyric_version,
                'song' => ['id' => (string) $target->song_id, 'title' => (string) $target->song_title],
                'library' => ['id' => (string) $target->library_id, 'name' => (string) $target->library_name],
                'source' => (string) $target->source_kind,
                'format' => (string) $target->source_format,
                'language' => (string) $target->language,
                'kind' => (string) $target->lyric_kind,
                'targetPreview' => (string) $target->target_relative_path,
                'targetState' => (string) $target->target_state,
                'replaceExisting' => (int) ($target->replace_existing ?? 0) === 1,
                'replacementAvailable' => (string) $target->target_state === 'conflict'
                    && $target->existing_target_sha256 !== null,
                'outputSizeBytes' => (int) $target->output_size_bytes,
                'status' => (string) $target->target_status,
                'errorCode' => $target->target_error_code === null ? null : (string) $target->target_error_code,
                'jobId' => $target->job_id === null ? null : (string) $target->job_id,
                'jobStatus' => $target->job_status === null ? null : (string) $target->job_status,
            ], $targets),
        ];
    }

    /** 单曲批次目标可执行的统一判断：独占新建或已冻结旧文件身份的显式替换。 */
    private static function preparedConfirmable(array $prepared): bool
    {
        return $prepared['targetState'] === 'absent' || $prepared['replaceExisting'] === true;
    }

    /** 在任何查询前拒绝畸形 ID，防止遗漏 where 条件造成越权枚举。 */
    private function requireUlid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new LyricsWritebackInvalid($message);
        }
    }
}
