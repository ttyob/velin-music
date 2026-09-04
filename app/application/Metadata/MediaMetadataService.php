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
 * 提供通用歌曲元数据浏览、逐字段来源对照、保存覆盖和清除回退。
 *
 * 全局 `edit_metadata` 由 Controller 校验，本服务仍把每次读写限制在 actor 的实时 manage 音乐库范围。
 * 列表不读取原始标签 JSON、歌词正文、文件路径、扫描状态或重复证据，只投影管理员判断刮削完整性
 * 所需的有效歌曲字段、流派和歌词摘要；重复关系必须由管理员主动打开专用检测弹窗查询。详情只返回
 * 字段值和同一媒体工作台内的歌词入口。所有写命令使用字段版本 CAS，在同一短事务内提交状态、目录
 * 物化、专用逐字段历史和通用脱敏审计。
 */
final class MediaMetadataService
{
    public function __construct(
        private readonly MetadataFieldSchema $schema = new MetadataFieldSchema(),
        private readonly MetadataFieldStateRepository $states = new MetadataFieldStateRepository(),
        private readonly AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 返回可管理歌曲页；筛选值采用封闭枚举并保持 URL 可复现。
     *
     * @param array<string,mixed> $actor 当前认证身份。
     * @return array<string,mixed>
     */
    public function page(
        array $actor,
        ?string $libraryId,
        ?string $missingField,
        ?string $state,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        $this->validateFilters($libraryId, $missingField, $state, $search, $limit, $offset);
        $libraries = $this->managedLibraries($actor);
        if ($libraryId !== null && !isset($libraries[$libraryId])) throw new MediaMetadataInvalid('音乐库筛选无效。');
        if ($libraries === []) return $this->emptyPage($limit, $offset, []);

        $query = $this->scopedSongs(array_keys($libraries), $state === 'file_missing');
        if ($libraryId !== null) $query->where('songs.library_id', $libraryId);
        if ($search !== null) {
            $pattern = '%' . $this->escapeLike($search) . '%';
            $query->where(function (Builder $match) use ($pattern): void {
                $match->whereRaw("songs.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("albums.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereExists(function (Builder $artists) use ($pattern): void {
                        $artists->selectRaw('1')->from('media_song_artists as song_artists')
                            ->join('media_artists as artists', 'artists.id', '=', 'song_artists.artist_id')
                            ->whereColumn('song_artists.song_id', 'songs.id')
                            ->whereRaw("artists.name LIKE ? ESCAPE '\\'", [$pattern]);
                    });
            });
        }
        if ($state === 'scan_error') $query->where('files.metadata_status', 'failed');
        elseif ($state === 'overridden') $query->whereExists(function (Builder $fields): void {
            $fields->selectRaw('1')->from('media_metadata_field_states as state_fields')
                ->whereColumn('state_fields.song_id', 'songs.id')->whereNotNull('state_fields.manual_value_json');
        });
        elseif ($state === 'locked') $query->whereExists(function (Builder $fields): void {
            $fields->selectRaw('1')->from('media_metadata_field_states as state_fields')
                ->whereColumn('state_fields.song_id', 'songs.id')->where('state_fields.is_locked', 1);
        });
        elseif ($state === 'recent') $query->where('songs.updated_at', '>=', gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400));
        elseif ($state === 'lyrics_missing') $query->whereNotExists(function (Builder $lyrics): void {
            $lyrics->selectRaw('1')->from('media_lyrics as state_lyrics')
                ->whereColumn('state_lyrics.song_id', 'songs.id');
        });
        elseif ($state === 'artwork_missing') $this->applyMissingArtworkFilter($query);
        elseif ($state === 'file_missing') $query->where('files.status', 'missing');
        if ($missingField !== null) {
            $query->where(function (Builder $missing) use ($missingField): void {
                $missing->whereNotExists(function (Builder $fields) use ($missingField): void {
                    $fields->selectRaw('1')->from('media_metadata_field_states as missing_fields')
                        ->whereColumn('missing_fields.song_id', 'songs.id')->where('missing_fields.field_key', $missingField);
                })->orWhereExists(function (Builder $fields) use ($missingField): void {
                    $fields->selectRaw('1')->from('media_metadata_field_states as missing_fields')
                        ->whereColumn('missing_fields.song_id', 'songs.id')->where('missing_fields.field_key', $missingField)
                        ->whereIn('missing_fields.effective_value_json', ['null', '[]', '""']);
                });
            });
        }

        $total = (clone $query)->count('songs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('songs.updated_at')->orderBy('songs.id')->offset($offset)->limit($limit)->get([
            'songs.id', 'songs.title', 'songs.duration_ms', 'songs.album_id',
            'songs.track_number', 'songs.track_total', 'songs.disc_number', 'songs.disc_total',
            'songs.release_date', 'songs.isrc',
            'albums.title as album_title', 'libraries.id as library_id', 'libraries.name as library_name',
            'files.status as file_status', 'files.metadata_status',
        ])->all();
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $artists = $this->artistNames($songIds);
        $genres = $this->genreNames($songIds);
        $lyrics = $this->lyricSummaries($songIds);
        return [
            'items' => array_map(static function (stdClass $row) use ($artists, $genres, $lyrics): array {
                $lyric = $lyrics[(string) $row->id] ?? ['count' => 0, 'hasPrimary' => false];
                return [
                    'type' => 'song', 'id' => (string) $row->id, 'title' => (string) $row->title,
                    'artists' => $artists[(string) $row->id] ?? [],
                    'album' => ['id' => (string) $row->album_id, 'title' => (string) $row->album_title],
                    'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
                    'durationMs' => (int) $row->duration_ms,
                    'trackNumber' => $row->track_number === null ? null : (int) $row->track_number,
                    'trackTotal' => $row->track_total === null ? null : (int) $row->track_total,
                    'discNumber' => $row->disc_number === null ? null : (int) $row->disc_number,
                    'discTotal' => $row->disc_total === null ? null : (int) $row->disc_total,
                    'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
                    'isrc' => $row->isrc === null ? null : (string) $row->isrc,
                    'genres' => $genres[(string) $row->id] ?? [],
                    'lyrics' => $lyric,
                    'fileStatus' => (string) $row->file_status,
                    'metadataStatus' => (string) $row->metadata_status,
                ];
            }, $rows),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'filterOptions' => ['libraries' => array_values($libraries), 'fields' => MetadataFieldSchema::FIELDS],
        ];
    }

