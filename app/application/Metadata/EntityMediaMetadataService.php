<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 提供艺术家和专辑实体级字段来源对照、保存覆盖与清除回退。
 *
 * Controller 校验全局 `edit_metadata`，本服务仍要求实体实际关联的全部音乐库均为 actor 的实时 manage
 * 范围。艺术家是跨库共享词汇，任一引用库失权会隐藏整个实体；专辑只要求其所属库。命令使用逐字段
 * CAS，并在一个短事务中提交状态、共享目录投影、字段历史和脱敏审计，不访问音频或触发扫描。
 */
final class EntityMediaMetadataService
{
    public function __construct(
        private readonly EntityMetadataFieldSchema $schema = new EntityMetadataFieldSchema(),
        private readonly EntityMetadataStateRepository $states = new EntityMetadataStateRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** 返回重新授权后的实体字段来源、有效投影和最近 50 条不可变字段历史。 */
    public function detail(array $actor, string $type, string $entityId): array
    {
        $row = $this->managedEntity($actor, $type, $entityId);
        if (!$row instanceof stdClass) throw new MediaMetadataNotFound('元数据实体不存在或不可管理。');
        $states = Db::transaction(fn (): array => $this->states->ensure($type, $entityId, gmdate('Y-m-d\TH:i:s\Z')));
        return $this->mapDetail($actor, $type, $row, $states);
    }

    /**
     * 原子保存一个或多个实体字段手工覆盖。
     *
     * 每项必须携带详情返回的正整数 version；任何字段冲突、艺术家同名冲突或关系更新失败会回滚整组
     * 命令和审计。保存只写数据库，音频标签写回仍需独立 Dry Run 方案。
     *
     * @param list<array{field:string,value:mixed,locked:bool,version:int}> $changes
     */
    public function save(array $actor, string $type, string $entityId, array $changes, string $requestId): array
    {
        $normalized = $this->normalizeChanges($type, $changes, false);
        return Db::transaction(function () use ($actor, $type, $entityId, $normalized, $requestId): array {
            $row = $this->managedEntity($actor, $type, $entityId);
            if (!$row instanceof stdClass) throw new MediaMetadataNotFound('元数据实体不存在或不可管理。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $states = $this->states->ensure($type, $entityId, $now);
            [$table, $idColumn] = $this->storage($type);
            $changeSetId = (string) new Ulid();
            $this->insertChangeSet($changeSetId, $actor, count($normalized), $requestId, $now);
            foreach ($normalized as $change) {
                $before = $states[$change['field']] ?? throw new MediaMetadataConflict('实体字段状态不存在。');
                if ($before['version'] !== $change['version']) throw new MediaMetadataConflict('实体字段版本已变化。');
                $updated = Db::table($table)->where($idColumn, $entityId)->where('field_key', $change['field'])
                    ->where('version', $change['version'])->update([
                        'manual_value_json' => $this->encode($change['value']),
                        'effective_value_json' => $this->encode($change['value']), 'effective_source' => 'manual',
                        'is_locked' => $change['locked'] ? 1 : 0, 'version' => Db::raw('version + 1'),
                        'manual_updated_at' => $now, 'updated_by' => (string) $actor['id'], 'updated_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaMetadataConflict('实体字段版本已变化。');
                $this->insertChangeItem($changeSetId, $type, $entityId, $change['field'], 'set',
                    $before, $change['value'], 'manual', $now);
            }
            $this->states->materialize($type, $entityId, $now);
            Db::table('metadata_change_sets')->where('id', $changeSetId)->update([
                'status' => 'succeeded', 'finished_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'metadata.entity.override.save', $type, $entityId,
                'success', $requestId, ['changeSetId' => $changeSetId, 'fieldCount' => count($normalized), 'objectCount' => 1]);
            $fresh = $this->managedEntity($actor, $type, $entityId);
            return $this->mapDetail($actor, $type, $fresh instanceof stdClass ? $fresh : $row,
                $this->states->states($type, $entityId), $changeSetId);
        });
    }

    /**
     * 清除指定实体手工值，并按 raw > scraped 来源回退。
     *
     * `unlock` 明确决定是否解除来源锁；清除不会删除来源事实或历史。任一版本变化会回滚整个命令。
     *
     * @param list<array{field:string,version:int,unlock?:bool}> $changes
     */
    public function clear(array $actor, string $type, string $entityId, array $changes, string $requestId): array
    {
        $normalized = $this->normalizeChanges($type, $changes, true);
        return Db::transaction(function () use ($actor, $type, $entityId, $normalized, $requestId): array {
            $row = $this->managedEntity($actor, $type, $entityId);
            if (!$row instanceof stdClass) throw new MediaMetadataNotFound('元数据实体不存在或不可管理。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $states = $this->states->ensure($type, $entityId, $now);
            [$table, $idColumn] = $this->storage($type);
            $changeSetId = (string) new Ulid();
            $this->insertChangeSet($changeSetId, $actor, count($normalized), $requestId, $now);
            foreach ($normalized as $change) {
                $before = $states[$change['field']] ?? throw new MediaMetadataConflict('实体字段状态不存在。');
                if ($before['version'] !== $change['version']) throw new MediaMetadataConflict('实体字段版本已变化。');
                $rawPresent = $this->valuePresent($before['raw'], $change['field']);
                $source = $rawPresent ? 'raw' : ($before['scraped'] !== null ? 'scraped' : 'raw');
                $after = $source === 'scraped' ? $before['scraped'] : $before['raw'];
                $updated = Db::table($table)->where($idColumn, $entityId)->where('field_key', $change['field'])
                    ->where('version', $change['version'])->update([
                        'manual_value_json' => null, 'effective_value_json' => $this->encode($after),
                        'effective_source' => $source, 'is_locked' => $change['unlock'] ? 0 : ($before['locked'] ? 1 : 0),
                        'version' => Db::raw('version + 1'), 'manual_updated_at' => null,
                        'updated_by' => (string) $actor['id'], 'updated_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaMetadataConflict('实体字段版本已变化。');
                $this->insertChangeItem($changeSetId, $type, $entityId, $change['field'], 'clear',
                    $before, $after, $source, $now);
            }
            $this->states->materialize($type, $entityId, $now);
            Db::table('metadata_change_sets')->where('id', $changeSetId)->update([
                'status' => 'succeeded', 'finished_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'metadata.entity.override.clear', $type, $entityId,
                'success', $requestId, ['changeSetId' => $changeSetId, 'fieldCount' => count($normalized), 'objectCount' => 1]);
            $fresh = $this->managedEntity($actor, $type, $entityId);
            return $this->mapDetail($actor, $type, $fresh instanceof stdClass ? $fresh : $row,
                $this->states->states($type, $entityId), $changeSetId);
        });
    }

    /** @return array<string,mixed> */
    private function mapDetail(array $actor, string $type, stdClass $row, array $states, ?string $changeSetId = null): array
    {
        $ordered = [];
        foreach ($this->schema->fields($type) as $field) if (isset($states[$field])) $ordered[] = $states[$field];
        /** @var list<stdClass> $history */
        $history = Db::table('metadata_change_items as items')->join('metadata_change_sets as sets', 'sets.id', '=', 'items.change_set_id')
            ->leftJoin('users', 'users.id', '=', 'sets.actor_user_id')->where('items.object_type', $type)
            ->where('items.object_id', (string) $row->id)->orderByDesc('items.created_at')->orderByDesc('items.id')
            ->limit(50)->get(['items.id', 'items.change_set_id', 'items.field_key', 'items.operation', 'items.result',
                'items.source_before', 'items.source_after', 'items.created_at', 'sets.task_id', 'users.display_name as actor_name'])->all();
        $libraryIds = $this->entityLibraryIds($type, (string) $row->id);
        $managed = $this->managedLibraries($actor);
        $libraries = array_values(array_filter(array_map(static fn (string $id): ?array => $managed[$id] ?? null, $libraryIds)));
        return [
            'type' => $type, 'id' => (string) $row->id,
            'name' => $type === 'artist' ? (string) $row->name : (string) $row->title,
            'libraries' => $libraries, 'fields' => $ordered,
            'history' => array_map(static fn (stdClass $item): array => [
                'id' => (string) $item->id, 'changeSetId' => (string) $item->change_set_id,
                'field' => (string) $item->field_key, 'operation' => (string) $item->operation,
                'result' => (string) $item->result, 'sourceBefore' => (string) $item->source_before,
                'sourceAfter' => (string) $item->source_after,
                'actor' => $item->actor_name === null ? '已删除账户' : (string) $item->actor_name,
                'taskId' => $item->task_id === null ? null : (string) $item->task_id,
                'createdAt' => (string) $item->created_at,
            ], $history),
            'lastChangeSetId' => $changeSetId,
            'policy' => ['sharedEntity' => true, 'databaseOnly' => true, 'writesAudioFile' => false,
                'writebackRequiresPlan' => true],
        ];
    }

    /** @param list<array<string,mixed>> $changes @return list<array<string,mixed>> */
    private function normalizeChanges(string $type, array $changes, bool $clear): array
    {
        $fields = $this->schema->fields($type);
        if (!array_is_list($changes) || $changes === [] || count($changes) > count($fields)) {
            throw new MediaMetadataInvalid('实体字段变更数量无效。');
        }
        $result = []; $seen = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !is_string($change['field'] ?? null)
                || !is_int($change['version'] ?? null) || $change['version'] < 1) {
                throw new MediaMetadataInvalid('实体字段变更结构无效。');
            }
            $field = $change['field'];
            if (!in_array($field, $fields, true) || isset($seen[$field])) throw new MediaMetadataInvalid('实体字段无效或重复。');
            $seen[$field] = true;
            if ($clear) {
                if (isset($change['unlock']) && !is_bool($change['unlock'])) throw new MediaMetadataInvalid('实体字段解锁值无效。');
                $result[] = ['field' => $field, 'version' => $change['version'], 'unlock' => $change['unlock'] ?? false];
            } else {
                if (!array_key_exists('value', $change) || !is_bool($change['locked'] ?? null)) {
                    throw new MediaMetadataInvalid('实体字段值或锁定状态无效。');
                }
                $result[] = ['field' => $field, 'version' => $change['version'],
                    'value' => $this->schema->normalize($type, $field, $change['value']), 'locked' => $change['locked']];
            }
        }
        return $result;
    }

    /** 返回已完整授权实体；null 合并不存在、非法 ID、空引用与失权，避免对象枚举。 */
    private function managedEntity(array $actor, string $type, string $entityId): ?stdClass
    {
        $this->schema->fields($type);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $entityId) !== 1) return null;
        /** @var stdClass|null $row */
        $row = Db::table($type === 'artist' ? 'media_artists' : 'media_albums')->where('id', $entityId)->first();
        if (!$row instanceof stdClass) return null;
        $required = $this->entityLibraryIds($type, $entityId);
        $managed = array_keys($this->managedLibraries($actor));
        return $required !== [] && array_diff($required, $managed) === [] ? $row : null;
    }

    /** @return list<string> 艺术家覆盖歌曲和专辑署名的全部库；专辑返回唯一所属库。 */
    private function entityLibraryIds(string $type, string $entityId): array
    {
        if ($type === 'album') {
            $library = Db::table('media_albums')->where('id', $entityId)->value('library_id');
            return is_string($library) ? [$library] : [];
        }
        $songs = Db::table('media_song_artists as links')->join('media_songs as songs', 'songs.id', '=', 'links.song_id')
            ->where('links.artist_id', $entityId)->distinct()->pluck('songs.library_id')->map('strval')->all();
        $albums = Db::table('media_album_artists as links')->join('media_albums as albums', 'albums.id', '=', 'links.album_id')
            ->where('links.artist_id', $entityId)->distinct()->pluck('albums.library_id')->map('strval')->all();
        return array_values(array_unique(array_merge($songs, $albums)));
    }

    /** @return array<string,array{id:string,name:string}> */
    private function managedLibraries(array $actor): array
    {
        $result = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                && is_string($library['id'] ?? null) && is_string($library['name'] ?? null)) {
                $result[$library['id']] = ['id' => $library['id'], 'name' => $library['name']];
            }
        }
        return $result;
    }

    private function insertChangeSet(string $id, array $actor, int $fields, string $requestId, string $now): void
    {
        Db::table('metadata_change_sets')->insert([
            'id' => $id, 'actor_user_id' => (string) $actor['id'], 'command_type' => 'single',
            'source_kind' => 'admin', 'object_count' => 1, 'changed_field_count' => $fields,
            'task_id' => null, 'status' => 'running', 'request_id' => $requestId,
            'created_at' => $now, 'finished_at' => null,
        ]);
    }

    /** 记录字段前后有效值；不写路径、标签原文、远端响应或实体关系快照。 */
    private function insertChangeItem(
        string $setId,
        string $type,
        string $entityId,
        string $field,
        string $operation,
        array $before,
        mixed $after,
        string $sourceAfter,
        string $now,
    ): void {
        Db::table('metadata_change_items')->insert([
            'id' => (string) new Ulid(), 'change_set_id' => $setId, 'object_type' => $type,
            'object_id' => $entityId, 'field_key' => $field, 'operation' => $operation,
            'before_value_json' => $this->encode($before['effective']), 'after_value_json' => $this->encode($after),
            'source_before' => $before['source'], 'source_after' => $sourceAfter,
            'result' => 'succeeded', 'error_code' => null, 'created_at' => $now,
        ]);
    }

    /** @return array{0:string,1:string} */
    private function storage(string $type): array
    {
        return $type === 'artist'
            ? ['media_artist_metadata_field_states', 'artist_id']
            : ['media_album_metadata_field_states', 'album_id'];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 手工清除后的回退判断必须与扫描/刮削写入使用同一字段级优先级。 */
    private function valuePresent(mixed $value, string $field): bool
    {
        if ($value === null || $value === '' || $value === []) return false;
        if (in_array($field, ['albumArtists', 'name'], true) && is_array($value)) {
            return !(count($value) === 1 && in_array($value[0], ['未知艺术家', 'Unknown Artist'], true));
        }
        return !($field === 'title' && in_array($value, ['单曲', 'Unknown Album'], true));
    }
}
