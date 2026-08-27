<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 创建、读取、确认和重试不可变批量元数据方案。
 *
 * 创建时冻结明确歌曲 ID 与涉及字段版本，并生成最多五个差异样本。确认只把同一摘要的 draft 排入
 * Durable Worker，不在 HTTP 请求内执行字段循环。方案和目标均受当前 actor 全局能力之外的 manage 库
 * 范围约束；响应不返回路径、标签原始 JSON、歌词正文、文件身份或用户 ID。
 */
final class MetadataBatchService
{
    private const MAX_TARGETS = 1000;

    public function __construct(
        private readonly MetadataFieldSchema $schema = new MetadataFieldSchema(),
        private readonly MetadataFieldStateRepository $states = new MetadataFieldStateRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 创建 24 小时不可变预览。
     *
     * @param array<string,mixed> $filter `matching` 使用当前目录筛选，`selected` 使用显式歌曲 ID 集合。
     * @param list<array<string,mixed>> $operations 设置、追加、移除或清空操作。
     * @return array<string,mixed>
     */
    public function create(array $actor, array $filter, array $operations, string $requestId): array
    {
        $filter = $this->normalizeFilter($filter);
        $operations = $this->normalizeOperations($operations);
        $libraries = $this->managedLibraries($actor);
        if ($libraries === []) throw new MediaMetadataNotFound('没有可管理的音乐库。');
        if (($filter['libraryId'] ?? null) !== null && !isset($libraries[$filter['libraryId']])) {
            throw new MediaMetadataInvalid('音乐库筛选无效。');
        }
        $query = $this->targetQuery(array_keys($libraries), $filter);
        $count = (clone $query)->count('songs.id');
        if ($count < 1) throw new MediaMetadataInvalid('筛选没有匹配媒体。');
        if ($count > self::MAX_TARGETS) throw new MediaMetadataInvalid('单个批量方案最多处理 1000 首歌曲。');
        /** @var list<stdClass> $targets */
        $targets = $query->orderBy('songs.id')->get([
            'songs.id', 'songs.library_id', 'songs.title', 'songs.updated_at',
            'albums.title as album_title', 'libraries.name as library_name',
        ])->all();

        return Db::transaction(function () use ($actor, $count, $filter, $operations, $requestId, $targets): array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $planId = (string) new Ulid();
            $samples = [];
            $targetRows = [];
            $digestFacts = [];
            $operationFields = array_values(array_unique(array_column($operations, 'field')));
            foreach ($targets as $target) {
                $snapshot = $this->states->snapshotSong((string) $target->id);
                $versions = ['_songUpdatedAt' => $snapshot['songUpdatedAt']];
                foreach ($operationFields as $field) $versions[$field] = $snapshot['fields'][$field]['version'];
                $versionJson = $this->encode($versions);
                $targetRows[] = [
                    'plan_id' => $planId, 'song_id' => (string) $target->id,
                    'library_id' => (string) $target->library_id, 'field_versions_json' => $versionJson,
                    'status' => 'pending', 'attempt' => 0, 'error_code' => null, 'change_set_id' => null,
                    'started_at' => null, 'finished_at' => null, 'updated_at' => $now,
                ];
                $digestFacts[] = (string) $target->id . ':' . hash('sha256', $versionJson);
                if (count($samples) < 5) {
                    $samples[] = [
                        'songId' => (string) $target->id, 'title' => (string) $target->title,
                        'album' => (string) $target->album_title, 'library' => (string) $target->library_name,
                        'changes' => array_map(function (array $operation) use ($snapshot): array {
                            $before = $snapshot['fields'][$operation['field']]['effective'];
                            return ['field' => $operation['field'], 'operation' => $operation['operation'],
                                'before' => $before, 'after' => $this->simulate($operation, $before)];
                        }, $operations),
                    ];
                }
            }
            $filterJson = $this->encode($filter);
            $operationsJson = $this->encode($operations);
            $snapshotSha = hash('sha256', $filterJson . "\n" . $operationsJson . "\n" . implode("\n", $digestFacts));
            Db::table('metadata_batch_plans')->insert([
                'id' => $planId, 'requested_by' => (string) $actor['id'], 'request_id' => $requestId,
                'filter_json' => $filterJson, 'operations_json' => $operationsJson,
                'snapshot_sha256' => $snapshotSha, 'sample_json' => $this->encode($samples),
                'target_count' => $count, 'processed_count' => 0, 'succeeded_count' => 0, 'failed_count' => 0,
                'status' => 'draft', 'version' => 1, 'attempt' => 0, 'worker_id' => null, 'heartbeat_at' => null,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400), 'confirmed_at' => null,
                'started_at' => null, 'finished_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_chunk($targetRows, 200) as $chunk) Db::table('metadata_batch_targets')->insert($chunk);
            $this->audit->record((string) $actor['id'], 'metadata.batch.plan.create', 'metadata_batch_plan', $planId, 'success', $requestId, [
                'objectCount' => $count, 'operationCount' => count($operations), 'snapshotSha256Prefix' => substr($snapshotSha, 0, 12),
            ]);
            return $this->mapPlan($this->planRow($planId), $operations, $samples);
        });
    }

    /** 返回仍完全位于 actor manage 范围的方案、进度和有界失败样本。 */
    public function show(array $actor, string $planId): array
    {
        $this->requireUlid($planId);
        $row = $this->visiblePlan($actor, $planId);
        if (!$row instanceof stdClass) throw new MediaMetadataNotFound('方案不存在或不可管理。');
        return $this->mapPlan($row, $this->decodeList((string) $row->operations_json), $this->decodeList((string) $row->sample_json));
    }

    /** 以版本和完整摘要确认同一 draft，重复/过期/漂移方案返回冲突。 */
    public function confirm(array $actor, string $planId, int $version, string $snapshotSha256, string $requestId): array
    {
        $this->requireUlid($planId);
        if ($version < 1 || preg_match('/^[a-f0-9]{64}$/', $snapshotSha256) !== 1) throw new MediaMetadataInvalid('确认版本或摘要无效。');
        return Db::transaction(function () use ($actor, $planId, $requestId, $snapshotSha256, $version): array {
            $row = $this->visiblePlan($actor, $planId);
            if (!$row instanceof stdClass) throw new MediaMetadataNotFound('方案不存在或不可管理。');
            if ((string) $row->status !== 'draft' || (int) $row->version !== $version
                || !hash_equals((string) $row->snapshot_sha256, $snapshotSha256)
                || (string) $row->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
                throw new MediaMetadataConflict('方案已变化或过期。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('metadata_batch_plans')->where('id', $planId)->where('status', 'draft')
                ->where('version', $version)->where('snapshot_sha256', $snapshotSha256)->update([
                    'status' => 'queued', 'version' => Db::raw('version + 1'), 'confirmed_at' => $now, 'updated_at' => $now,
                ]);
            if ($updated !== 1) throw new MediaMetadataConflict('方案已变化。');
            $this->audit->record((string) $actor['id'], 'metadata.batch.plan.confirm', 'metadata_batch_plan', $planId, 'success', $requestId, [
                'objectCount' => (int) $row->target_count, 'operationCount' => count($this->decodeList((string) $row->operations_json)),
            ]);
            return $this->show($actor, $planId);
        });
    }

    /** 从终态方案的失败对象创建新 draft；新方案重新冻结当前字段版本，不重置旧结果。 */
    public function retryFailures(array $actor, string $planId, string $requestId): array
    {
        $row = $this->visiblePlan($actor, $planId);
        if (!$row instanceof stdClass) throw new MediaMetadataNotFound('方案不存在或不可管理。');
        if (!in_array((string) $row->status, ['partial', 'failed'], true)) throw new MediaMetadataConflict('当前方案没有可重试失败项。');
        $songIds = Db::table('metadata_batch_targets')->where('plan_id', $planId)->where('status', 'failed')
            ->orderBy('song_id')->pluck('song_id')->map('strval')->all();
        if ($songIds === []) throw new MediaMetadataConflict('当前方案没有可重试失败项。');
        return $this->create($actor, ['scope' => 'selected', 'songIds' => $songIds, 'libraryId' => null,
            'missingField' => null, 'state' => null, 'q' => null], $this->decodeList((string) $row->operations_json), $requestId);
    }

    /** @return list<array<string,mixed>> */
    private function normalizeOperations(array $operations): array
    {
        if (!array_is_list($operations) || $operations === [] || count($operations) > 17) throw new MediaMetadataInvalid('批量操作数量无效。');
        $result = []; $seen = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || !is_string($operation['field'] ?? null)
                || !is_string($operation['operation'] ?? null) || !is_bool($operation['locked'] ?? null)) {
                throw new MediaMetadataInvalid('批量操作结构无效。');
            }
            $field = $operation['field']; $kind = $operation['operation'];
            if (!in_array($field, MetadataFieldSchema::FIELDS, true) || !in_array($kind, ['set', 'append', 'remove', 'clear'], true)) {
                throw new MediaMetadataInvalid('批量字段或操作无效。');
            }
            if (isset($seen[$field])) throw new MediaMetadataInvalid('同一方案不能重复操作一个字段。');
            $seen[$field] = true;
            if (in_array($kind, ['append', 'remove'], true) && !in_array($field, MetadataFieldSchema::LIST_FIELDS, true)) {
                throw new MediaMetadataInvalid('只有列表字段支持追加或移除。');
            }
            if ($kind === 'clear') {
                if (array_key_exists('value', $operation) && $operation['value'] !== null) throw new MediaMetadataInvalid('清空操作不能携带值。');
                $this->schema->emptyValue($field);
                $value = null;
            } else {
                if (!array_key_exists('value', $operation)) throw new MediaMetadataInvalid('批量操作缺少值。');
                $value = $this->schema->normalize($field, $operation['value']);
                if (in_array($kind, ['append', 'remove'], true) && $value === []) throw new MediaMetadataInvalid('追加或移除值不能为空。');
            }
            $result[] = ['field' => $field, 'operation' => $kind, 'value' => $value, 'locked' => $operation['locked']];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function normalizeFilter(array $filter): array
    {
        $allowed = ['scope', 'songIds', 'libraryId', 'missingField', 'state', 'q'];
        $keys = array_keys($filter); sort($keys); sort($allowed);
        if ($keys !== $allowed || !is_string($filter['scope'] ?? null) || !in_array($filter['scope'], ['matching', 'selected'], true)) {
            throw new MediaMetadataInvalid('批量范围结构无效。');
        }
        foreach (['libraryId', 'missingField', 'state', 'q'] as $key) {
            if ($filter[$key] !== null && !is_string($filter[$key])) throw new MediaMetadataInvalid('批量筛选值无效。');
        }
        if ($filter['libraryId'] !== null) $this->requireUlid($filter['libraryId']);
        if ($filter['missingField'] !== null && !in_array($filter['missingField'], MetadataFieldSchema::FIELDS, true)) throw new MediaMetadataInvalid('缺失字段无效。');
        if ($filter['state'] !== null && !in_array($filter['state'], ['scan_error', 'overridden', 'locked', 'recent'], true)) throw new MediaMetadataInvalid('状态筛选无效。');
        if ($filter['q'] !== null && (mb_strlen($filter['q'], 'UTF-8') < 1 || mb_strlen($filter['q'], 'UTF-8') > 100)) throw new MediaMetadataInvalid('搜索词无效。');
        if (!is_array($filter['songIds']) || !array_is_list($filter['songIds'])) throw new MediaMetadataInvalid('歌曲选择无效。');
        $ids = [];
        foreach ($filter['songIds'] as $id) { if (!is_string($id)) throw new MediaMetadataInvalid('歌曲选择无效。'); $this->requireUlid($id); $ids[$id] = true; }
        if ($filter['scope'] === 'selected' && $ids === []) throw new MediaMetadataInvalid('至少选择一首歌曲。');
        if ($filter['scope'] === 'matching' && $ids !== []) throw new MediaMetadataInvalid('全部匹配范围不能携带歌曲选择。');
        return ['scope' => $filter['scope'], 'songIds' => array_keys($ids), 'libraryId' => $filter['libraryId'],
            'missingField' => $filter['missingField'], 'state' => $filter['state'], 'q' => $filter['q']];
    }

    private function targetQuery(array $libraryIds, array $filter): Builder
    {
        $query = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->whereIn('songs.library_id', $libraryIds ?: [''])->where('files.status', 'available')->where('libraries.status', 'active');
        if ($filter['scope'] === 'selected') $query->whereIn('songs.id', $filter['songIds']);
        if ($filter['libraryId'] !== null) $query->where('songs.library_id', $filter['libraryId']);
        if ($filter['q'] !== null) {
            $pattern = '%' . $this->escapeLike($filter['q']) . '%';
            $query->where(fn (Builder $match) => $match->whereRaw("songs.title LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("albums.title LIKE ? ESCAPE '\\'", [$pattern]));
        }
        if ($filter['state'] === 'scan_error') $query->where('files.metadata_status', 'failed');
        elseif ($filter['state'] === 'overridden') $query->whereExists(fn (Builder $fields) => $fields->selectRaw('1')->from('media_metadata_field_states as s')->whereColumn('s.song_id', 'songs.id')->whereNotNull('s.manual_value_json'));
        elseif ($filter['state'] === 'locked') $query->whereExists(fn (Builder $fields) => $fields->selectRaw('1')->from('media_metadata_field_states as s')->whereColumn('s.song_id', 'songs.id')->where('s.is_locked', 1));
        elseif ($filter['state'] === 'recent') $query->where('songs.updated_at', '>=', gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400));
        if ($filter['missingField'] !== null) $query->where(function (Builder $missing) use ($filter): void {
            $missing->whereNotExists(fn (Builder $fields) => $fields->selectRaw('1')->from('media_metadata_field_states as m')->whereColumn('m.song_id', 'songs.id')->where('m.field_key', $filter['missingField']))
                ->orWhereExists(fn (Builder $fields) => $fields->selectRaw('1')->from('media_metadata_field_states as m')->whereColumn('m.song_id', 'songs.id')->where('m.field_key', $filter['missingField'])->whereIn('m.effective_value_json', ['null', '[]', '""']));
        });
        return $query;
    }

    private function simulate(array $operation, mixed $before): mixed
    {
        if ($operation['operation'] === 'set') return $operation['value'];
        if ($operation['operation'] === 'clear') return $this->schema->emptyValue($operation['field']);
        $current = is_array($before) && array_is_list($before) ? $before : [];
        $change = $operation['value'];
        if ($operation['operation'] === 'append') return $this->schema->normalize($operation['field'], array_merge($current, $change));
        $remove = array_map(static fn (string $item): string => mb_strtolower($item, 'UTF-8'), $change);
        return $this->schema->normalize($operation['field'], array_values(array_filter($current,
            static fn (string $item): bool => !in_array(mb_strtolower($item, 'UTF-8'), $remove, true))));
    }

    private function visiblePlan(array $actor, string $planId): ?stdClass
    {
        $managed = array_keys($this->managedLibraries($actor));
        if ($managed === []) return null;
        /** @var stdClass|null $row */
        $row = Db::table('metadata_batch_plans as plans')->where('plans.id', $planId)
            ->whereNotExists(function (Builder $targets) use ($managed): void {
                $targets->selectRaw('1')->from('metadata_batch_targets as hidden_targets')
                    ->whereColumn('hidden_targets.plan_id', 'plans.id')->whereNotIn('hidden_targets.library_id', $managed);
            })->first(['plans.*']);
        return $row;
    }

    private function planRow(string $id): stdClass
    {
        /** @var stdClass|null $row */ $row = Db::table('metadata_batch_plans')->where('id', $id)->first();
        return $row ?? throw new MediaMetadataNotFound('方案不存在。');
    }

    /** @return array<string,mixed> */
    private function mapPlan(stdClass $row, array $operations, array $samples): array
    {
        /** @var list<stdClass> $failures */
        $failures = Db::table('metadata_batch_targets as targets')->join('media_songs as songs', 'songs.id', '=', 'targets.song_id')
            ->where('targets.plan_id', (string) $row->id)->where('targets.status', 'failed')
            ->orderBy('targets.song_id')->limit(50)->get(['songs.id', 'songs.title', 'targets.error_code'])->all();
        return [
            'id' => (string) $row->id, 'status' => (string) $row->status, 'version' => (int) $row->version,
            'snapshotSha256' => (string) $row->snapshot_sha256, 'targetCount' => (int) $row->target_count,
            'processedCount' => (int) $row->processed_count, 'succeededCount' => (int) $row->succeeded_count,
            'failedCount' => (int) $row->failed_count, 'operations' => $operations, 'samples' => $samples,
            'failureSamples' => array_map(static fn (stdClass $failure): array => [
                'songId' => (string) $failure->id, 'title' => (string) $failure->title,
                'errorCode' => $failure->error_code === null ? 'METADATA_BATCH_FAILED' : (string) $failure->error_code,
            ], $failures),
            'expiresAt' => (string) $row->expires_at, 'createdAt' => (string) $row->created_at,
            'confirmedAt' => $row->confirmed_at === null ? null : (string) $row->confirmed_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'policy' => ['immutable' => true, 'databaseOnly' => true, 'writesAudioFile' => false,
                'canConfirm' => (string) $row->status === 'draft' && (string) $row->expires_at > gmdate('Y-m-d\TH:i:s\Z'),
                'canRetryFailures' => in_array((string) $row->status, ['partial', 'failed'], true) && (int) $row->failed_count > 0],
        ];
    }

    /** @return array<string,array{id:string,name:string}> */
    private function managedLibraries(array $actor): array
    {
        $result = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) if (is_array($library)
            && ($library['accessLevel'] ?? null) === 'manage' && is_string($library['id'] ?? null) && is_string($library['name'] ?? null)) {
            $result[$library['id']] = ['id' => $library['id'], 'name' => $library['name']];
        }
        return $result;
    }

    private function requireUlid(string $id): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) throw new MediaMetadataInvalid('对象标识无效。');
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string,mixed>> */
    private function decodeList(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !array_is_list($value)) throw new JsonException('批量方案 JSON 无效。');
        return $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