    /**
     * 返回单曲逐字段四层对照和近期变更；首次打开迁移前歌曲会惰性初始化来源状态。
     *
     * 初始化只复制数据库目录事实，不访问文件、不触发扫描且不生成管理员变更审计。
     */
    public function song(array $actor, string $songId): array
    {
        $this->requireUlid($songId);
        $row = $this->managedSong($actor, $songId);
        if (!$row instanceof stdClass) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
        $states = Db::transaction(fn (): array => $this->states->ensureSong($songId, gmdate('Y-m-d\TH:i:s\Z')));
        return $this->mapDetail($row, $states);
    }

    /**
     * 设置一个或多个手工值并可同时改变锁定状态。
     *
     * @param list<array{field:string,value:mixed,locked:bool,version:int}> $changes
     * @return array<string,mixed>
     */
    public function save(array $actor, string $songId, array $changes, string $requestId): array
    {
        $this->requireUlid($songId);
        if ($changes === [] || count($changes) > count(MetadataFieldSchema::FIELDS)) throw new MediaMetadataInvalid('字段变更数量无效。');
        $normalized = $this->normalizeChanges($changes, false);
        return Db::transaction(function () use ($actor, $songId, $normalized, $requestId): array {
            $row = $this->managedSong($actor, $songId);
            if (!$row instanceof stdClass) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $states = $this->states->ensureSong($songId, $now);
            $changeSetId = (string) new Ulid();
            $this->insertChangeSet($changeSetId, $actor, 'single', count($normalized), $requestId, $now);
            foreach ($normalized as $change) {
                $before = $states[$change['field']] ?? throw new MediaMetadataConflict('字段状态不存在。');
                if ($before['version'] !== $change['version']) throw new MediaMetadataConflict('字段版本已变化。');
                $afterValue = $change['value'];
                $updated = Db::table('media_metadata_field_states')->where('song_id', $songId)
                    ->where('field_key', $change['field'])->where('version', $change['version'])->update([
                        'manual_value_json' => $this->encode($afterValue),
                        'effective_value_json' => $this->encode($afterValue), 'effective_source' => 'manual',
                        'is_locked' => $change['locked'] ? 1 : 0, 'version' => Db::raw('version + 1'),
                        'manual_updated_at' => $now, 'updated_by' => (string) $actor['id'], 'updated_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaMetadataConflict('字段版本已变化。');
                $this->insertChangeItem($changeSetId, $songId, $change['field'], 'set', $before, $afterValue, 'manual', $now);
            }
            $this->states->materialize($songId);
            Db::table('metadata_change_sets')->where('id', $changeSetId)->update(['status' => 'succeeded', 'finished_at' => $now]);
            $this->audit->record((string) $actor['id'], 'metadata.override.save', 'song', $songId, 'success', $requestId, [
                'changeSetId' => $changeSetId, 'fieldCount' => count($normalized), 'objectCount' => 1,
            ]);
            $fresh = $this->managedSong($actor, $songId);
            return $this->mapDetail($fresh instanceof stdClass ? $fresh : $row, $this->states->states($songId), $changeSetId);
        });
    }

    /**
     * 清除指定手工值，并按字段级来源规则回退；来源事实和历史记录始终保留。
     *
     * 普通字段回退顺序为 raw > scraped，专辑与发行日期回退顺序为 scraped > raw；`unlock` 决定是否
     * 同时解除锁定。版本任一不匹配时整批回滚，不会产生部分字段成功，专辑关系迁移也由同一事务保护。
     *
     * @param list<array{field:string,version:int,unlock?:bool}> $changes
     * @return array<string,mixed>
     */
    public function clear(array $actor, string $songId, array $changes, string $requestId): array
    {
        $this->requireUlid($songId);
        if ($changes === [] || count($changes) > count(MetadataFieldSchema::FIELDS)) throw new MediaMetadataInvalid('字段变更数量无效。');
        $normalized = $this->normalizeChanges($changes, true);
        return Db::transaction(function () use ($actor, $songId, $normalized, $requestId): array {
            $row = $this->managedSong($actor, $songId);
            if (!$row instanceof stdClass) throw new MediaMetadataNotFound('歌曲不存在或不可管理。');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $states = $this->states->ensureSong($songId, $now);
            $changeSetId = (string) new Ulid();
            $this->insertChangeSet($changeSetId, $actor, 'single', count($normalized), $requestId, $now);
            foreach ($normalized as $change) {
                $before = $states[$change['field']] ?? throw new MediaMetadataConflict('字段状态不存在。');
                if ($before['version'] !== $change['version']) throw new MediaMetadataConflict('字段版本已变化。');
                $source = in_array($change['field'], ['album', 'releaseDate'], true) && $before['scraped'] !== null
                    ? 'scraped'
                    : ($this->valuePresent($before['raw'], $change['field'])
                        ? 'raw' : ($before['scraped'] !== null ? 'scraped' : 'raw'));
                $afterValue = $source === 'scraped' ? $before['scraped'] : $before['raw'];
                $updated = Db::table('media_metadata_field_states')->where('song_id', $songId)
                    ->where('field_key', $change['field'])->where('version', $change['version'])->update([
                        'manual_value_json' => null, 'effective_value_json' => $this->encode($afterValue),
                        'effective_source' => $source,
                        'is_locked' => $change['unlock'] ? 0 : ($before['locked'] ? 1 : 0),
                        'version' => Db::raw('version + 1'), 'manual_updated_at' => null,
                        'updated_by' => (string) $actor['id'], 'updated_at' => $now,
                    ]);
                if ($updated !== 1) throw new MediaMetadataConflict('字段版本已变化。');
                $this->insertChangeItem($changeSetId, $songId, $change['field'], 'clear', $before, $afterValue, $source, $now);
            }
            $this->states->materialize($songId);
            Db::table('metadata_change_sets')->where('id', $changeSetId)->update(['status' => 'succeeded', 'finished_at' => $now]);
            $this->audit->record((string) $actor['id'], 'metadata.override.clear', 'song', $songId, 'success', $requestId, [
                'changeSetId' => $changeSetId, 'fieldCount' => count($normalized), 'objectCount' => 1,
            ]);
            $fresh = $this->managedSong($actor, $songId);
            return $this->mapDetail($fresh instanceof stdClass ? $fresh : $row, $this->states->states($songId), $changeSetId);
        });
    }

    /** @return array<string,mixed> */
    private function mapDetail(stdClass $row, array $states, ?string $changeSetId = null): array
    {
        $ordered = [];
        foreach (MetadataFieldSchema::FIELDS as $field) if (isset($states[$field])) $ordered[] = $states[$field];
        /** @var list<stdClass> $history */
        $history = Db::table('metadata_change_items as items')
            ->join('metadata_change_sets as sets', 'sets.id', '=', 'items.change_set_id')
            ->leftJoin('users', 'users.id', '=', 'sets.actor_user_id')
            ->where('items.object_type', 'song')->where('items.object_id', (string) $row->id)
            ->orderByDesc('items.created_at')->orderByDesc('items.id')->limit(50)->get([
                'items.id', 'items.change_set_id', 'items.field_key', 'items.operation', 'items.result',
                'items.source_before', 'items.source_after', 'items.created_at', 'sets.task_id',
                'users.display_name as actor_name',
            ])->all();
        return [
            'type' => 'song', 'id' => (string) $row->id, 'title' => (string) $row->title,
            'artists' => $this->artistNames([(string) $row->id])[(string) $row->id] ?? [],
            'album' => ['id' => (string) $row->album_id, 'title' => (string) $row->album_title],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'durationMs' => (int) $row->duration_ms, 'fields' => $ordered,
            'artworkUrl' => '/api/v1/albums/' . rawurlencode((string) $row->album_id) . '/cover',
            'lyricsUrl' => '/admin/media?lyricsSongId=' . rawurlencode((string) $row->id),
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
            'policy' => ['databaseOnly' => true, 'writesAudioFile' => false, 'writebackRequiresPlan' => true],
        ];
    }

    /** @param list<array<string,mixed>> $changes @return list<array<string,mixed>> */
    private function normalizeChanges(array $changes, bool $clear): array
    {
        if (!array_is_list($changes)) throw new MediaMetadataInvalid('字段变更必须为列表。');
        $result = []; $seen = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !is_string($change['field'] ?? null)
                || !is_int($change['version'] ?? null) || $change['version'] < 1) {
                throw new MediaMetadataInvalid('字段变更结构无效。');
            }
            $field = $change['field'];
            if (isset($seen[$field])) throw new MediaMetadataInvalid('同一字段不能重复提交。');
            $seen[$field] = true;
            if (!in_array($field, MetadataFieldSchema::FIELDS, true)) throw new MediaMetadataInvalid('不支持的元数据字段。');
            if ($clear) {
                if (isset($change['unlock']) && !is_bool($change['unlock'])) throw new MediaMetadataInvalid('解锁值无效。');
                $result[] = ['field' => $field, 'version' => $change['version'], 'unlock' => $change['unlock'] ?? false];
            } else {
                if (!array_key_exists('value', $change) || !is_bool($change['locked'] ?? null)) throw new MediaMetadataInvalid('字段值或锁定状态无效。');
                $result[] = ['field' => $field, 'version' => $change['version'],
                    'value' => $this->schema->normalize($field, $change['value']), 'locked' => $change['locked']];
            }
        }
        return $result;
    }

