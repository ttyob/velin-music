<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\infrastructure\Database\SqliteTransientRetry;
use app\infrastructure\Database\SqliteWriteGate;
use app\infrastructure\Audit\AuditLogger;
use support\Db;

/**
 * 分批把当前元数据策略重新应用到历史歌曲、专辑和艺术家字段状态。
 *
 * 本服务只由完成 `manage_system + edit_metadata` 的本机管理入口调用，不自行解析 Session。它不读取媒体、
 * 不调用插件或外部网络、不创建刮削任务，也不改 raw/scraped/manual/locked 快照。开始及每批提交前必须
 * 确认扫描、逐曲刮削、实体补全和批量元数据写入均已空闲，避免两个状态机同时拥有字段版本。处理顺序
 * 固定为艺术家、专辑、歌曲，使共享实体文本先稳定，再恢复歌曲关系；每批最多 100 个对象，在独立
 * SQLite 短事务内同时写投影和脱敏审计。某批失败时该批整体回滚，先前成功批次保留且可幂等重跑。
 */
final readonly class MetadataPolicyReprojectionService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private MetadataScrapePolicyService $policy = new MetadataScrapePolicyService(),
        private MetadataFieldStateRepository $songs = new MetadataFieldStateRepository(),
        private EntityMetadataStateRepository $entities = new EntityMetadataStateRepository(),
        private AuditLogger $audit = new AuditLogger(),
        private SqliteTransientRetry $sqliteRetry = new SqliteTransientRetry(),
        private SqliteWriteGate $sqliteWriteGate = new SqliteWriteGate(),
    ) {}

    /**
     * 对所有已有字段状态执行一次受控重投影。
     *
     * actorUserId 和 requestId 必须来自已授权 CLI 请求；方法不会为无状态对象凭空建立来源。对象按 ULID
     * 游标分页，新增对象只会在当前游标之后被本次命中或留给下次幂等执行，不会重复处理已完成页。每批的
     * 字段变化数与对象数进入审计，但不记录媒体名称、字段值、路径或插件数据。返回值仅用于管理终端摘要。
     *
     * @return array{artists:int,albums:int,songs:int,changedFields:int,batches:int}
     */
    public function reprojectAll(string $actorUserId, string $requestId): array
    {
        // 维护命令不能使用 Worker 的保守缺省策略；先冻结正式版本，批次之间发生配置变化时失败并要求重跑。
        $policyVersion = $this->policy->get()['version'];
        $this->assertIdle();
        $summary = ['artists' => 0, 'albums' => 0, 'songs' => 0, 'changedFields' => 0, 'batches' => 0];
        foreach ([
            ['artist', 'media_artist_metadata_field_states', 'artist_id', 'artists'],
            ['album', 'media_album_metadata_field_states', 'album_id', 'albums'],
            ['song', 'media_metadata_field_states', 'song_id', 'songs'],
        ] as [$type, $table, $idColumn, $summaryKey]) {
            if (!Db::connection()->getSchemaBuilder()->hasTable($table)) continue;
            $lastId = '';
            while (true) {
                $ids = Db::table($table)->select($idColumn)->distinct()->where($idColumn, '>', $lastId)
                    ->orderBy($idColumn)->limit(self::BATCH_SIZE)->pluck($idColumn)
                    ->map('strval')->all();
                if ($ids === []) break;
                $now = gmdate('Y-m-d\TH:i:s\Z');
                // 单批可完整幂等重放；写闸门协调本部署 Worker，结构化 BUSY/LOCKED 退避吸收非协作短写者。
                $changedFields = $this->sqliteRetry->run(fn (): int => $this->sqliteWriteGate->run(
                    fn (): int => Db::transaction(function () use (
                        $actorUserId, $ids, $now, $policyVersion, $requestId, $type,
                    ): int {
                        $this->assertIdle();
                        if ($this->policy->get()['version'] !== $policyVersion) {
                            throw new MetadataScrapePolicyConflict('元数据策略在重投影期间发生变化，请重新执行。');
                        }
                        $changed = 0;
                        foreach ($ids as $id) {
                            $changed += $type === 'song'
                                ? $this->songs->reprojectAutomaticValues($id, $now)
                                : $this->entities->reprojectAutomaticValues($type, $id, $now);
                        }
                        $this->audit->record(
                            $actorUserId,
                            'metadata.scrape_policy.reproject.batch',
                            'system_setting',
                            'metadata.scrape_policy',
                            'success',
                            $requestId,
                            [
                                'entity_type' => $type,
                                'object_count' => count($ids),
                                'changed_field_count' => $changed,
                                'policy_version' => $policyVersion,
                            ],
                        );
                        return $changed;
                    }),
                ));
                $summary[$summaryKey] += count($ids);
                $summary['changedFields'] += $changedFields;
                ++$summary['batches'];
                $lastId = $ids[array_key_last($ids)];
            }
        }
        if ($this->policy->get()['version'] !== $policyVersion) {
            throw new MetadataScrapePolicyConflict('元数据策略在重投影期间发生变化，请重新执行。');
        }
        return $summary;
    }

    /**
     * 拒绝与任何可能改写同一目录或字段状态的活动任务并发。
     *
     * 查询只判断固定表和固定状态，不领取、取消或修改任务；滚动升级缺少某张表时跳过该类任务。检查在
     * 首批之前和每个短事务内部重复执行：已有任务必须先自然收口，新任务若在批次间创建会使下一批失败，
     * 已完成批次保留并可在空闲后幂等重跑。新任务在某批提交后才开始时会读取最新字段版本和当前策略，
     * 不会与该批并发持有 SQLite 写事务。
     */
    private function assertIdle(): void
    {
        foreach ([
            ['library_scan_jobs', ['queued', 'running', 'cancel_requested']],
            ['metadata_sync_scrape_targets', ['pending', 'running']],
            ['metadata_batch_plans', ['queued', 'running']],
            ['album_scrape_jobs', ['queued', 'running']],
            ['metadata_entity_scrape_intents', ['queued', 'running']],
        ] as [$table, $statuses]) {
            if (Db::connection()->getSchemaBuilder()->hasTable($table)
                && Db::table($table)->whereIn('status', $statuses)->exists()) {
                throw new MetadataPolicyReprojectionBusy('存在活动元数据任务，请等待任务完成后重新执行。');
            }
        }
    }
}

/** 活动任务仍可能改写目录投影，维护命令必须等待其自然收口。 */
final class MetadataPolicyReprojectionBusy extends \RuntimeException {}
