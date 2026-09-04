<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Artist\ArtistNameIdentityNormalizer;
use app\application\Media\AlbumEditionNameNormalizer;
use app\application\Search\SearchTextNormalizer;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 管理艺术家/专辑合并、影响预览、旧 ID 重定向和无冲突回滚（ADMIN-META-010/012）。
 *
 * 服务只修改目录数据库关系，不打开、移动或重写音频/图片文件。歌曲稳定 ID 始终不变，因此歌曲收藏、
 * 播放历史、歌词、播放列表和以歌曲为目标的分享无需重建；专辑/艺术家收藏与专辑直连分享会显式迁移。
 * 每次合并在 IMMEDIATE 短事务中保存完整受影响关系快照，并对执行后状态计算 SHA-256。回滚只有在当前
 * 状态仍与该摘要完全一致时执行，防止覆盖后续扫描、人工编辑、收藏、封面或分享变更。
 *
 * 艺术家是跨库词汇，调用者必须对来源和目标实际关联的全部活动音乐库拥有 manage；专辑合并仅允许
 * 同一音乐库且要求该库 manage。全局 `edit_metadata` 由 Controller 验证，本服务仍独立复验对象范围。
 * 返回投影不含快照、物理路径、文件身份、用户 ID 或内部摘要。
 */