    private function insertChangeSet(string $id, array $actor, string $type, int $fields, string $requestId, string $now): void
    {
        Db::table('metadata_change_sets')->insert([
            'id' => $id, 'actor_user_id' => (string) $actor['id'], 'command_type' => $type,
            'source_kind' => 'admin', 'object_count' => 1, 'changed_field_count' => $fields,
            'task_id' => null, 'status' => 'running', 'request_id' => $requestId,
            'created_at' => $now, 'finished_at' => null,
        ]);
    }

    private function insertChangeItem(string $setId, string $songId, string $field, string $operation, array $before, mixed $after, string $sourceAfter, string $now): void
    {
        Db::table('metadata_change_items')->insert([
            'id' => (string) new Ulid(), 'change_set_id' => $setId, 'object_type' => 'song',
            'object_id' => $songId, 'field_key' => $field, 'operation' => $operation,
            'before_value_json' => $this->encode($before['effective']), 'after_value_json' => $this->encode($after),
            'source_before' => $before['source'], 'source_after' => $sourceAfter,
            'result' => 'succeeded', 'error_code' => null, 'created_at' => $now,
        ]);
    }

    private function managedSong(array $actor, string $songId): ?stdClass
    {
        $managed = array_keys($this->managedLibraries($actor));
        if ($managed === []) return null;
        /** @var stdClass|null $row */
        $row = $this->scopedSongs($managed)->where('songs.id', $songId)->first([
            'songs.id', 'songs.title', 'songs.duration_ms', 'songs.album_id', 'songs.library_id',
            'albums.title as album_title', 'libraries.name as library_name', 'files.metadata_status',
        ]);
        return $row;
    }

