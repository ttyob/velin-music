<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Auth\CapabilityResolver;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 领取并逐对象执行不可变批量元数据方案。
 *
 * Worker 不访问媒体文件或外部来源。每个歌曲使用独立短事务，先复验请求账号、实时 capability、manage
 * 库授权、可用媒体和冻结字段版本，再提交覆盖、目录物化、变更明细和目标状态。一个对象失败不会回滚
 * 已成功对象；终态用 succeeded/partial/failed 明确反映结果，不把部分失败伪装成成功。
 */
final class MetadataBatchWorkerService
{
    private const LEASE_SECONDS = 60;

    public function __construct(
        private readonly MetadataFieldSchema $schema = new MetadataFieldSchema(),
        private readonly MetadataFieldStateRepository $states = new MetadataFieldStateRepository(),
        private readonly CapabilityResolver $capabilities = new CapabilityResolver(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** 恢复崩溃租约；最多三次，耗尽后把所有未完成目标记为稳定失败。 */
    public function recoverStaleLeases(): void
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::LEASE_SECONDS);
        /** @var list<stdClass> $rows */
        $rows = Db::table('metadata_batch_plans')->where('status', 'running')
            ->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $threshold))
            ->get(['id', 'attempt', 'heartbeat_at'])->all();
        foreach ($rows as $row) Db::transaction(function () use ($row): void {
            $query = Db::table('metadata_batch_plans')->where('id', (string) $row->id)->where('status', 'running');
            $row->heartbeat_at === null ? $query->whereNull('heartbeat_at') : $query->where('heartbeat_at', (string) $row->heartbeat_at);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if ((int) $row->attempt >= 3) {
                if ($query->update(['status' => 'failed', 'worker_id' => null, 'heartbeat_at' => null,
                    'finished_at' => $now, 'updated_at' => $now]) !== 1) return;
                $remaining = Db::table('metadata_batch_targets')->where('plan_id', (string) $row->id)
                    ->whereIn('status', ['pending', 'running'])->update([
                        'status' => 'failed', 'error_code' => 'METADATA_BATCH_LEASE_EXHAUSTED',
                        'finished_at' => $now, 'updated_at' => $now,
                    ]);
                Db::table('metadata_batch_plans')->where('id', (string) $row->id)->update([
                    'processed_count' => Db::raw('processed_count + ' . (int) $remaining),
                    'failed_count' => Db::raw('failed_count + ' . (int) $remaining),
                ]);
                return;
            }
            if ($query->update(['status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null,
                'started_at' => null, 'updated_at' => $now]) === 1) {
                Db::table('metadata_batch_targets')->where('plan_id', (string) $row->id)->where('status', 'running')
                    ->update(['status' => 'pending', 'error_code' => null, 'started_at' => null, 'updated_at' => $now]);
            }
        });
    }

    /** @return array<string,mixed>|null 条件更新获胜时返回方案不可变执行输入。 */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            /** @var stdClass|null $row */
            $row = Db::table('metadata_batch_plans')->where('status', 'queued')->orderBy('created_at')->orderBy('id')
                ->first(['id', 'requested_by', 'request_id', 'operations_json', 'target_count']);
            if (!$row instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if (Db::table('metadata_batch_plans')->where('id', (string) $row->id)->where('status', 'queued')->update([
                'status' => 'running', 'attempt' => Db::raw('attempt + 1'), 'worker_id' => $workerId,
                'heartbeat_at' => $now, 'started_at' => $now, 'updated_at' => $now,
            ]) !== 1) return null;
            return ['id' => (string) $row->id,
                'requestedBy' => $row->requested_by === null ? null : (string) $row->requested_by,
                'requestId' => (string) $row->request_id, 'operationsJson' => (string) $row->operations_json,
                'targetCount' => (int) $row->target_count];
        });
    }

    /** 执行全部 pending 目标并写入真实终态；停止信号只在对象边界释放租约。 */
    public function execute(array $plan, callable $isStopping): void
    {
        $planId = (string) $plan['id'];
        try {
            $operations = $this->decodeOperations((string) $plan['operationsJson']);
            while (true) {
                if ($isStopping()) { $this->release($planId); return; }
                /** @var stdClass|null $target */
                $target = Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('status', 'pending')
                    ->orderBy('song_id')->first(['song_id', 'library_id', 'field_versions_json']);
                if (!$target instanceof stdClass) break;
                try {
                    $this->executeTarget($plan, $target, $operations);
                } catch (Throwable $throwable) {
                    $this->failTarget($planId, (string) $target->song_id, $this->errorCode($throwable));
                }
                Db::table('metadata_batch_plans')->where('id', $planId)->where('status', 'running')
                    ->update(['heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            }
            $this->finish($plan);
        } catch (Throwable) {
            // 方案级 JSON/数据库失败不能伪装为部分完成；剩余目标保留给租约恢复，当前计划明确失败。
            $this->finishInfrastructureFailure($plan);
        }
    }

    /** @param list<array<string,mixed>> $operations */
    private function executeTarget(array $plan, stdClass $target, array $operations): void
    {
        Db::transaction(function () use ($operations, $plan, $target): void {
            $planId = (string) $plan['id']; $songId = (string) $target->song_id;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $claimed = Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('song_id', $songId)
                ->where('status', 'pending')->update(['status' => 'running', 'attempt' => Db::raw('attempt + 1'),
                    'started_at' => $now, 'updated_at' => $now]);
            if ($claimed !== 1) throw new MediaMetadataConflict('目标状态已变化。');
            $actor = $this->workerActor($plan['requestedBy']);
            if ($actor === null || !isset($actor['libraries'][(string) $target->library_id])) throw new MediaMetadataNotFound('账号或管理授权已失效。');
            $visible = Db::table('media_songs as songs')->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
                ->where('songs.id', $songId)->where('songs.library_id', (string) $target->library_id)
                ->where('files.status', 'available')->where('libraries.status', 'active')->exists();
            if (!$visible) throw new MediaMetadataNotFound('媒体已不可用。');

            $expected = $this->decodeMap((string) $target->field_versions_json);
            $snapshot = $this->states->snapshotSong($songId);
            foreach ($operations as $operation) {
                $field = $operation['field'];
                if (!array_key_exists($field, $expected) || !is_int($expected[$field])) throw new JsonException('目标版本快照无效。');
                $currentVersion = $snapshot['fields'][$field]['version'];
                if ($currentVersion !== $expected[$field]) throw new MediaMetadataConflict('字段版本已变化。');
                if ($currentVersion === 0 && ($expected['_songUpdatedAt'] ?? null) !== $snapshot['songUpdatedAt']) {
                    throw new MediaMetadataConflict('歌曲来源已变化。');
                }
            }
            $states = $this->states->ensureSong($songId, $now);
            $changeSetId = (string) new Ulid();
            Db::table('metadata_change_sets')->insert([
                'id' => $changeSetId, 'actor_user_id' => $plan['requestedBy'], 'command_type' => 'batch',
                'source_kind' => 'worker', 'object_count' => 1, 'changed_field_count' => count($operations),
                'task_id' => $planId, 'status' => 'running', 'request_id' => (string) $plan['requestId'],
                'created_at' => $now, 'finished_at' => null,
            ]);
            foreach ($operations as $operation) {
                $before = $states[$operation['field']];
                $after = $this->applyOperation($operation, $before['effective']);
                $updated = Db::table('media_metadata_field_states')->where('song_id', $songId)
                    ->where('field_key', $operation['field'])->where('version', $before['version'])->update([
                        'manual_value_json' => $this->encode($after), 'effective_value_json' => $this->encode($after),
                        'effective_source' => 'manual', 'is_locked' => $operation['locked'] ? 1 : 0,
                        'version' => Db::raw('version + 1'), 'manual_updated_at' => $now,
                        'updated_by' => $plan['requestedBy'], 'updated_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaMetadataConflict('字段版本已变化。');
                Db::table('metadata_change_items')->insert([
                    'id' => (string) new Ulid(), 'change_set_id' => $changeSetId, 'object_type' => 'song',
                    'object_id' => $songId, 'field_key' => $operation['field'], 'operation' => $operation['operation'],
                    'before_value_json' => $this->encode($before['effective']), 'after_value_json' => $this->encode($after),
                    'source_before' => $before['source'], 'source_after' => 'manual', 'result' => 'succeeded',
                    'error_code' => null, 'created_at' => $now,
                ]);
                $states[$operation['field']]['effective'] = $after;
                $states[$operation['field']]['source'] = 'manual';
                ++$states[$operation['field']]['version'];
            }
            $this->states->materialize($songId);
            Db::table('metadata_change_sets')->where('id', $changeSetId)->update(['status' => 'succeeded', 'finished_at' => $now]);
            Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('song_id', $songId)->where('status', 'running')
                ->update(['status' => 'succeeded', 'error_code' => null, 'change_set_id' => $changeSetId,
                    'finished_at' => $now, 'updated_at' => $now]);
            Db::table('metadata_batch_plans')->where('id', $planId)->where('status', 'running')->update([
                'processed_count' => Db::raw('processed_count + 1'), 'succeeded_count' => Db::raw('succeeded_count + 1'),
                'heartbeat_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    private function failTarget(string $planId, string $songId, string $errorCode): void
    {
        Db::transaction(function () use ($errorCode, $planId, $songId): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('song_id', $songId)
                ->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error_code' => $errorCode,
                    'finished_at' => $now, 'updated_at' => $now]);
            if ($updated === 1) Db::table('metadata_batch_plans')->where('id', $planId)->where('status', 'running')->update([
                'processed_count' => Db::raw('processed_count + 1'), 'failed_count' => Db::raw('failed_count + 1'),
                'heartbeat_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    private function finish(array $plan): void
    {
        Db::transaction(function () use ($plan): void {
            /** @var stdClass|null $row */
            $row = Db::table('metadata_batch_plans')->where('id', (string) $plan['id'])->where('status', 'running')
                ->first(['succeeded_count', 'failed_count']);
            if (!$row instanceof stdClass) return;
            $status = (int) $row->failed_count === 0 ? 'succeeded' : ((int) $row->succeeded_count > 0 ? 'partial' : 'failed');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('metadata_batch_plans')->where('id', (string) $plan['id'])->where('status', 'running')->update([
                'status' => $status, 'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record($plan['requestedBy'], 'metadata.batch.complete', 'metadata_batch_plan', (string) $plan['id'],
                $status === 'succeeded' ? 'success' : 'failed', (string) $plan['requestId'], [
                    'objectCount' => (int) $plan['targetCount'], 'succeededCount' => (int) $row->succeeded_count,
                    'failedCount' => (int) $row->failed_count, 'status' => $status,
                ]);
        });
    }

    private function release(string $planId): void
    {
        Db::transaction(function () use ($planId): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            if (Db::table('metadata_batch_plans')->where('id', $planId)->where('status', 'running')->update([
                'status' => 'queued', 'worker_id' => null, 'heartbeat_at' => null, 'updated_at' => $now,
            ]) === 1) Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('status', 'running')
                ->update(['status' => 'pending', 'started_at' => null, 'updated_at' => $now]);
        });
    }

    private function finishInfrastructureFailure(array $plan): void
    {
        Db::transaction(function () use ($plan): void {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $remaining = Db::table('metadata_batch_targets')->where('plan_id', (string) $plan['id'])
                ->whereIn('status', ['pending', 'running'])->update([
                    'status' => 'failed', 'error_code' => 'METADATA_BATCH_INTERNAL_FAILED',
                    'finished_at' => $now, 'updated_at' => $now,
                ]);
            Db::table('metadata_batch_plans')->where('id', (string) $plan['id'])->where('status', 'running')->update([
                'status' => 'failed', 'processed_count' => Db::raw('processed_count + ' . (int) $remaining),
                'failed_count' => Db::raw('failed_count + ' . (int) $remaining),
                'worker_id' => null, 'heartbeat_at' => null, 'finished_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /** @return array{id:string,libraries:array<string,true>}|null */
    private function workerActor(mixed $userId): ?array
    {
        if (!is_string($userId)) return null;
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)->where('status', 'active')->first(['id', 'is_super_admin']);
        if (!$user instanceof stdClass) return null;
        $super = (int) $user->is_super_admin === 1;
        if (!in_array('edit_metadata', $this->capabilities->resolve($userId, $super), true)) return null;
        $query = Db::table('music_libraries as libraries')->where('libraries.status', 'active');
        if (!$super) $query->join('library_user_grants as grants', 'grants.library_id', '=', 'libraries.id')
            ->where('grants.user_id', $userId)->where('grants.access_level', 'manage');
        $ids = $query->pluck('libraries.id')->map('strval')->all();
        return ['id' => $userId, 'libraries' => array_fill_keys($ids, true)];
    }

    private function applyOperation(array $operation, mixed $before): mixed
    {
        if ($operation['operation'] === 'set') return $this->schema->normalize($operation['field'], $operation['value']);
        if ($operation['operation'] === 'clear') return $this->schema->emptyValue($operation['field']);
        if (!is_array($before) || !array_is_list($before) || !is_array($operation['value'])) throw new MediaMetadataInvalid('列表操作值无效。');
        if ($operation['operation'] === 'append') return $this->schema->normalize($operation['field'], array_merge($before, $operation['value']));
        if ($operation['operation'] !== 'remove') throw new MediaMetadataInvalid('批量操作无效。');
        $remove = array_map(static fn (string $item): string => mb_strtolower($item, 'UTF-8'), $operation['value']);
        return $this->schema->normalize($operation['field'], array_values(array_filter($before,
            static fn (string $item): bool => !in_array(mb_strtolower($item, 'UTF-8'), $remove, true))));
    }

    /** @return list<array<string,mixed>> */
    private function decodeOperations(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !array_is_list($value) || $value === []) throw new JsonException('方案操作无效。');
        foreach ($value as $operation) if (!is_array($operation) || !is_string($operation['field'] ?? null)
            || !is_string($operation['operation'] ?? null) || !is_bool($operation['locked'] ?? null)) {
            throw new JsonException('方案操作无效。');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function decodeMap(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) throw new JsonException('目标版本无效。');
        return $value;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function errorCode(Throwable $throwable): string
    {
        return match (true) {
            $throwable instanceof MediaMetadataConflict => 'METADATA_CHANGED',
            $throwable instanceof MediaMetadataNotFound => 'MEDIA_OR_PERMISSION_UNAVAILABLE',
            $throwable instanceof MediaMetadataInvalid, $throwable instanceof JsonException => 'METADATA_OPERATION_INVALID',
            default => 'METADATA_BATCH_INTERNAL_FAILED',
        };
    }
}