final readonly class MetadataEntityService
{
    private const TYPES = ['artist', 'album'];

    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
        private SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
        private EntityMetadataStateRepository $entityStates = new EntityMetadataStateRepository(),
        private AlbumEditionNameNormalizer $albumEditions = new AlbumEditionNameNormalizer(),
        private ArtistNameIdentityNormalizer $artistNames = new ArtistNameIdentityNormalizer(),
    )
    {
    }

    /**
     * 列出当前操作者可完整管理的艺术家或专辑候选。
     *
     * 艺术家只有在其全部实际引用库都位于 manage 范围时出现，避免选择一个共享艺术家后影响未授权库。
     * 查询只返回名称、库、歌曲数、更新时间、封面 URL 和外部 ID；q 最长 100 字符，分页固定上限 100。
     * 封面投影使用批量查询：手工选择返回受后台权限保护的不可变候选 URL，扫描来源返回现有公开图片
     * 端点并携带不可逆版本摘要。URL 本身不授予读取权限，图片端点仍会重新验证账号和实时库范围。
     *
     * 专辑响应额外返回最多 50 组高置信版次重复候选。候选只在同库、同主要专辑艺术家和相同基础标题内
     * 生成，且至少一侧含明确版次词；它只预填现有合并预览，不自动执行关系修改。
     *
     * @return array{entities:list<array<string,mixed>>,total:int,limit:int,offset:int,libraries:list<array{id:string,name:string}>,editionCandidates:list<array<string,mixed>>}
     */
    public function entities(array $actor, string $type, ?string $query, ?string $libraryId, int $limit, int $offset): array
    {
        $this->type($type);
        $managed = $this->managedLibraries($actor);
        $managedIds = array_keys($managed);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $query = is_string($query) && trim($query) !== '' ? mb_substr(trim($query), 0, 100) : null;
        if ($libraryId !== null && ($libraryId === '' || !isset($managed[$libraryId]))) {
            return ['entities' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset,
                'libraries' => array_values($managed), 'editionCandidates' => []];
        }
        if ($managedIds === []) {
            return ['entities' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset,
                'libraries' => [], 'editionCandidates' => []];
        }

        if ($type === 'album') {
            $base = Db::table('media_albums as entities')->join('music_libraries as libraries', 'libraries.id', '=', 'entities.library_id')
                ->whereIn('entities.library_id', $managedIds)->where('libraries.status', 'active');
            if ($libraryId !== null) $base->where('entities.library_id', $libraryId);
            if ($query !== null) {
                $base->whereRaw("entities.title LIKE ? ESCAPE '\\'", ['%' . $this->escapeLike($query) . '%']);
            }
            $total = (clone $base)->count('entities.id');
            /** @var list<stdClass> $rows */
            $rows = $base->orderBy('entities.title')->orderBy('entities.id')->offset($offset)->limit($limit)->get([
                'entities.id', 'entities.title as name', 'entities.updated_at', 'entities.song_count',
                'entities.musicbrainz_release_id', 'entities.musicbrainz_release_group_id',
                'libraries.id as library_id', 'libraries.name as library_name',
            ])->all();
            $artworkUrls = $this->entityArtworkUrls($type, $rows, $managedIds);
            return ['entities' => array_map(
                fn (stdClass $row): array => $this->mapEntityRow($type, $row, $artworkUrls),
                $rows,
            ),
                'total' => $total, 'limit' => $limit, 'offset' => $offset, 'libraries' => array_values($managed),
                'editionCandidates' => $this->albumEditionCandidates($managedIds, $libraryId, $query)];
        }

        // 艺术家是全局词汇：歌曲署名和专辑署名任一可见即可成为候选，再排除仍被未管理库引用的对象。
        $visibleIds = $libraryId === null ? $managedIds : [$libraryId];
        $base = Db::table('media_artists as entities')->where(function (Builder $visible) use ($visibleIds): void {
            $visible->whereExists(function (Builder $songs) use ($visibleIds): void {
                $songs->selectRaw('1')->from('media_song_artists as visible_song_credits')
                    ->join('media_songs as visible_songs', 'visible_songs.id', '=', 'visible_song_credits.song_id')
                    ->whereColumn('visible_song_credits.artist_id', 'entities.id')
                    ->whereIn('visible_songs.library_id', $visibleIds);
            })->orWhereExists(function (Builder $albums) use ($visibleIds): void {
                $albums->selectRaw('1')->from('media_album_artists as visible_album_credits')
                    ->join('media_albums as visible_albums', 'visible_albums.id', '=', 'visible_album_credits.album_id')
                    ->whereColumn('visible_album_credits.artist_id', 'entities.id')
                    ->whereIn('visible_albums.library_id', $visibleIds);
            });
        });
        if ($query !== null) {
            $base->whereRaw("entities.name LIKE ? ESCAPE '\\'", ['%' . $this->escapeLike($query) . '%']);
        }
        $base->whereNotExists(function (Builder $outside) use ($managedIds): void {
            $outside->selectRaw('1')->from('media_song_artists as outside_credits')
                ->join('media_songs as outside_songs', 'outside_songs.id', '=', 'outside_credits.song_id')
                ->whereColumn('outside_credits.artist_id', 'entities.id')->whereNotIn('outside_songs.library_id', $managedIds);
        })->whereNotExists(function (Builder $outside) use ($managedIds): void {
            $outside->selectRaw('1')->from('media_album_artists as outside_credits')
                ->join('media_albums as outside_albums', 'outside_albums.id', '=', 'outside_credits.album_id')
                ->whereColumn('outside_credits.artist_id', 'entities.id')->whereNotIn('outside_albums.library_id', $managedIds);
        });
        $total = (clone $base)->count('entities.id');
        /** @var list<stdClass> $rows */
        $rows = $base->select([
            'entities.id', 'entities.name', 'entities.updated_at', 'entities.musicbrainz_artist_id',
        ])->selectSub(function (Builder $songs) use ($managedIds): void {
            $songs->from('media_song_artists as count_credits')->join('media_songs as count_songs', 'count_songs.id', '=', 'count_credits.song_id')
                ->whereColumn('count_credits.artist_id', 'entities.id')->whereIn('count_songs.library_id', $managedIds)
                ->selectRaw('COUNT(DISTINCT count_songs.id)');
        }, 'song_count')->orderBy('entities.name')->orderBy('entities.id')->offset($offset)->limit($limit)->get()->all();
        $artworkUrls = $this->entityArtworkUrls($type, $rows, $managedIds);
        return ['entities' => array_map(
            fn (stdClass $row): array => $this->mapEntityRow($type, $row, $artworkUrls),
            $rows,
        ),
            'total' => $total, 'limit' => $limit, 'offset' => $offset, 'libraries' => array_values($managed),
            'editionCandidates' => []];
    }

    /**
     * 构建历史版次重复实体的只读合并建议。
     *
     * 分组只使用当前 actor 可管理的活动音乐库，并绑定主要专辑艺术家。每组必须至少包含一个明确版次
     * 标题和一个可确定目标：优先选择唯一基础标题；若只有多个版次则选择最早创建实体。出现两个基础
     * 标题时视为同名发行歧义，不返回建议。结果最多 50 组，避免异常标签库放大管理响应；执行仍须走
     * mergeImpact/merge 的实时授权、乐观锁、审计和可回滚事务。
     *
     * @return list<array<string,mixed>>
     */
    private function albumEditionCandidates(array $managedIds, ?string $libraryId, ?string $query): array
    {
        $scope = $libraryId === null ? $managedIds : [$libraryId];
        $albums = Db::table('media_albums as albums')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'albums.library_id')
            ->join('media_album_artists as credits', function ($join): void {
                $join->on('credits.album_id', '=', 'albums.id')->where('credits.position', '=', 0);
            })
            ->leftJoin('media_album_metadata_field_states as title_state', function ($join): void {
                $join->on('title_state.album_id', '=', 'albums.id')->where('title_state.field_key', '=', 'title');
            })
            ->whereIn('albums.library_id', $scope)->where('libraries.status', 'active')
            ->orderBy('albums.created_at')->get([
                'albums.id', 'albums.title', 'albums.release_year', 'albums.song_count', 'albums.updated_at',
                'albums.created_at', 'albums.library_id', 'libraries.name as library_name',
                'credits.artist_id', 'title_state.raw_value_json as raw_title_json',
            ])->all();
        $groups = [];
        foreach ($albums as $album) {
            $sourceTitle = $this->albumCandidateSourceTitle($album);
            if ($query !== null && mb_stripos((string) $album->title, $query) === false
                && mb_stripos($sourceTitle, $query) === false) continue;
            $baseKey = $this->albumEditions->baseIdentityKey($sourceTitle);
            if ($baseKey === '') continue;
            $key = (string) $album->library_id . "\0" . (string) $album->artist_id . "\0" . $baseKey;
            $groups[$key][] = ['row' => $album, 'sourceTitle' => $sourceTitle,
                'edition' => $this->albumEditions->hasEditionQualifier($sourceTitle)];
        }
        $result = [];
        foreach ($groups as $members) {
            if (count($members) < 2 || !in_array(true, array_column($members, 'edition'), true)) continue;
            $base = array_values(array_filter($members, static fn (array $member): bool => !$member['edition']));
            if (count($base) > 1) continue;
            $target = $base[0] ?? $members[0];
            foreach ($members as $source) {
                if ((string) $source['row']->id === (string) $target['row']->id) continue;
                $result[] = [
                    'source' => $this->mapAlbumEditionCandidate($source['row']),
                    'target' => $this->mapAlbumEditionCandidate($target['row']),
                    'reason' => 'explicit_edition_suffix',
                    'automaticMerge' => false,
                ];
                if (count($result) >= 50) return $result;
            }
        }

        return $result;
    }

    /** 字段状态存在时读取原始扫描标题，避免人工显示改名制造或隐藏候选。 */
    private function albumCandidateSourceTitle(stdClass $album): string
    {
        if (is_string($album->raw_title_json ?? null)) {
            try {
                $value = json_decode($album->raw_title_json, true, 8, JSON_THROW_ON_ERROR);
                if (is_string($value) && trim($value) !== '') return $value;
            } catch (Throwable) {
                // 损坏字段状态不放宽匹配，仅回退当前专辑标题。
            }
        }

        return (string) $album->title;
    }

    /** @return array<string,mixed> 把候选实体映射为前端合并预填所需的最小安全字段。 */
    private function mapAlbumEditionCandidate(stdClass $album): array
    {
        return [
            'id' => (string) $album->id, 'type' => 'album', 'name' => (string) $album->title,
            'songCount' => (int) $album->song_count,
            'library' => ['id' => (string) $album->library_id, 'name' => (string) $album->library_name],
            'updatedAt' => (string) $album->updated_at,
        ];
    }

    /**
     * 返回合并前影响范围，不创建操作或锁定媒体。
     *
     * 预览统计来源实体歌曲、来源收藏、包含这些歌曲的播放列表和歌曲/专辑分享。专辑直连分享也计入；
     * 艺术家本身不是可分享对象，因此只统计其歌曲及署名专辑。外部 ID 只展示固定字段，不访问第三方。
     */
    public function mergeImpact(array $actor, string $type, string $sourceId, string $targetId): array
    {
        $this->mergeInput($type, $sourceId, $targetId);
        $source = $this->entity($type, $sourceId);
        $target = $this->entity($type, $targetId);
        $this->assertScope($actor, $type, [$sourceId, $targetId], $source, $target);
        $impact = $this->impact($type, $sourceId);
        return ['entityType' => $type, 'source' => $this->publicEntity($type, $source),
            'target' => $this->publicEntity($type, $target), 'impact' => $impact,
            'confirmation' => 'MERGE ' . strtoupper($type), 'canMerge' => true];
    }

    /**
     * 分页列出一个来源实体可用于拆分的安全歌曲摘要。
     *
     * 返回前重新验证来源全部实际库都在 actor manage 范围。歌曲只包含稳定 ID、标题和专辑显示名，
     * 不返回库存、路径、标签快照或个人关系；专辑/标题排序使用数据库当前投影，分页上限 500。
     *
     * @return array{songs:list<array{id:string,title:string,album:array{id:string,title:string}>>,total:int,limit:int,offset:int}
     */
    public function entitySongs(array $actor, string $type, string $sourceId, int $limit, int $offset): array
    {
        $this->type($type);
        $source = $this->entity($type, $sourceId);
        $this->assertScope($actor, $type, [$sourceId], $source, null);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $ids = $this->sourceSongIds($type, $sourceId);
        if ($ids === []) return ['songs' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        /** @var list<stdClass> $rows */
        $rows = Db::table('media_songs as songs')->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->whereIn('songs.id', $ids)->orderBy('albums.title')->orderBy('songs.disc_number')
            ->orderBy('songs.track_number')->orderBy('songs.title')->orderBy('songs.id')
            ->offset($offset)->limit($limit)->get([
                'songs.id', 'songs.title', 'albums.id as album_id', 'albums.title as album_title',
            ])->all();
        return ['songs' => array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id, 'title' => (string) $row->title,
            'album' => ['id' => (string) $row->album_id, 'title' => (string) $row->album_title],
        ], $rows), 'total' => count($ids), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * 原子合并来源实体到目标实体，并保存旧 ID 重定向和完整回滚快照。
     *
     * expected 时间必须来自最新预览；确认文本精确为 `MERGE ARTIST` 或 `MERGE ALBUM`。并发扫描或编辑
     * 导致时间变化、来源已有活动重定向或专辑跨库时整体失败。目标名称和稳定 ID始终保留；目标缺失的
     * 外部 ID 可从来源补齐。歌曲 ID、文件、播放历史、歌词和播放列表项不会改变。
     *
     * @return array{operation:array<string,mixed>,redirect:array{sourceId:string,targetId:string}}
     */
    public function merge(
        array $actor,
        string $type,
        string $sourceId,
        string $targetId,
        string $expectedSourceUpdatedAt,
        string $expectedTargetUpdatedAt,
        string $confirmation,
        string $requestId,
    ): array {
        $this->mergeInput($type, $sourceId, $targetId);
        if ($confirmation !== 'MERGE ' . strtoupper($type)
            || !$this->timestamp($expectedSourceUpdatedAt) || !$this->timestamp($expectedTargetUpdatedAt)) {
            throw new MetadataEntityInvalid('合并确认或实体版本无效。');
        }
        $actorId = $this->actorId($actor);
        $operationId = (string) new Ulid();
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $source = $this->entity($type, $sourceId);
            $target = $this->entity($type, $targetId);
            $this->assertScope($actor, $type, [$sourceId, $targetId], $source, $target);
            if ((string) $source->updated_at !== $expectedSourceUpdatedAt
                || (string) $target->updated_at !== $expectedTargetUpdatedAt
                || Db::table('metadata_entity_redirects')->where('entity_type', $type)->where('status', 'active')
                    ->whereIn('source_entity_id', [$sourceId, $targetId])->exists()) {
                throw new MetadataEntityConflict('实体在预览后发生变化。');
            }
            // 合并会删除来源实体；先把两端惰性状态固定进快照，才能在回滚时恢复人工覆盖与锁定。
            $stateNow = gmdate('Y-m-d\TH:i:s\Z');
            $this->entityStates->ensure($type, $sourceId, $stateNow);
            $this->entityStates->ensure($type, $targetId, $stateNow);
            $impact = $this->impact($type, $sourceId);
            $snapshot = $this->captureMergeSnapshot($type, $sourceId, $targetId, $source, $target, $impact);
            $now = $stateNow;
            if ($type === 'artist') {
                $this->mergeArtist($sourceId, $targetId, $source, $target, $now);
            } else {
                $this->mergeAlbum($sourceId, $targetId, $source, $target, $now);
            }
            // 目标字段来源优先于合并时的目录字段补齐；人工值和锁定不能被关系合并隐式覆盖。
            $this->entityStates->materialize($type, $targetId, $now);
            $postcondition = $this->stateHash($snapshot);
            $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($snapshotJson) > 16_777_216) throw new MetadataEntityConflict('受影响关系过多，无法安全保存回滚快照。');
            Db::table('metadata_entity_operations')->insert([
                'id' => $operationId, 'entity_type' => $type, 'operation_type' => 'merge',
                'source_entity_id' => $sourceId, 'target_entity_id' => $targetId, 'created_entity_id' => null,
                'actor_user_id' => $actorId, 'library_id' => $type === 'album' ? (string) $source->library_id : null,
                'status' => 'applied', 'version' => 1,
                'affected_song_count' => $impact['songs'], 'affected_favorite_count' => $impact['favorites'],
                'affected_playlist_count' => $impact['playlists'],
                'snapshot_json' => $snapshotJson, 'postcondition_sha256' => $postcondition,
                'created_at' => $now, 'rolled_back_at' => null, 'updated_at' => $now,
            ]);
            Db::table('metadata_entity_redirects')->insert([
                'entity_type' => $type, 'source_entity_id' => $sourceId, 'target_entity_id' => $targetId,
                'operation_id' => $operationId, 'status' => 'active', 'created_at' => $now, 'reverted_at' => null,
            ]);
            $this->audit->record($actorId, 'metadata.entity.merge', 'metadata_entity_operation', $operationId,
                'success', $requestId, ['entityType' => $type, 'sourceId' => $sourceId, 'targetId' => $targetId,
                    'affectedSongs' => $impact['songs'], 'affectedFavorites' => $impact['favorites'],
                    'affectedPlaylists' => $impact['playlists']]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable) { /* 保留原始领域或数据库异常。 */ }
            }
            throw $throwable;
        }
        return ['operation' => $this->operation($actor, $operationId), 'redirect' => compact('sourceId', 'targetId')];
    }

    /**
     * 预览把来源实体的部分歌曲拆到新实体后的关系影响。
     *
     * songIds 必须全部仍属于来源实体且不能覆盖来源全部歌曲。拆分保持歌曲和播放列表 ID，
     * 所以列表计数表示“会跟随稳定歌曲继续有效”的关系，不表示这些个人数据会被重写。新实体不
     * 继承收藏或外部 ID，避免系统替用户猜测偏好或制造重复第三方身份。
     */
    public function splitImpact(array $actor, string $type, string $sourceId, array $songIds, string $newName): array
    {
        [$source, $selected] = $this->validateSplit($actor, $type, $sourceId, $songIds, $newName);
        $impact = $this->songRelationImpact($selected);

        return [
            'entityType' => $type,
            'source' => $this->publicEntity($type, $source),
            'newEntity' => ['name' => trim($newName), 'externalIds' => $this->emptyExternalIds($type)],
            'selectedSongIds' => $selected,
            'impact' => ['songs' => count($selected), 'favorites' => 0] + $impact,
            'confirmation' => 'SPLIT ' . strtoupper($type),
            'canSplit' => true,
        ];
    }

    /**
     * 原子创建一个艺术家或专辑，并把预览选择的歌曲关系拆分到新实体。
     *
     * expectedSourceUpdatedAt、歌曲集合和固定确认文本共同形成乐观锁。事务内会重新验证每首歌曲仍属于
     * 来源；任何扫描、人工编辑或同名实体竞争都会整体回滚。艺术家拆分只移动被选歌曲的来源署名，并
     * 在必要时同步专辑署名；专辑拆分只移动 album_id。两种操作均不读取或修改媒体文件。
     *
     * @return array{operation:array<string,mixed>,createdEntity:array<string,mixed>}
     */
    public function split(
        array $actor,
        string $type,
        string $sourceId,
        array $songIds,
        string $newName,
        string $expectedSourceUpdatedAt,
        string $confirmation,
        string $requestId,
    ): array {
        if ($confirmation !== 'SPLIT ' . strtoupper($type) || !$this->timestamp($expectedSourceUpdatedAt)) {
            throw new MetadataEntityInvalid('拆分确认或实体版本无效。');
        }
        $actorId = $this->actorId($actor);
        $operationId = (string) new Ulid();
        $createdId = (string) new Ulid();
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            [$source, $selected] = $this->validateSplit($actor, $type, $sourceId, $songIds, $newName);
            if ((string) $source->updated_at !== $expectedSourceUpdatedAt
                || Db::table('metadata_entity_redirects')->where('entity_type', $type)->where('status', 'active')
                    ->where('source_entity_id', $sourceId)->exists()) {
                throw new MetadataEntityConflict('实体在预览后发生变化。');
            }
            $impact = ['songs' => count($selected), 'favorites' => 0] + $this->songRelationImpact($selected);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->entityStates->ensure($type, $sourceId, $now);
            $snapshot = $this->captureSplitSnapshot($type, $source, $selected, $impact);
            if ($type === 'artist') {
                $this->splitArtist($source, $createdId, $selected, trim($newName), $now);
            } else {
                $this->splitAlbum($source, $createdId, $selected, trim($newName), $now);
            }
            // 新实体建立确定的 raw 状态，使单纯打开详情不会改变后置摘要并阻断安全回滚。
            $this->entityStates->ensure($type, $createdId, $now);
            $snapshot['createdEntityId'] = $createdId;
            $postcondition = $this->stateHash($snapshot);
            $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($snapshotJson) > 16_777_216) {
                throw new MetadataEntityConflict('受影响关系过多，无法安全保存回滚快照。');
            }
            Db::table('metadata_entity_operations')->insert([
                'id' => $operationId, 'entity_type' => $type, 'operation_type' => 'split',
                'source_entity_id' => $sourceId, 'target_entity_id' => $createdId, 'created_entity_id' => $createdId,
                'actor_user_id' => $actorId, 'library_id' => $type === 'album' ? (string) $source->library_id : null,
                'status' => 'applied', 'version' => 1,
                'affected_song_count' => $impact['songs'], 'affected_favorite_count' => 0,
                'affected_playlist_count' => $impact['playlists'],
                'snapshot_json' => $snapshotJson, 'postcondition_sha256' => $postcondition,
                'created_at' => $now, 'rolled_back_at' => null, 'updated_at' => $now,
            ]);
            $this->audit->record($actorId, 'metadata.entity.split', 'metadata_entity_operation', $operationId,
                'success', $requestId, ['entityType' => $type, 'sourceId' => $sourceId, 'createdId' => $createdId,
                    'affectedSongs' => $impact['songs'], 'affectedPlaylists' => $impact['playlists']]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable) { /* 保留原始领域或数据库异常。 */ }
            }
            throw $throwable;
        }

        return ['operation' => $this->operation($actor, $operationId),
            'createdEntity' => $this->publicEntity($type, $this->entity($type, $createdId))];
    }

    /** 返回最近操作的脱敏投影；快照和后置摘要永不出现在 API。 */
    public function operations(array $actor, int $limit, int $offset): array
    {
        $this->actorId($actor);
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $query = Db::table('metadata_entity_operations');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->get($this->operationColumns())->all();
        $visible = array_values(array_filter($rows, fn (stdClass $row): bool => $this->operationVisible($actor, $row)));
        return ['operations' => array_map(fn (stdClass $row): array => $this->mapOperation($row),
            array_slice($visible, $offset, $limit)), 'total' => count($visible), 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * 回滚一个仍满足执行后摘要的合并操作。
     *
     * 回滚会恢复来源/目标行、署名、收藏、封面与专辑分享的精确执行前快照，并停用旧 ID 重定向。任何
     * 后续变化都会造成摘要不一致并整体拒绝，不提供“强制覆盖”参数。操作版本和确认文本防止重复提交；
     * 已回滚操作幂等地拒绝为冲突，不重复写审计。
     */
    public function rollback(array $actor, string $operationId, int $expectedVersion, string $confirmation, string $requestId): array
    {
        if (!$this->ulid($operationId) || $expectedVersion < 1 || $confirmation !== 'ROLLBACK METADATA') {
            throw new MetadataEntityInvalid('回滚命令无效。');
        }
        $actorId = $this->actorId($actor);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $row */
            $row = Db::table('metadata_entity_operations')->where('id', $operationId)->first();
            if (!$row instanceof stdClass) throw new MetadataEntityNotFound('元数据操作不存在。');
            if ((string) $row->status !== 'applied' || (int) $row->version !== $expectedVersion
                || !in_array((string) $row->operation_type, ['merge', 'split'], true)) {
                throw new MetadataEntityConflict('元数据操作状态已变化。');
            }
            $snapshot = json_decode((string) $row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || !hash_equals((string) $row->postcondition_sha256, $this->stateHash($snapshot))) {
                throw new MetadataEntityConflict('操作后媒体已变化，不能安全回滚。');
            }
            $source = is_array($snapshot['source'] ?? null) ? $snapshot['source'] : [];
            $target = is_array($snapshot['target'] ?? null) ? $snapshot['target'] : [];
            $type = (string) $row->entity_type;
            $this->assertScope($actor, $type, [(string) $row->target_entity_id], null, (object) $target);
            if ((string) $row->operation_type === 'merge') {
                if ($type === 'artist') $this->rollbackArtistMerge($snapshot);
                else $this->rollbackAlbumMerge($snapshot);
            } elseif ($type === 'artist') {
                $this->rollbackArtistSplit($snapshot);
            } else {
                $this->rollbackAlbumSplit($snapshot);
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('metadata_entity_operations')->where('id', $operationId)->where('status', 'applied')
                ->where('version', $expectedVersion)->update(['status' => 'rolled_back', 'version' => Db::raw('version + 1'),
                    'rolled_back_at' => $now, 'updated_at' => $now]);
            if ($changed !== 1) throw new MetadataEntityConflict('元数据操作状态已变化。');
            Db::table('metadata_entity_redirects')->where('operation_id', $operationId)->where('status', 'active')
                ->update(['status' => 'reverted', 'reverted_at' => $now]);
            $this->audit->record($actorId, 'metadata.entity.rollback', 'metadata_entity_operation', $operationId,
                'success', $requestId, ['entityType' => $type, 'sourceId' => (string) ($source['id'] ?? ''),
                    'targetId' => (string) ($target['id'] ?? '')]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable) { /* 保留原始冲突。 */ }
            }
            throw $throwable;
        }
        return $this->operation($actor, $operationId);
    }

    /** 返回一个操作投影；内部快照和摘要用于回滚授权，不属于管理端可读字段。 */
    public function operation(array $actor, string $operationId): array
    {
        if (!$this->ulid($operationId)) throw new MetadataEntityNotFound('元数据操作不存在。');
        /** @var stdClass|null $row */
        $row = Db::table('metadata_entity_operations')->where('id', $operationId)->first($this->operationColumns());
        if (!$row instanceof stdClass) throw new MetadataEntityNotFound('元数据操作不存在。');
        if (!$this->operationVisible($actor, $row)) throw new MetadataEntityNotFound('元数据操作不存在。');
        return $this->mapOperation($row);
    }

    /** 应用艺术家署名、收藏、封面和缺失外部 ID 合并，然后删除来源词汇行。 */
    private function mergeArtist(string $sourceId, string $targetId, stdClass $source, stdClass $target, string $now): void
    {
        foreach (Db::table('media_song_artists')->where('artist_id', $sourceId)->get()->all() as $row) {
            Db::table('media_song_artists')->insertOrIgnore(['song_id' => $row->song_id, 'artist_id' => $targetId,
                'role' => $row->role, 'position' => $row->position]);
        }
        Db::table('media_song_artists')->where('artist_id', $sourceId)->delete();
        foreach (Db::table('media_album_artists')->where('artist_id', $sourceId)->get()->all() as $row) {
            Db::table('media_album_artists')->insertOrIgnore(['album_id' => $row->album_id,
                'artist_id' => $targetId, 'position' => $row->position]);
        }
        Db::table('media_album_artists')->where('artist_id', $sourceId)->delete();
        $this->mergePreferences('user_artist_preferences', 'artist_id', $sourceId, $targetId, $now);
        foreach (Db::table('media_artist_artworks')->where('artist_id', $sourceId)->get()->all() as $row) {
            if (Db::table('media_artist_artworks')->where('artist_id', $targetId)->where('library_id', $row->library_id)->exists()) {
                Db::table('media_artist_artworks')->where('artist_id', $sourceId)->where('library_id', $row->library_id)->delete();
            } else {
                Db::table('media_artist_artworks')->where('artist_id', $sourceId)->where('library_id', $row->library_id)
                    ->update(['artist_id' => $targetId, 'updated_at' => $now]);
            }
        }
        Db::table('media_artists')->where('id', $targetId)->update([
            'musicbrainz_artist_id' => $target->musicbrainz_artist_id ?? $source->musicbrainz_artist_id,
            'updated_at' => $now,
        ]);
        if (Db::table('media_artists')->where('id', $sourceId)->delete() !== 1) {
            throw new MetadataEntityConflict('来源艺术家已变化。');
        }
    }

    /** 应用同库专辑歌曲、署名、收藏、封面和聚合字段合并，然后删除来源专辑。 */
    private function mergeAlbum(string $sourceId, string $targetId, stdClass $source, stdClass $target, string $now): void
    {
        Db::table('media_songs')->where('album_id', $sourceId)->update(['album_id' => $targetId, 'updated_at' => $now]);
        foreach (Db::table('media_album_artists')->where('album_id', $sourceId)->get()->all() as $row) {
            Db::table('media_album_artists')->insertOrIgnore(['album_id' => $targetId,
                'artist_id' => $row->artist_id, 'position' => $row->position]);
        }
        Db::table('media_album_artists')->where('album_id', $sourceId)->delete();
        $this->mergePreferences('user_album_preferences', 'album_id', $sourceId, $targetId, $now);
        $sourceArtwork = Db::table('media_album_artworks')->where('album_id', $sourceId)->first();
        if ($sourceArtwork instanceof stdClass) {
            if (Db::table('media_album_artworks')->where('album_id', $targetId)->exists()) {
                Db::table('media_album_artworks')->where('album_id', $sourceId)->delete();
            } else {
                Db::table('media_album_artworks')->where('album_id', $sourceId)
                    ->update(['album_id' => $targetId, 'updated_at' => $now]);
            }
        }
        $aggregate = Db::table('media_songs')->where('album_id', $targetId)
            ->selectRaw('COUNT(*) AS song_count, COALESCE(SUM(duration_ms), 0) AS duration_ms')->first();
        Db::table('media_albums')->where('id', $targetId)->update([
            'release_date' => $target->release_date ?? $source->release_date,
            'release_year' => $target->release_year ?? $source->release_year,
            'disc_total' => $target->disc_total ?? $source->disc_total,
            'musicbrainz_release_id' => $target->musicbrainz_release_id ?? $source->musicbrainz_release_id,
            'musicbrainz_release_group_id' => $target->musicbrainz_release_group_id ?? $source->musicbrainz_release_group_id,
            'song_count' => (int) ($aggregate->song_count ?? 0), 'duration_ms' => (int) ($aggregate->duration_ms ?? 0),
            'updated_at' => $now,
        ]);
        if (Db::table('media_albums')->where('id', $sourceId)->delete() !== 1) {
            throw new MetadataEntityConflict('来源专辑已变化。');
        }
    }

    /**
     * 合并同一用户的收藏与评分，避免实体合并破坏个人偏好。
     *
     * 收藏采用并集并保留最早收藏时间；评分冲突时目标实体评分优先，目标未评分才继承来源评分及其时间，
     * 使重试结果稳定且不会让来源覆盖用户对目标的明确判断。来源行最终删除，合并后的行仍满足“至少有
     * 收藏或评分”稀疏约束。调用者已处于媒体实体短写事务，任一步失败会连同实体合并一起回滚。
     */
    private function mergePreferences(string $table, string $column, string $sourceId, string $targetId, string $now): void
    {
        foreach (Db::table($table)->where($column, $sourceId)->get()->all() as $row) {
            $target = Db::table($table)->where('user_id', $row->user_id)->where($column, $targetId)->first();
            if ($target instanceof stdClass) {
                $favorite = (bool) $row->is_favorite || (bool) $target->is_favorite;
                $times = array_filter([$row->favorited_at, $target->favorited_at], 'is_string');
                $rating = $target->rating === null
                    ? ($row->rating === null ? null : (int) $row->rating)
                    : (int) $target->rating;
                $ratedAt = $target->rating === null ? $row->rated_at : $target->rated_at;
                Db::table($table)->where('user_id', $row->user_id)->where($column, $targetId)->update([
                    'is_favorite' => $favorite ? 1 : 0,
                    'favorited_at' => $favorite ? ($times === [] ? $now : min($times)) : null,
                    'rating' => $rating,
                    'rated_at' => $rating === null ? null : (string) $ratedAt,
                    'updated_at' => $now,
                ]);
                Db::table($table)->where('user_id', $row->user_id)->where($column, $sourceId)->delete();
            } else {
                Db::table($table)->where('user_id', $row->user_id)->where($column, $sourceId)
                    ->update([$column => $targetId, 'updated_at' => $now]);
            }
        }
    }

    /**
     * 创建新艺术家并转移所选歌曲中属于来源艺术家的全部角色署名。
     *
     * 对每个受影响专辑，只有来源艺术家仍有未选歌曲时才保留原专辑署名并增加新署名；否则把来源专辑
     * 署名直接转给新艺术家。这样拆分后的专辑展示不会丢失贡献者，也不会因为一张专辑只拆出部分歌曲
     * 就错误移除原艺术家。新艺术家不继承外部 ID、收藏或封面。
     */
    private function splitArtist(stdClass $source, string $createdId, array $songIds, string $name, string $now): void
    {
        Db::table('media_artists')->insert([
            'id' => $createdId, 'name' => $name, 'normalized_name' => $this->artistNames->storageKey($name),
            'sort_name' => null, 'musicbrainz_artist_id' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::table('media_song_artists')->where('artist_id', (string) $source->id)->whereIn('song_id', $songIds)
            ->update(['artist_id' => $createdId]);
        $albumIds = $this->ids(Db::table('media_songs')->whereIn('id', $songIds), 'album_id');
        foreach ($albumIds as $albumId) {
            /** @var stdClass|null $albumCredit */
            $albumCredit = Db::table('media_album_artists')->where('album_id', $albumId)
                ->where('artist_id', (string) $source->id)->first();
            if (!$albumCredit instanceof stdClass) continue;
            $hasRemaining = Db::table('media_song_artists as credits')
                ->join('media_songs as songs', 'songs.id', '=', 'credits.song_id')
                ->where('credits.artist_id', (string) $source->id)->where('songs.album_id', $albumId)->exists();
            if ($hasRemaining) {
                Db::table('media_album_artists')->insertOrIgnore([
                    'album_id' => $albumId, 'artist_id' => $createdId, 'position' => (int) $albumCredit->position,
                ]);
            } else {
                Db::table('media_album_artists')->where('album_id', $albumId)
                    ->where('artist_id', (string) $source->id)->update(['artist_id' => $createdId]);
            }
        }
        Db::table('media_artists')->where('id', (string) $source->id)->update(['updated_at' => $now]);
    }

    /** 创建同库新专辑、移动所选歌曲、复制专辑署名并重新计算两张专辑的聚合。 */
    private function splitAlbum(stdClass $source, string $createdId, array $songIds, string $title, string $now): void
    {
        Db::table('media_albums')->insert([
            'id' => $createdId, 'library_id' => (string) $source->library_id,
            'identity_key' => 'manual-split:' . hash('sha256', $createdId . "\n" . $title),
            'identity_source_title' => $title,
            'title' => $title, 'normalized_title' => $this->normalizer->normalize($title), 'sort_title' => null,
            'release_date' => $source->release_date, 'release_year' => $source->release_year,
            'disc_total' => $source->disc_total, 'musicbrainz_release_id' => null,
            'musicbrainz_release_group_id' => null, 'song_count' => 0, 'duration_ms' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (Db::table('media_album_artists')->where('album_id', (string) $source->id)->get()->all() as $row) {
            Db::table('media_album_artists')->insert([
                'album_id' => $createdId, 'artist_id' => $row->artist_id, 'position' => $row->position,
            ]);
        }
        Db::table('media_songs')->whereIn('id', $songIds)->where('album_id', (string) $source->id)
            ->update(['album_id' => $createdId, 'updated_at' => $now]);
        $this->refreshAlbumStats((string) $source->id, $now);
        $this->refreshAlbumStats($createdId, $now);
    }

    /** 重新计算专辑派生计数；调用者必须已处于短写事务，方法不打开媒体文件。 */
    private function refreshAlbumStats(string $albumId, string $now): void
    {
        $aggregate = Db::table('media_songs')->where('album_id', $albumId)
            ->selectRaw('COUNT(*) AS song_count, COALESCE(SUM(duration_ms), 0) AS duration_ms')->first();
        Db::table('media_albums')->where('id', $albumId)->update([
            'song_count' => (int) ($aggregate->song_count ?? 0),
            'duration_ms' => (int) ($aggregate->duration_ms ?? 0),
            'updated_at' => $now,
        ]);
    }

    /**
     * 在事务内复验拆分命令，并返回规范排序的歌曲集合。
     *
     * 艺术家按实际歌曲署名判断归属，专辑按 album_id 判断。选择为空、超过 5000 首、包含重复/非法 ID、
     * 包含不属于来源的歌曲或会掏空来源实体均拒绝。名称按 UTF-8 字符计 1-200，规范名冲突在写入前
     * 转为稳定领域冲突，避免把 SQLite 唯一约束暴露到 HTTP。
     *
     * @return array{0:stdClass,1:list<string>}
     */
    private function validateSplit(array $actor, string $type, string $sourceId, array $songIds, string $newName): array
    {
        $this->type($type);
        if (!$this->ulid($sourceId) || trim($newName) === '' || mb_strlen(trim($newName)) > 200
            || $songIds === [] || count($songIds) > 5000) {
            throw new MetadataEntityInvalid('拆分实体、名称或歌曲集合无效。');
        }
        $selected = [];
        foreach ($songIds as $songId) {
            if (!is_string($songId) || !$this->ulid($songId) || isset($selected[$songId])) {
                throw new MetadataEntityInvalid('拆分歌曲集合无效。');
            }
            $selected[$songId] = true;
        }
        $selectedIds = array_keys($selected);
        sort($selectedIds);
        $source = $this->entity($type, $sourceId);
        $this->assertScope($actor, $type, [$sourceId], $source, null);
        $all = $this->sourceSongIds($type, $sourceId);
        if (count($selectedIds) >= count($all) || array_diff($selectedIds, $all) !== []) {
            throw new MetadataEntityConflict('只能拆分来源实体中的部分歌曲，来源至少保留一首。');
        }
        // 专辑允许同名发行版；其 identity_key 仍使用独立的手工拆分键。艺术家词汇则必须保持全局唯一。
        $collision = $type === 'artist'
            && Db::table('media_artists')->whereIn('normalized_name', $this->artistNames->lookupKeys($newName))->exists();
        if ($collision) throw new MetadataEntityConflict('同名艺术家或专辑已经存在，请改用合并或更换名称。');

        return [$source, $selectedIds];
    }

    /** 捕获拆分前会被修改的实体、字段覆盖、歌曲与署名关系；个人收藏和封面用于检测后续引用。 */
    private function captureSplitSnapshot(string $type, stdClass $source, array $songIds, array $impact): array
    {
        $albumIds = $this->ids(Db::table('media_songs')->whereIn('id', $songIds), 'album_id');
        $sourceId = (string) $source->id;
        $preferences = $type === 'artist'
            ? $this->rows(Db::table('user_artist_preferences')->where('artist_id', $sourceId))
            : $this->rows(Db::table('user_album_preferences')->where('album_id', $sourceId));
        $artworks = $type === 'artist'
            ? $this->rows(Db::table('media_artist_artworks')->where('artist_id', $sourceId))
            : $this->rows(Db::table('media_album_artworks')->where('album_id', $sourceId));
        return [
            'schemaVersion' => 1, 'entityType' => $type, 'operationType' => 'split',
            'source' => (array) $source, 'target' => [], 'createdEntityId' => null,
            'affectedSongIds' => $songIds, 'affectedAlbumIds' => $albumIds,
            'songs' => $this->rows(Db::table('media_songs')->whereIn('id', $songIds)->select(['id', 'album_id', 'updated_at'])),
            'songLinks' => $type === 'artist'
                ? $this->rows(Db::table('media_song_artists')->whereIn('song_id', $songIds)) : [],
            'albumLinks' => $albumIds === [] ? []
                : $this->rows(Db::table('media_album_artists')->whereIn('album_id', $albumIds)),
            'metadataStates' => $this->entityStateRows($type, [$sourceId]),
            'preferences' => $preferences, 'artworks' => $artworks, 'impact' => $impact,
        ];
    }

    /** 捕获合并会修改或因来源删除而级联移除的全部数据库关系。 */
    private function captureMergeSnapshot(
        string $type,
        string $sourceId,
        string $targetId,
        stdClass $source,
        stdClass $target,
        array $impact,
    ): array {
        $songIds = $this->sourceSongIds($type, $sourceId);
        $albumIds = $type === 'artist'
            ? $this->ids(Db::table('media_album_artists')->where('artist_id', $sourceId), 'album_id')
            : [$sourceId, $targetId];
        if ($type === 'artist') {
            $songLinks = $this->rows(Db::table('media_song_artists')->whereIn('song_id', $songIds));
            $albumLinks = $this->rows(Db::table('media_album_artists')->whereIn('album_id', $albumIds));
            $preferences = $this->rows(Db::table('user_artist_preferences')->whereIn('artist_id', [$sourceId, $targetId]));
            $artworks = $this->rows(Db::table('media_artist_artworks')->whereIn('artist_id', [$sourceId, $targetId]));
        } else {
            $songLinks = [];
            $albumLinks = $this->rows(Db::table('media_album_artists')->whereIn('album_id', [$sourceId, $targetId]));
            $preferences = $this->rows(Db::table('user_album_preferences')->whereIn('album_id', [$sourceId, $targetId]));
            $artworks = $this->rows(Db::table('media_album_artworks')->whereIn('album_id', [$sourceId, $targetId]));
        }
        return ['schemaVersion' => 1, 'entityType' => $type, 'operationType' => 'merge',
            'source' => (array) $source, 'target' => (array) $target, 'affectedSongIds' => $songIds,
            'affectedAlbumIds' => $albumIds, 'songLinks' => $songLinks, 'albumLinks' => $albumLinks,
            'songs' => $type === 'album' && $songIds !== []
                ? $this->rows(Db::table('media_songs')->whereIn('id', $songIds)->select(['id', 'album_id', 'updated_at'])) : [],
            'metadataStates' => $this->entityStateRows($type, [$sourceId, $targetId]),
            'preferences' => $preferences, 'artworks' => $artworks, 'impact' => $impact];
    }

    /** 以固定查询和排序重建执行后状态，用于检测任何可能被回滚覆盖的后续变化。 */
    private function stateHash(array $snapshot): string
    {
        $type = (string) ($snapshot['entityType'] ?? '');
        $sourceId = (string) ($snapshot['source']['id'] ?? '');
        $targetId = (string) ($snapshot['target']['id'] ?? '');
        $createdId = (string) ($snapshot['createdEntityId'] ?? '');
        $operationType = (string) ($snapshot['operationType'] ?? '');
        $songIds = is_array($snapshot['affectedSongIds'] ?? null) ? $snapshot['affectedSongIds'] : [];
        $albumIds = is_array($snapshot['affectedAlbumIds'] ?? null) ? $snapshot['affectedAlbumIds'] : [];
        $entityIds = $operationType === 'split' ? [$sourceId, $createdId] : [$sourceId, $targetId];
        $state = ['entities' => $type === 'artist'
            ? $this->rows(Db::table('media_artists')->whereIn('id', $entityIds))
            : $this->rows(Db::table('media_albums')->whereIn('id', $entityIds))];
        $state['metadataStates'] = $this->entityStateRows($type, $entityIds);
        if ($type === 'artist') {
            $relationArtistIds = $operationType === 'split' ? [$sourceId, $createdId] : [$sourceId, $targetId];
            $state['songLinks'] = $operationType === 'split'
                ? $this->rows(Db::table('media_song_artists')->whereIn('artist_id', $relationArtistIds))
                : ($songIds === [] ? [] : $this->rows(Db::table('media_song_artists')->whereIn('song_id', $songIds)));
            $state['albumLinks'] = $operationType === 'split'
                ? $this->rows(Db::table('media_album_artists')->whereIn('artist_id', $relationArtistIds))
                : ($albumIds === [] ? [] : $this->rows(Db::table('media_album_artists')->whereIn('album_id', $albumIds)));
            $state['preferences'] = $this->rows(Db::table('user_artist_preferences')->whereIn('artist_id', $relationArtistIds));
            $state['artworks'] = $this->rows(Db::table('media_artist_artworks')->whereIn('artist_id', $relationArtistIds));
        } else {
            $relationAlbumIds = $operationType === 'split' ? [$sourceId, $createdId] : [$sourceId, $targetId];
            $state['songs'] = $songIds === [] ? []
                : $this->rows(Db::table('media_songs')->whereIn('id', $songIds)->select(['id', 'album_id', 'updated_at']));
            $state['albumLinks'] = $this->rows(Db::table('media_album_artists')->whereIn('album_id', $relationAlbumIds));
            $state['preferences'] = $this->rows(Db::table('user_album_preferences')->whereIn('album_id', $relationAlbumIds));
            $state['artworks'] = $this->rows(Db::table('media_album_artworks')->whereIn('album_id', $relationAlbumIds));
        }
        return hash('sha256', json_encode($this->canonical($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** 恢复艺术家实体和全部受影响关系；调用前后置摘要已证明不存在后续变化。 */
    private function rollbackArtistMerge(array $snapshot): void
    {
        $source = $snapshot['source'];
        $target = $snapshot['target'];
        Db::table('media_artists')->insert($source);
        Db::table('media_artists')->where('id', $target['id'])->update(array_diff_key($target, ['id' => true]));
        $this->restoreEntityStates('artist', [$source['id'], $target['id']], $snapshot['metadataStates'] ?? []);
        $songIds = $snapshot['affectedSongIds'];
        if ($songIds !== []) Db::table('media_song_artists')->whereIn('song_id', $songIds)->delete();
        $albumIds = $snapshot['affectedAlbumIds'];
        if ($albumIds !== []) Db::table('media_album_artists')->whereIn('album_id', $albumIds)->delete();
        $this->insertRows('media_song_artists', $snapshot['songLinks']);
        $this->insertRows('media_album_artists', $snapshot['albumLinks']);
        Db::table('user_artist_preferences')->whereIn('artist_id', [$source['id'], $target['id']])->delete();
        $this->insertRows('user_artist_preferences', $snapshot['preferences']);
        Db::table('media_artist_artworks')->whereIn('artist_id', [$source['id'], $target['id']])->delete();
        $this->insertRows('media_artist_artworks', $snapshot['artworks']);
    }

    /** 恢复专辑实体、歌曲归属、署名、收藏和封面；歌曲稳定 ID 始终不变。 */
    private function rollbackAlbumMerge(array $snapshot): void
    {
        $source = $snapshot['source'];
        $target = $snapshot['target'];
        Db::table('media_albums')->insert($source);
        Db::table('media_albums')->where('id', $target['id'])->update(array_diff_key($target, ['id' => true]));
        $this->restoreEntityStates('album', [$source['id'], $target['id']], $snapshot['metadataStates'] ?? []);
        foreach ($snapshot['songs'] as $song) {
            Db::table('media_songs')->where('id', $song['id'])->update([
                'album_id' => $song['album_id'], 'updated_at' => $song['updated_at'],
            ]);
        }
        Db::table('media_album_artists')->whereIn('album_id', [$source['id'], $target['id']])->delete();
        $this->insertRows('media_album_artists', $snapshot['albumLinks']);
        Db::table('user_album_preferences')->whereIn('album_id', [$source['id'], $target['id']])->delete();
        $this->insertRows('user_album_preferences', $snapshot['preferences']);
        Db::table('media_album_artworks')->whereIn('album_id', [$source['id'], $target['id']])->delete();
        $this->insertRows('media_album_artworks', $snapshot['artworks']);
    }

    /** 回滚艺术家拆分，恢复来源行与受影响署名，再删除没有后续引用的新艺术家。 */
    private function rollbackArtistSplit(array $snapshot): void
    {
        $source = $snapshot['source'];
        $createdId = (string) $snapshot['createdEntityId'];
        Db::table('media_artists')->where('id', $source['id'])->update(array_diff_key($source, ['id' => true]));
        $this->restoreEntityStates('artist', [$source['id']], $snapshot['metadataStates'] ?? []);
        $songIds = $snapshot['affectedSongIds'];
        if ($songIds !== []) Db::table('media_song_artists')->whereIn('song_id', $songIds)->delete();
        $albumIds = $snapshot['affectedAlbumIds'];
        if ($albumIds !== []) Db::table('media_album_artists')->whereIn('album_id', $albumIds)->delete();
        $this->insertRows('media_song_artists', $snapshot['songLinks']);
        $this->insertRows('media_album_artists', $snapshot['albumLinks']);
        Db::table('user_artist_preferences')->whereIn('artist_id', [$source['id'], $createdId])->delete();
        $this->insertRows('user_artist_preferences', $snapshot['preferences']);
        Db::table('media_artist_artworks')->whereIn('artist_id', [$source['id'], $createdId])->delete();
        $this->insertRows('media_artist_artworks', $snapshot['artworks']);
        if (Db::table('media_artists')->where('id', $createdId)->delete() !== 1) {
            throw new MetadataEntityConflict('拆分创建的艺术家已变化。');
        }
    }

    /** 回滚专辑拆分，恢复歌曲时间与归属、专辑关系和来源聚合，再删除新专辑。 */
    private function rollbackAlbumSplit(array $snapshot): void
    {
        $source = $snapshot['source'];
        $createdId = (string) $snapshot['createdEntityId'];
        $this->restoreEntityStates('album', [$source['id']], $snapshot['metadataStates'] ?? []);
        foreach ($snapshot['songs'] as $song) {
            Db::table('media_songs')->where('id', $song['id'])->update([
                'album_id' => $song['album_id'], 'updated_at' => $song['updated_at'],
            ]);
        }
        Db::table('media_album_artists')->whereIn('album_id', [$source['id'], $createdId])->delete();
        $this->insertRows('media_album_artists', $snapshot['albumLinks']);
        Db::table('user_album_preferences')->whereIn('album_id', [$source['id'], $createdId])->delete();
        $this->insertRows('user_album_preferences', $snapshot['preferences']);
        Db::table('media_album_artworks')->whereIn('album_id', [$source['id'], $createdId])->delete();
        $this->insertRows('media_album_artworks', $snapshot['artworks']);
        if (Db::table('media_albums')->where('id', $createdId)->delete() !== 1) {
            throw new MetadataEntityConflict('拆分创建的专辑已变化。');
        }
        Db::table('media_albums')->where('id', $source['id'])->update(array_diff_key($source, ['id' => true]));
    }

    /** 计算来源对象会直接或间接影响的账号级关系数量，不返回具体用户或列表 ID。 */
    private function impact(string $type, string $sourceId): array
    {
        $songIds = $this->sourceSongIds($type, $sourceId);
        $favorites = $type === 'artist'
            ? Db::table('user_artist_preferences')->where('artist_id', $sourceId)->count()
            : Db::table('user_album_preferences')->where('album_id', $sourceId)->count();
        $playlists = $songIds === [] ? 0 : Db::table('playlist_items')->whereIn('song_id', $songIds)->distinct()->count('playlist_id');
        return ['songs' => count($songIds), 'favorites' => (int) $favorites, 'playlists' => (int) $playlists];
    }

    /** 统计稳定歌曲集合关联的播放列表，不读取账号或列表正文。 */
    private function songRelationImpact(array $songIds): array
    {
        if ($songIds === []) return ['playlists' => 0];
        return [
            'playlists' => (int) Db::table('playlist_items')->whereIn('song_id', $songIds)
                ->distinct()->count('playlist_id'),
        ];
    }

    /** 返回来源实体关联的稳定歌曲 ID；专辑直接按 album_id，艺术家按歌曲署名。 */
    private function sourceSongIds(string $type, string $sourceId): array
    {
        return $type === 'artist'
            ? $this->ids(Db::table('media_song_artists')->where('artist_id', $sourceId), 'song_id')
            : $this->ids(Db::table('media_songs')->where('album_id', $sourceId), 'id');
    }

    /** 读取固定类型实体完整行；不存在与非法 ID统一为不可枚举失败。 */
    private function entity(string $type, string $id): stdClass
    {
        if (!$this->ulid($id)) throw new MetadataEntityNotFound('元数据实体不存在。');
        $row = Db::table($type === 'artist' ? 'media_artists' : 'media_albums')->where('id', $id)->first();
        if (!$row instanceof stdClass) throw new MetadataEntityNotFound('元数据实体不存在。');
        return $row;
    }

    /** 复验来源/目标所有实际库均在当前 actor 的 manage 范围，专辑还必须同库。 */
    private function assertScope(array $actor, string $type, array $entityIds, ?stdClass $source, ?stdClass $target): void
    {
        $managed = array_keys($this->managedLibraries($actor));
        $required = [];
        foreach ($entityIds as $id) $required = array_merge($required, $this->entityLibraryIds($type, $id));
        $required = array_values(array_unique($required));
        if ($required === [] || array_diff($required, $managed) !== []) throw new MetadataEntityNotFound('元数据实体不存在。');
        if ($type === 'album' && $source instanceof stdClass && $target instanceof stdClass
            && (string) $source->library_id !== (string) $target->library_id) {
            throw new MetadataEntityConflict('只能合并同一音乐库内的专辑。');
        }
    }

    /** 查询实体真实使用库；艺术家同时覆盖歌曲署名和专辑署名，避免遗漏空专辑关系。 */
    private function entityLibraryIds(string $type, string $id): array
    {
        if ($type === 'album') {
            $library = Db::table('media_albums')->where('id', $id)->value('library_id');
            return is_string($library) ? [$library] : [];
        }
        $songs = Db::table('media_song_artists as credits')->join('media_songs as songs', 'songs.id', '=', 'credits.song_id')
            ->where('credits.artist_id', $id)->distinct()->pluck('songs.library_id')->all();
        $albums = Db::table('media_album_artists as credits')->join('media_albums as albums', 'albums.id', '=', 'credits.album_id')
            ->where('credits.artist_id', $id)->distinct()->pluck('albums.library_id')->all();
        return array_values(array_unique(array_map('strval', array_merge($songs, $albums))));
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

    /**
     * 判断操作是否完全位于当前操作者的实时 manage 范围。
     *
     * applied 合并的来源已被删除，因此以仍存在的目标判断；rolled_back 合并和 split 则合并所有仍存在
     * 的来源/目标实际库。艺术家的全部歌曲及专辑署名库都必须受管，任何空引用或失权都按不可见处理，
     * 防止操作历史泄露其他库对象的存在。
     */
    private function operationVisible(array $actor, stdClass $row): bool
    {
        $managed = array_keys($this->managedLibraries($actor));
        if ($managed === []) return false;
        $ids = [(string) $row->source_entity_id, (string) $row->target_entity_id];
        $required = [];
        foreach (array_unique($ids) as $id) {
            $required = array_merge($required, $this->entityLibraryIds((string) $row->entity_type, $id));
        }
        $required = array_values(array_unique($required));

        return $required !== [] && array_diff($required, $managed) === [];
    }

    /** 返回新拆分实体明确为空的固定外部 ID 投影，前端不需要猜测字段集合。 */
    private function emptyExternalIds(string $type): array
    {
        return $type === 'artist'
            ? ['musicbrainzArtistId' => null]
            : ['musicbrainzReleaseId' => null, 'musicbrainzReleaseGroupId' => null];
    }

    /** 将完整实体收敛为不含 identity key、路径或内部规范化值的预览。 */
    private function publicEntity(string $type, stdClass $row): array
    {
        return ['id' => (string) $row->id, 'name' => (string) ($type === 'artist' ? $row->name : $row->title),
            'libraryId' => $type === 'album' ? (string) $row->library_id : null,
            'externalIds' => $type === 'artist'
                ? ['musicbrainzArtistId' => $row->musicbrainz_artist_id === null ? null : (string) $row->musicbrainz_artist_id]
                : ['musicbrainzReleaseId' => $row->musicbrainz_release_id === null ? null : (string) $row->musicbrainz_release_id,
                    'musicbrainzReleaseGroupId' => $row->musicbrainz_release_group_id === null ? null : (string) $row->musicbrainz_release_group_id],
            'updatedAt' => (string) $row->updated_at];
    }

    /**
     * 映射列表聚合行；艺术家无单一 libraryId，专辑附带可读库名。
     *
     * `$artworkUrls` 已由同一次实体授权范围内的批量查询生成，映射阶段不访问文件系统，也不把候选摘要、
     * 库路径或图片字节放入列表响应。图片稍后读取失败时由图片端点返回不可用，不能因此放宽实体范围。
     *
     * @param array<string,string> $artworkUrls
     */
    private function mapEntityRow(string $type, stdClass $row, array $artworkUrls = []): array
    {
        return ['id' => (string) $row->id, 'type' => $type, 'name' => (string) $row->name,
            'songCount' => (int) $row->song_count,
            'library' => $type === 'album' ? ['id' => (string) $row->library_id, 'name' => (string) $row->library_name] : null,
            'imageUrl' => $artworkUrls[(string) $row->id] ?? null,
            'externalIds' => $type === 'artist' ? ['musicbrainzArtistId' => $row->musicbrainz_artist_id]
                : ['musicbrainzReleaseId' => $row->musicbrainz_release_id,
                    'musicbrainzReleaseGroupId' => $row->musicbrainz_release_group_id],
            'updatedAt' => (string) $row->updated_at];
    }

    /**
     * 为已完成 manage 范围裁剪的实体批量生成当前封面 URL。
     *
     * 前置条件：`$rows` 只能来自 `entities()` 上方已验证的活动库查询，`$managedIds` 是同一 actor 的实时
     * manage 范围。手工选择必须同时匹配候选的实体和库，异常外键或跨库记录不会进入投影；艺术家的
     * 本地图片也只接受管理范围内的库。手工选择优先于扫描来源，与实际封面解析顺序一致。这里不读取
     * BLOB 或文件，不缓存授权结果；最终 GET 仍由对应图片 Controller 复验权限和存储身份。
     *
     * 版本参数只由实体 ID 和更新时间生成短摘要，既能在重新扫描后使浏览器失效旧失败缓存，也不会暴露
     * 原始时间、内容哈希或服务器路径。旧迁移/测试结构缺少选择表时安全降级为扫描来源或 null。
     *
     * @param list<stdClass> $rows
     * @param list<string> $managedIds
     * @return array<string,string>
     */
    private function entityArtworkUrls(string $type, array $rows, array $managedIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (stdClass $row): string => (string) $row->id,
            $rows,
        )));
        if ($ids === []) return [];

        $result = [];
        $table = $type === 'album' ? 'media_album_artworks' : 'media_artist_artworks';
        $idColumn = $type . '_id';
        $automatic = Db::table($table)->whereIn($idColumn, $ids);
        if ($type === 'artist') $automatic->whereIn('library_id', $managedIds);
        foreach ($automatic->get([$idColumn, 'updated_at']) as $row) {
            $entityId = (string) $row->{$idColumn};
            if (isset($result[$entityId])) continue;
            $version = substr(hash('sha256', $entityId . "\0" . (string) $row->updated_at), 0, 16);
            $result[$entityId] = $type === 'album'
                ? '/api/v1/albums/' . rawurlencode($entityId) . '/cover?v=' . $version
                : '/api/v1/artists/' . rawurlencode($entityId) . '/image?v=' . $version;
        }

        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasTable('media_manual_artwork_candidates')
            || !$schema->hasColumn('media_artwork_selection_overrides', $idColumn)
            || !$schema->hasColumn('media_manual_artwork_candidates', $idColumn)) return $result;

        $selected = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join) use ($idColumn): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.' . $idColumn, '=', 'selections.' . $idColumn)
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->whereIn('selections.' . $idColumn, $ids)
            ->whereIn('selections.library_id', $managedIds)
            ->orderBy('selections.library_id')
            ->get(['selections.' . $idColumn . ' as entity_id', 'selections.candidate_id']);
        foreach ($selected as $row) {
            $entityId = (string) $row->entity_id;
            if (str_starts_with($result[$entityId] ?? '', '/api/v1/admin/artworks/candidates/')) continue;
            $result[$entityId] = '/api/v1/admin/artworks/candidates/'
                . rawurlencode((string) $row->candidate_id) . '/image';
        }

        return $result;
    }

    /** @return list<string> */
    private function ids(Builder $query, string $column): array
    {
        return array_values(array_unique(array_map('strval', $query->orderBy($column)->pluck($column)->all())));
    }

    /** @return list<array<string,mixed>> */
    private function rows(Builder $query): array
    {
        $rows = array_map(static fn (stdClass $row): array => (array) $row, $query->get()->all());
        usort($rows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
        ));
        return $rows;
    }

    /**
     * 返回固定实体类型的字段状态快照。
     *
     * 方法只接受服务内部白名单类型和已解析 ID；结果包含人工值、锁定、版本及更新时间，但只会进入
     * 服务器端加摘要的回滚快照，绝不通过 API 返回。后续任何字段编辑都会改变后置摘要并阻止旧回滚。
     *
     * @param list<string> $entityIds
     * @return list<array<string,mixed>>
     */
    private function entityStateRows(string $type, array $entityIds): array
    {
        if ($entityIds === []) return [];
        [$table, $column] = $this->entityStateStorage($type);
        return $this->rows(Db::table($table)->whereIn($column, $entityIds));
    }

    /**
     * 在实体行存在后恢复字段状态精确快照。
     *
     * 调用者已经通过后置摘要证明没有后续变化并处于 IMMEDIATE 事务。先删除指定实体当前状态再插入
     * 快照，确保 merge 级联删除与 split 后惰性初始化都可逆；任一步失败会让整个关系回滚事务撤销。
     *
     * @param list<string> $entityIds
     * @param list<array<string,mixed>> $rows
     */
    private function restoreEntityStates(string $type, array $entityIds, array $rows): void
    {
        [$table, $column] = $this->entityStateStorage($type);
        Db::table($table)->whereIn($column, $entityIds)->delete();
        $this->insertRows($table, $rows);
    }

    /** @return array{0:string,1:string} 返回固定表名和外键列，禁止快照内容参与 SQL 标识符。 */
    private function entityStateStorage(string $type): array
    {
        return $type === 'artist'
            ? ['media_artist_metadata_field_states', 'artist_id']
            : ['media_album_metadata_field_states', 'album_id'];
    }

    /** 仅把内部固定表的已验证快照重新插入；空列表不发 SQL。 */
    private function insertRows(string $table, array $rows): void
    {
        if ($rows !== []) Db::table($table)->insert($rows);
    }

    /** 递归排序关联键和列表，确保相同关系状态在 PHP/SQLite 返回顺序变化时摘要稳定。 */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
            usort($items, static fn (mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            ));
            return $items;
        }
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);
        return $value;
    }

    /** @return list<string> */
    private function operationColumns(): array
    {
        return ['id', 'entity_type', 'operation_type', 'source_entity_id', 'target_entity_id',
            'created_entity_id', 'status', 'version', 'affected_song_count', 'affected_favorite_count',
            'affected_playlist_count', 'created_at', 'rolled_back_at', 'updated_at'];
    }

    /** 将持久操作映射为可回滚状态，不暴露 actor、快照或摘要。 */
    private function mapOperation(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'entityType' => (string) $row->entity_type,
            'operationType' => (string) $row->operation_type, 'sourceId' => (string) $row->source_entity_id,
            'targetId' => (string) $row->target_entity_id,
            'createdEntityId' => $row->created_entity_id === null ? null : (string) $row->created_entity_id,
            'status' => (string) $row->status, 'version' => (int) $row->version,
            'impact' => ['songs' => (int) $row->affected_song_count,
                'favorites' => (int) $row->affected_favorite_count,
                'playlists' => (int) $row->affected_playlist_count],
            'canRollback' => (string) $row->status === 'applied', 'createdAt' => (string) $row->created_at,
            'rolledBackAt' => $row->rolled_back_at === null ? null : (string) $row->rolled_back_at,
            'updatedAt' => (string) $row->updated_at];
    }

    /** 合并输入只接受固定类型、两个不同 ULID。 */
    private function mergeInput(string $type, string $sourceId, string $targetId): void
    {
        $this->type($type);
        if (!$this->ulid($sourceId) || !$this->ulid($targetId) || $sourceId === $targetId) {
            throw new MetadataEntityInvalid('合并实体标识无效。');
        }
    }

    /** 固定实体类型白名单，禁止客户端把表名或多态类型带入查询。 */
    private function type(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new MetadataEntityInvalid('元数据实体类型无效。');
    }

    /** 从已认证 actor 提取 ULID；服务不会信任请求体中的操作者。 */
    private function actorId(array $actor): string
    {
        $id = $actor['id'] ?? null;
        if (!is_string($id) || !$this->ulid($id)) throw new MetadataEntityInvalid('操作者身份无效。');
        return $id;
    }

    private function ulid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    private function timestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;
    }

    /** 转义 LIKE 通配符；查询构造器继续使用绑定参数，不拼接 SQL。 */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