    private function scopedSongs(array $libraryIds, bool $includeUnavailable = false): Builder
    {
        $query = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->whereIn('songs.library_id', $libraryIds ?: [''])
            ->where('libraries.status', 'active');
        if (!$includeUnavailable) $query->where('files.status', 'available');
        return $query;
    }

    /**
     * 只按已提交的封面选择与扫描索引事实筛选“缺封面”歌曲。
     *
     * 歌曲可使用自己的选择、所属专辑选择、所属专辑本地封面，或同专辑其他歌曲的已选图作为回退；
     * 四类来源均不存在才视为缺失。查询不打开文件，因此运行时身份损坏仍由图片读取服务失败关闭，
     * 不会让后台列表触发磁盘 I/O。所有子查询依附已经完成音乐库 manage 裁剪的外层歌曲。
     */
    private function applyMissingArtworkFilter(Builder $query): void
    {
        $query->whereNotExists(function (Builder $selection): void {
            $selection->selectRaw('1')->from('media_artwork_selection_overrides as song_artwork')
                ->whereColumn('song_artwork.song_id', 'songs.id');
        })->whereNotExists(function (Builder $selection): void {
            $selection->selectRaw('1')->from('media_artwork_selection_overrides as album_artwork')
                ->whereColumn('album_artwork.album_id', 'songs.album_id');
        })->whereNotExists(function (Builder $artwork): void {
            $artwork->selectRaw('1')->from('media_album_artworks as indexed_artwork')
                ->whereColumn('indexed_artwork.album_id', 'songs.album_id');
        })->whereNotExists(function (Builder $fallback): void {
            $fallback->selectRaw('1')->from('media_songs as album_song')
                ->join('media_artwork_selection_overrides as fallback_artwork',
                    'fallback_artwork.song_id', '=', 'album_song.id')
                ->whereColumn('album_song.album_id', 'songs.album_id');
        });
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

    /** @param list<string> $songIds @return array<string,list<string>> */
    private function artistNames(array $songIds): array
    {
        if ($songIds === []) return [];
        $result = [];
        foreach (Db::table('media_song_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('links.song_id', $songIds)->orderBy('links.song_id')->orderBy('links.position')
            ->get(['links.song_id', 'artists.name']) as $row) {
            $result[(string) $row->song_id][] = (string) $row->name;
        }
        return $result;
    }

    /**
     * 按标签位置聚合当前页歌曲的有效流派名称，供管理员直接判断描述元数据是否缺失。
     *
     * 前置条件：歌曲 ID 已经过实时 manage 音乐库范围过滤，且数量受分页上限约束。本查询只读取规范化
     * 关系表，不解析原始标签 JSON，也不返回流派内部 ID；没有关系的歌曲由调用方投影为空列表。方法
     * 不写数据库、文件或缓存，查询失败由上层按整个列表读取失败处理，不把不完整结果伪装成缺失值。
     *
     * @param list<string> $songIds 当前授权页内的歌曲 ID
     * @return array<string,list<string>>
     */
    private function genreNames(array $songIds): array
    {
        if ($songIds === []) return [];
        $result = [];
        foreach (Db::table('media_song_genres as links')->join('media_genres as genres', 'genres.id', '=', 'links.genre_id')
            ->whereIn('links.song_id', $songIds)->orderBy('links.song_id')->orderBy('links.position')
            ->get(['links.song_id', 'genres.name']) as $row) {
            $result[(string) $row->song_id][] = (string) $row->name;
        }
        return $result;
    }

    /**
     * 聚合当前页歌词索引数量和显式主歌词选择，不读取任何歌词文件正文。
     *
     * @param list<string> $songIds 已通过实时 manage 范围过滤的歌曲 ID
     * @return array<string,array{count:int,hasPrimary:bool}>
     */
    private function lyricSummaries(array $songIds): array
    {
        if ($songIds === []) return [];
        $result = [];
        foreach (Db::table('media_lyrics as lyrics')
            ->leftJoin('media_lyrics_primary_selections as selections', 'selections.lyric_id', '=', 'lyrics.id')
            ->whereIn('lyrics.song_id', $songIds)->groupBy('lyrics.song_id')
            ->get(['lyrics.song_id', Db::raw('COUNT(lyrics.id) AS lyric_count'),
                Db::raw('MAX(CASE WHEN selections.lyric_id IS NULL THEN 0 ELSE 1 END) AS has_primary')]) as $row) {
            $result[(string) $row->song_id] = [
                'count' => (int) $row->lyric_count,
                'hasPrimary' => (int) $row->has_primary === 1,
            ];
        }
        return $result;
    }

    private function validateFilters(?string $libraryId, ?string $missingField, ?string $state, ?string $search, int $limit, int $offset): void
    {
        if ($libraryId !== null) $this->requireUlid($libraryId);
        if ($missingField !== null && !in_array($missingField, MetadataFieldSchema::FIELDS, true)) throw new MediaMetadataInvalid('缺失字段筛选无效。');
        if ($state !== null && !in_array($state, [
            'scan_error', 'overridden', 'locked', 'recent', 'lyrics_missing', 'artwork_missing', 'file_missing',
        ], true)) throw new MediaMetadataInvalid('状态筛选无效。');
        if ($search !== null && (mb_strlen($search, 'UTF-8') < 1 || mb_strlen($search, 'UTF-8') > 100)) throw new MediaMetadataInvalid('搜索词长度无效。');
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) throw new MediaMetadataInvalid('分页参数无效。');
    }

    private function requireUlid(string $id): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) throw new MediaMetadataInvalid('对象标识无效。');
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 手工清除后的回退判断与扫描/刮削写入保持一致，空列表和扫描占位值均视为缺失。 */
    private function valuePresent(mixed $value, string $field): bool
    {
        if ($value === null || $value === '' || $value === []) return false;
        if (in_array($field, ['artists', 'albumArtists'], true) && is_array($value)) {
            return !(count($value) === 1 && in_array($value[0], ['未知艺术家', 'Unknown Artist'], true));
        }
        return !($field === 'album' && in_array($value, ['单曲', 'Unknown Album'], true));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function emptyPage(int $limit, int $offset, array $libraries): array
    {
        return ['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset,
            'filterOptions' => ['libraries' => $libraries, 'fields' => MetadataFieldSchema::FIELDS]];
    }
}
