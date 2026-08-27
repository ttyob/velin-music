<?php

declare(strict_types=1);

namespace app\application\Media;

use app\application\Metadata\MetadataEntityRedirectResolver;
use app\application\Search\SearchQueryInput;
use app\application\Search\SearchTextNormalizer;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * Provides path-free media projections after applying the current user's library grants.
 *
 * Every entry point independently joins active libraries, available inventory, and successful
 * metadata state. Ordinary users additionally require a current library_user_grants row; a global
 * `play` capability alone never expands object scope. No method in this public service returns an
 * inventory ID, relative path, resolved path, device, inode, or probe error detail.
 */
final class MediaQueryService
{
    /**
     * 创建路径无关媒体查询服务，并选择是否加载 OpenSubsonic 个人扩展事实。
     *
     * Web、后台和领域服务默认关闭，避免普通目录页为 `played` 与专辑艺人承担额外批量查询；Subsonic
     * 目录适配器显式开启后，所有结果仍先完成实时媒体授权，再加载当前账号播放时间与专辑署名。该
     * 开关不改变可见范围、不缓存身份，也不产生播放计次或数据库写入。
     */
    public function __construct(private readonly bool $includeOpenSubsonicFacts = false)
    {
    }

    /**
     * Proves that one media object is currently visible to the authenticated principal.
     *
     * The proof uses the same active-library, available-file, successful-metadata, and live-grant
     * scope as catalog browsing. Invalid IDs and unsupported types return false so callers can map
     * missing and unauthorized objects to one indistinguishable response. No physical path or
     * global vocabulary existence is exposed as a side effect.
     *
     * @param array<string, mixed> $actor Authenticated principal with the play capability.
     */
    public function mediaExists(array $actor, string $type, string $mediaId): bool
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $mediaId) !== 1) {
            return false;
        }

        return match ($type) {
            'songs' => $this->scopedSongQuery($actor)->where(
                'songs.id',
                (new SongDuplicateRedirectResolver())->resolve($mediaId),
            )->exists(),
            'albums' => $this->scopedAlbumQuery($actor)->where('albums.id', $mediaId)->exists(),
            'artists' => $this->scopedArtistQuery($actor)->where('artists.id', $mediaId)->exists(),
            default => false,
        };
    }

    /**
     * Adds live song visibility to a personal-data query without changing its selected columns.
     *
     * The outer query must expose a trusted server-owned song column such as
     * `playback_sessions.song_id`; callers must never pass request input as SQL structure. EXISTS
     * reuses active-library, available-file, successful-metadata, and current-grant invariants while
     * avoiding row multiplication. This method only removes rows and never grants media access.
     *
     * @param array<string, mixed> $actor Authenticated principal whose current grants apply.
     */
    public function constrainToVisibleSongs(Builder $query, array $actor, string $outerSongColumn): void
    {
        $query->whereExists(function (Builder $songs) use ($actor, $outerSongColumn): void {
            $songs->selectRaw('1')->from('media_songs as visibility_songs')
                ->join('library_file_inventory as visibility_files', 'visibility_files.id', '=', 'visibility_songs.inventory_file_id')
                ->join('music_libraries as visibility_libraries', 'visibility_libraries.id', '=', 'visibility_songs.library_id')
                ->whereColumn('visibility_songs.id', $outerSongColumn)
                ->where('visibility_files.status', 'available')
                ->where('visibility_files.metadata_status', 'ready')
                ->where('visibility_libraries.status', 'active');
            (new SongDuplicateRedirectResolver())->excludeActiveSources($songs, 'visibility_songs');
            if (!($actor['isSuperAdmin'] ?? false)) {
                $songs->join('library_user_grants as visibility_grant', function ($join) use ($actor): void {
                    $join->on('visibility_grant.library_id', '=', 'visibility_songs.library_id')
                        ->where('visibility_grant.user_id', '=', (string) $actor['id']);
                });
            }
        });
    }

    /**
     * Lists songs in stable title/ULID order with bounded offset pagination.
     *
     * Offset pagination is intentionally limited to the first 10,000 rows for this initial Web
     * slice. The public contract can add a title+ULID cursor without changing media IDs.
     *
     * @param array<string, mixed> $actor Authenticated principal with the global play capability.
     * @return array{songs: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function songs(
        array $actor,
        ?string $libraryId,
        int $limit,
        int $offset,
        ?string $genreId = null,
        bool $favoritesOnly = false,
        int|string|null $releaseYear = null,
    ): array
    {
        [$limit, $offset] = $this->bounds($limit, $offset);
        $query = $this->scopedSongQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        $this->filterGenre($query, $genreId);
        $this->filterReleaseYear($query, $releaseYear, 'songs.release_year');
        $this->filterFavorites($query, $actor, 'songs', 'songs.id', $favoritesOnly);
        $total = (clone $query)->count('songs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.title')->orderBy('songs.id')->offset($offset)->limit($limit)
            ->get($this->songColumns())->all();
        $artists = $this->songArtists(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
        $albumArtists = $this->includeOpenSubsonicFacts
            ? $this->albumArtists(array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->album_id,
                $rows,
            ))))
            : [];
        $covers = $this->songArtworkVersions($rows);
        $preferences = $this->preferenceMap($actor, 'songs', array_map(static fn (stdClass $row): string => (string) $row->id, $rows));

        return [
            'songs' => array_map(
                fn (stdClass $row): array => $this->mapSong($row, $artists, $albumArtists, $covers, $preferences),
                $rows,
            ),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Resolves a bounded ID set to authorized, currently playable path-free song projections.
     *
     * Missing, unavailable, disabled-library, and out-of-grant IDs are all omitted. The caller must
     * compare the returned keys with its requested unique IDs when every item is mandatory. Results
     * are keyed by song ID rather than request order so duplicate queue entries can reuse one safe
     * projection without duplicate metadata queries.
     *
     * @param array<string, mixed> $actor Authenticated principal with the global play capability.
     * @param list<string> $songIds At most 500 syntactically validated opaque IDs.
     * @return array<string, array<string, mixed>> Authorized projections keyed by song ID.
     */
    public function songsByIds(array $actor, array $songIds): array
    {
        $songIds = array_values(array_unique($songIds));
        if ($songIds === [] || count($songIds) > 500) {
            return [];
        }
        foreach ($songIds as $songId) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
                return [];
            }
        }

        $resolver = new SongDuplicateRedirectResolver();
        $resolvedByRequest = [];
        foreach ($songIds as $songId) $resolvedByRequest[$songId] = $resolver->resolve($songId);
        $resolvedIds = array_values(array_unique(array_values($resolvedByRequest)));

        /** @var list<stdClass> $rows */
        $rows = $this->scopedSongQuery($actor)->whereIn('songs.id', $resolvedIds)
            ->get($this->songColumns())->all();
        $artists = $this->songArtists(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
        $albumArtists = $this->includeOpenSubsonicFacts
            ? $this->albumArtists(array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->album_id,
                $rows,
            ))))
            : [];
        $covers = $this->songArtworkVersions($rows);
        $preferences = $this->preferenceMap($actor, 'songs', array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
        $canonical = [];
        foreach ($rows as $row) {
            $canonical[(string) $row->id] = $this->mapSong(
                $row,
                $artists,
                $albumArtists,
                $covers,
                $preferences,
            );
        }
        $result = [];
        foreach ($resolvedByRequest as $requestedId => $resolvedId) {
            if (isset($canonical[$resolvedId])) $result[$requestedId] = $canonical[$resolvedId];
        }

        return $result;
    }

    /**
     * 在当前账号的可播放范围内查询歌单条目的候选歌曲 ID。
     *
     * 标题和艺人参数只作为已经约束长度的显示文本，查询前统一使用索引时相同的可移植规范化规则；
     * 调用方可以传入原文与内存中的繁简变体，但本方法不会做包含、拼音或模糊相似匹配。查询从
     * `scopedSongQuery()` 开始，因此活动音乐库、可用文件、ready 元数据、实时授权和重复媒体重定向
     * 排除始终同时生效，不能因为歌单来源是公开的就扩大后台管理员之外的媒体范围。
     *
     * 返回多个 ID 时表示同一身份在授权目录中仍有歧义；调用方必须把它保留为 ambiguous，不能使用
     * 稳定排序偷偷选择一首。方法只读，不写入歌单、匹配日志或任何媒体元数据。
     *
     * @param array<string,mixed> $actor 当前主体及实时音乐库授权。
     * @param list<string> $titles 外部条目的完整标题候选，至少一个非空值。
     * @param list<string> $artists 外部条目的艺人候选，至少一个非空值。
     * @return list<string> 已授权歌曲 ID，按稳定 ID 顺序去重。
     */
    public function songIdsByPlaylistIdentity(array $actor, array $titles, array $artists): array
    {
        $normalizer = new SearchTextNormalizer();
        $normalize = static function (array $values) use ($normalizer): array {
            $keys = [];
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > 500) continue;
                $key = $normalizer->normalize($value);
                if ($key !== '') $keys[] = $key;
            }
            return array_values(array_unique($keys));
        };
        $titleKeys = $normalize($titles);
        $artistKeys = $normalize($artists);
        if ($titleKeys === [] || $artistKeys === [] || count($titleKeys) > 16 || count($artistKeys) > 32) return [];

        $query = $this->scopedSongQuery($actor)->whereIn('songs.normalized_title', $titleKeys)
            ->whereExists(function (Builder $artistQuery) use ($artistKeys): void {
                $artistQuery->selectRaw('1')->from('media_song_artists as playlist_song_artists')
                    ->join('media_artists as playlist_artists', 'playlist_artists.id', '=', 'playlist_song_artists.artist_id')
                    ->whereColumn('playlist_song_artists.song_id', 'songs.id')
                    ->whereIn('playlist_artists.normalized_name', $artistKeys);
            });

        /** @var list<stdClass> $rows */
        $rows = $query->distinct()->orderBy('songs.id')->get(['songs.id'])->all();
        return array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
    }

    /**
     * Resolves the first authorized song matching legacy artist/title text exactly.
     *
     * Subsonic `getLyrics` predates opaque song IDs and makes both fields optional. This query keeps
     * that compatibility search inside the normal active-library, available-file, ready-metadata,
     * and live-grant scope. Supplied values must already be trimmed and bounded by the protocol
     * adapter; they are normalized with the same portable algorithm used while indexing. Artist
     * matching accepts any directly credited song artist but deliberately does not perform fuzzy or
     * concatenated-display matching, because returning lyrics for a merely similar song is worse
     * than a successful empty legacy response. Multiple exact matches use stable song-ID order.
     *
     * The method is read-only, does not record the query, and returns a path-free projection or null.
     * An absent artist and title has no search meaning and returns null without querying the catalog.
     *
     * @param array<string, mixed> $actor Authenticated principal whose live library scope is applied.
     * @return array<string, mixed>|null Authorization-safe song summary for lyrics lookup.
     */
    public function firstSongByExactArtistAndTitle(
        array $actor,
        ?string $artist,
        ?string $title,
    ): ?array {
        if ($artist === null && $title === null) {
            return null;
        }

        $normalizer = new SearchTextNormalizer();
        $query = $this->scopedSongQuery($actor);
        if ($title !== null) {
            $query->where('songs.normalized_title', $normalizer->normalize($title));
        }
        if ($artist !== null) {
            $normalizedArtist = $normalizer->normalize($artist);
            $query->whereExists(function (Builder $artists) use ($normalizedArtist): void {
                $artists->selectRaw('1')
                    ->from('media_song_artists as legacy_lyric_song_artists')
                    ->join(
                        'media_artists as legacy_lyric_artists',
                        'legacy_lyric_artists.id',
                        '=',
                        'legacy_lyric_song_artists.artist_id',
                    )
                    ->whereColumn('legacy_lyric_song_artists.song_id', 'songs.id')
                    ->where('legacy_lyric_artists.normalized_name', $normalizedArtist);
            });
        }

        /** @var stdClass|null $row */
        $row = $query->orderBy('songs.id')->first($this->songColumns());
        if (!$row instanceof stdClass) {
            return null;
        }

        return $this->mapSongRows($actor, [$row])[0] ?? null;
    }

    /**
     * 返回至少含一首当前可用、已解析且已授权歌曲的专辑。
     *
     * 年份筛选必须使用 `songs.release_year`：`years()` 的聚合和年份歌曲列表都以
     * 歌曲年份为事实，扫描或刮削后专辑头的 `albums.release_year` 可能仍为 null。
     * 若此处改用专辑头筛选，就会出现“年份聚合声称有专辑，展开却为空”的
     * 半份快照。当一张专辑同时包含多个年份的可见歌曲时，它可合法出现在多个
     * 年份页；`distinct` 保证在单个年份页内只返回一次。
     *
     * 查询只读且无缓存副作用；非法年份令牌仍由 `filterReleaseYear()` 失败关闭为空范围。
     * SQLite 与未来 MySQL 都使用已连接歌曲列的等值/NULL 条件，不依赖方言函数。
     *
     * @param array<string, mixed> $actor 已通过 play 能力检查且携带实时音乐库授权的主体。
     * @return array{albums: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function albums(
        array $actor,
        ?string $libraryId,
        int $limit,
        int $offset,
        bool $favoritesOnly = false,
        int|string|null $releaseYear = null,
    ): array
    {
        [$limit, $offset] = $this->bounds($limit, $offset);
        $query = $this->scopedAlbumQuery($actor);
        $this->filterLibrary($query, $libraryId, 'albums.library_id');
        $this->filterReleaseYear($query, $releaseYear, 'songs.release_year');
        $this->filterFavorites($query, $actor, 'albums', 'albums.id', $favoritesOnly);
        $total = (clone $query)->distinct()->count('albums.id');
        /** @var list<stdClass> $rows */
        $rows = $query->distinct()->orderBy('albums.title')->orderBy('albums.id')
            ->offset($offset)->limit($limit)->get($this->albumColumns())->all();
        return [
            'albums' => $this->mapAlbumRows($actor, $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns newly indexed albums after applying the same live authorization scope as browsing.
     *
     * Album creation time represents when the album first entered Velin's catalog, not its release
     * date. A rescan may update metadata without moving an old album to the front. Ordering uses ID
     * as a deterministic tie-breaker, and the result never contains an album without a currently
     * available authorized song.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return list<array<string, mixed>> Path-free album summaries, newest first.
     */
    public function recentlyAddedAlbums(array $actor, int $limit): array
    {
        $limit = max(1, min(24, $limit));
        /** @var list<stdClass> $rows */
        $rows = $this->scopedAlbumQuery($actor)->distinct()
            ->orderByDesc('albums.created_at')->orderByDesc('albums.id')->limit($limit)
            ->get($this->albumColumns())->all();

        return $this->mapAlbumRows($actor, $rows);
    }

    /**
     * Selects a rotating album window without relying on database-specific random functions.
     *
     * The start offset is derived from the authenticated user, UTC day, and visible album count.
     * This gives a stable daily selection (avoiding layout churn on every refresh) while remaining
     * portable to MySQL. If the window reaches the end it wraps once to the beginning. Revoked or
     * unavailable albums are removed by both count and fetch scopes before any projection is made.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return list<array<string, mixed>> At most the requested number of authorized album summaries.
     */
    public function randomAlbums(array $actor, int $limit): array
    {
        $limit = max(1, min(24, $limit));
        $base = $this->scopedAlbumQuery($actor);
        $total = (clone $base)->distinct()->count('albums.id');
        if ($total === 0) {
            return [];
        }
        $target = min($limit, $total);

        $seed = (string) ($actor['id'] ?? '') . '|' . gmdate('Y-m-d') . '|' . $total;
        $offset = (int) (sprintf('%u', crc32($seed)) % $total);
        /** @var list<stdClass> $rows */
        $rows = (clone $base)->distinct()->orderBy('albums.id')->offset($offset)->limit($target)
            ->get($this->albumColumns())->all();
        if (count($rows) < $target) {
            /** @var list<stdClass> $wrapped */
            $wrapped = (clone $base)->distinct()->orderBy('albums.id')->limit($target - count($rows))
                ->get($this->albumColumns())->all();
            $rows = array_merge($rows, $wrapped);
        }

        return $this->mapAlbumRows($actor, $rows);
    }

    /**
     * Selects a stable rotating song window inside live authorization and optional discovery filters.
     *
     * This query powers compatibility clients that request random songs with up to 500 results.
     * SQLite/MySQL random functions are deliberately avoided: a user/day/filter seed chooses an
     * offset in stable song-ID order and wraps once. The result changes daily, remains repeatable
     * during pagination/retries, and never loads the full catalog into PHP. Genre matching uses the
     * indexed normalized genre name and only the same visible outer song row; year bounds exclude
     * unknown years when either bound is supplied.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return list<array<string, mixed>> Path-free authorized song summaries.
     */
    public function randomSongs(
        array $actor,
        ?string $libraryId,
        int $size,
        ?string $genre,
        ?int $fromYear,
        ?int $toYear,
    ): array {
        $size = max(1, min(500, $size));
        $query = $this->scopedSongQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        $this->filterGenreName($query, $genre, 'songs.id');
        $this->filterYearBounds($query, $fromYear, $toYear, 'songs.release_year');
        $total = (clone $query)->count('songs.id');
        if ($total === 0) {
            return [];
        }
        $target = min($size, $total);
        $seed = implode('|', [
            (string) ($actor['id'] ?? ''), gmdate('Y-m-d'), (string) $libraryId,
            (string) $genre, (string) $fromYear, (string) $toYear, (string) $total,
        ]);
        $offset = (int) (sprintf('%u', crc32($seed)) % $total);
        /** @var list<stdClass> $rows */
        $rows = (clone $query)->orderBy('songs.id')->offset($offset)->limit($target)
            ->get($this->songColumns())->all();
        if (count($rows) < $target) {
            /** @var list<stdClass> $wrapped */
            $wrapped = (clone $query)->orderBy('songs.id')->limit($target - count($rows))
                ->get($this->songColumns())->all();
            $rows = array_merge($rows, $wrapped);
        }

        return $this->mapSongRows($actor, $rows);
    }

    /**
     * 返回当前账号可见曲库中的稳定随机歌曲分页。
     *
     * 调用方必须先完成认证、`play` 能力和严格分页校验。本方法从活动音乐库、可用文件、元数据成功
     * 及实时 library grant 的共同范围计算 total，再以账号、UTC 日期和当前总数生成起点，在歌曲 ID
     * 稳定序上循环读取。这样同一天同一授权快照下重复请求结果稳定、相邻页面不会重复，也不依赖
     * SQLite `RANDOM()`，未来迁移 MySQL 时只需保留同一查询边界。若授权或目录在翻页期间变化，
     * 新请求立即按新范围计算，不为维持旧随机页而泄露撤权歌曲。查询只读，不记录播放、不旋转持久
     * 状态，也不触发扫描。
     *
     * @param array<string, mixed> $actor 已认证且具备播放能力的当前账号。
     * @return array{songs: list<array<string, mixed>>, page: int, pageSize: int, total: int, hasMore: bool}
     */
    public function randomRecommendationSongs(array $actor, int $page, int $pageSize): array
    {
        $page = max(1, min(10_000, $page));
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $query = $this->scopedSongQuery($actor);
        $total = (clone $query)->count('songs.id');
        $target = $offset >= $total ? 0 : min($pageSize, $total - $offset);
        $seed = implode('|', [
            (string) ($actor['id'] ?? ''),
            gmdate('Y-m-d'),
            'random-recommendations',
            (string) $total,
        ]);
        $rows = $this->rotatingSongRows($query, $seed, $total, $offset, $target);

        return [
            'songs' => $this->mapSongRows($actor, $rows),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'hasMore' => $offset + count($rows) < $total,
        ];
    }

    /**
     * Pages songs by exact normalized genre name under the same live song scope as playback.
     *
     * The genre name comes from Subsonic's getGenres value rather than an internal genre ID. Exact
     * normalized matching keeps punctuation meaningful and avoids treating `%`/`_` as wildcards.
     * Unknown and unauthorized genres both produce an empty page.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return list<array<string, mixed>> At most 500 authorized songs in stable title/ID order.
     */
    public function songsByGenreName(
        array $actor,
        string $genre,
        ?string $libraryId,
        int $count,
        int $offset,
    ): array {
        $count = max(1, min(500, $count));
        $offset = max(0, min(10_000, $offset));
        $query = $this->scopedSongQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        $this->filterGenreName($query, $genre, 'songs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.title')->orderBy('songs.id')->offset($offset)->limit($count)
            ->get($this->songColumns())->all();

        return $this->mapSongRows($actor, $rows);
    }

    /**
     * 按完整 Subsonic AlbumList2 类型返回当前账号可见专辑。
     *
     * `highest` 只选择当前用户已有 1..5 分评分的专辑，并按评分、评分时间、标题和稳定 ID 排序；其他
     * 用户评分和未评分专辑不能进入或影响结果。收藏、评分和播放统计都只能过滤或排序已经通过活动库、
     * 可用歌曲与实时 grant 证明的专辑，绝不授予可见性。相关子查询避免放大共享可见歌曲连接，保持
     * SQLite 与未来 MySQL 的可替换边界；random 使用确定性轮转，其余类型遵守有界 offset 分页且只读。
     *
     * @param array<string, mixed> $actor 已认证主体，也是个人排序数据的所有者。
     * @return list<array<string, mixed>> 不含路径且已经授权的专辑摘要。
     */
    public function discoverAlbums(
        array $actor,
        string $type,
        ?string $libraryId,
        int $size,
        int $offset,
        ?int $fromYear,
        ?int $toYear,
        ?string $genre,
    ): array {
        $size = max(1, min(500, $size));
        $offset = max(0, min(10_000, $offset));
        $query = $this->scopedAlbumQuery($actor);
        $this->filterLibrary($query, $libraryId, 'albums.library_id');
        $userId = (string) ($actor['id'] ?? '');

        if ($type === 'starred') {
            $this->filterFavorites($query, $actor, 'albums', 'albums.id', true);
        } elseif ($type === 'highest') {
            $query->whereExists(function (Builder $ratings) use ($userId): void {
                $ratings->selectRaw('1')->from('user_album_preferences as selected_ratings')
                    ->whereColumn('selected_ratings.album_id', 'albums.id')
                    ->where('selected_ratings.user_id', $userId)
                    ->whereNotNull('selected_ratings.rating');
            });
        } elseif ($type === 'byYear') {
            $this->filterYearBounds($query, $fromYear, $toYear, 'albums.release_year');
        } elseif ($type === 'byGenre') {
            $this->filterGenreName($query, $genre, 'songs.id');
        } elseif (in_array($type, ['frequent', 'recent'], true)) {
            $query->whereExists(function (Builder $stats) use ($userId): void {
                $stats->selectRaw('1')->from('user_song_play_stats as selected_stats')
                    ->join('media_songs as selected_stat_songs', 'selected_stat_songs.id', '=', 'selected_stats.song_id')
                    ->whereColumn('selected_stat_songs.album_id', 'albums.id')
                    ->where('selected_stats.user_id', $userId)
                    ->where('selected_stats.play_count', '>', 0);
            });
        }

        $query->distinct();
        $total = (clone $query)->count('albums.id');
        if ($type === 'random') {
            if ($total === 0) {
                return [];
            }
            $target = min($size, $total);
            $seed = implode('|', [$userId, gmdate('Y-m-d'), (string) $libraryId, (string) $total]);
            $start = ((int) (sprintf('%u', crc32($seed)) % $total) + $offset) % $total;
            /** @var list<stdClass> $rows */
            $rows = (clone $query)->orderBy('albums.id')->offset($start)->limit($target)
                ->get($this->albumColumns())->all();
            if (count($rows) < $target) {
                /** @var list<stdClass> $wrapped */
                $wrapped = (clone $query)->orderBy('albums.id')->limit($target - count($rows))
                    ->get($this->albumColumns())->all();
                $rows = array_merge($rows, $wrapped);
            }

            return $this->mapAlbumRows($actor, $rows);
        }

        match ($type) {
            'newest' => $query->orderByDesc('albums.created_at')->orderByDesc('albums.id'),
            'frequent' => $query->orderByRaw(
                '(SELECT COALESCE(SUM(frequent_stats.play_count), 0) FROM user_song_play_stats AS frequent_stats'
                . ' JOIN media_songs AS frequent_songs ON frequent_songs.id = frequent_stats.song_id'
                . ' WHERE frequent_songs.album_id = albums.id AND frequent_stats.user_id = ?) DESC',
                [$userId],
            )->orderBy('albums.title')->orderBy('albums.id'),
            'recent' => $query->orderByRaw(
                '(SELECT MAX(recent_stats.last_played_at) FROM user_song_play_stats AS recent_stats'
                . ' JOIN media_songs AS recent_songs ON recent_songs.id = recent_stats.song_id'
                . ' WHERE recent_songs.album_id = albums.id AND recent_stats.user_id = ?) DESC',
                [$userId],
            )->orderBy('albums.title')->orderBy('albums.id'),
            'highest' => $query->orderByRaw(
                '(SELECT rated_preferences.rating FROM user_album_preferences AS rated_preferences'
                . ' WHERE rated_preferences.album_id = albums.id AND rated_preferences.user_id = ?) DESC',
                [$userId],
            )->orderByRaw(
                '(SELECT rated_preferences.rated_at FROM user_album_preferences AS rated_preferences'
                . ' WHERE rated_preferences.album_id = albums.id AND rated_preferences.user_id = ?) DESC',
                [$userId],
            )->orderBy('albums.title')->orderBy('albums.id'),
            'alphabeticalByArtist' => $query->orderByRaw(
                '(SELECT MIN(COALESCE(sort_artists.sort_name, sort_artists.name))'
                . ' FROM media_album_artists AS sort_links'
                . ' JOIN media_artists AS sort_artists ON sort_artists.id = sort_links.artist_id'
                . ' WHERE sort_links.album_id = albums.id)',
            )->orderBy('albums.title')->orderBy('albums.id'),
            'byYear' => ($fromYear ?? 0) > ($toYear ?? 0)
                ? $query->orderByDesc('albums.release_year')->orderBy('albums.title')->orderBy('albums.id')
                : $query->orderBy('albums.release_year')->orderBy('albums.title')->orderBy('albums.id'),
            'starred' => $query->orderByRaw(
                '(SELECT favorite_preferences.favorited_at FROM user_album_preferences AS favorite_preferences'
                . ' WHERE favorite_preferences.album_id = albums.id AND favorite_preferences.user_id = ?) DESC',
                [$userId],
            )->orderBy('albums.title')->orderBy('albums.id'),
            default => $query->orderBy('albums.title')->orderBy('albums.id'),
        };
        /** @var list<stdClass> $rows */
        $rows = $query->offset($offset)->limit($size)->get($this->albumColumns())->all();

        return $this->mapAlbumRows($actor, $rows);
    }

    /**
     * Returns the current user's most-played visible songs in aggregate-count order.
     *
     * Personal statistics never grant catalog access: the stats query is first constrained through
     * the current active library/file/grant scope, then song summaries are resolved again through
     * songsByIds. Cleared history remains counted by design, matching playback statistics semantics.
     *
     * @param array<string, mixed> $actor Authenticated owner whose personal counts are queried.
     * @return list<array{song: array<string, mixed>, playCount: int, lastPlayedAt: string|null}>
     */
    public function frequentlyPlayedSongs(array $actor, int $limit): array
    {
        $limit = max(1, min(24, $limit));
        return $this->frequentlyPlayedSongPage($actor, 1, $limit)['songs'];
    }

    /**
     * 按当前账号累计播放次数倒序返回仍可见的歌曲分页。
     *
     * 播放统计只决定候选与排序，不能授予歌曲访问权；total 和当前页在同一活动库、可用文件、元数据
     * 成功及实时 library grant 范围内分别计算，因此撤权、停用或失效歌曲不会占用页位，也不会通过
     * 总数泄露。次数相同时依次按最后播放时间倒序和歌曲 ID 排序，保证分页稳定。清除播放历史不减少
     * 累计统计，符合现有统计契约。本方法只读，不修改统计、历史、队列或媒体文件。
     *
     * @param array<string, mixed> $actor 已认证且具备播放能力的统计所有者。
     * @return array{songs: list<array{song: array<string, mixed>, playCount: int, lastPlayedAt: string|null}>, page: int, pageSize: int, total: int, hasMore: bool}
     */
    public function frequentlyPlayedSongPage(array $actor, int $page, int $pageSize): array
    {
        $page = max(1, min(10_000, $page));
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $query = Db::table('user_song_play_stats as stats')
            ->where('stats.user_id', (string) ($actor['id'] ?? ''))
            ->where('stats.play_count', '>', 0);
        $this->constrainToVisibleSongs($query, $actor, 'stats.song_id');
        $total = (clone $query)->count('stats.song_id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('stats.play_count')->orderByDesc('stats.last_played_at')
            ->orderBy('stats.song_id')->offset($offset)->limit($pageSize)
            ->get(['stats.song_id', 'stats.play_count', 'stats.last_played_at'])->all();
        $ids = array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows);
        $songs = $this->songsByIds($actor, $ids);
        $result = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (isset($songs[$songId])) {
                $result[] = [
                    'song' => $songs[$songId],
                    'playCount' => (int) $row->play_count,
                    'lastPlayedAt' => $row->last_played_at === null ? null : (string) $row->last_played_at,
                ];
            }
        }

        return [
            'songs' => $result,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'hasMore' => $offset + count($result) < $total,
        ];
    }

    /**
     * 从稳定 ID 序列读取一个以种子起点旋转的逻辑分页，必要时只回绕一次。
     *
     * offset 是旋转后逻辑序列中的位置；调用方必须保证 `offset + limit <= total`，从而相邻页面不会
     * 因回绕重复歌曲。查询结构和种子均由服务端生成，用户输入只以已校验整数参与 offset/limit，且
     * 本方法不加载整个 ID 集合到 PHP 内存。空目录、尾部空页或零长度请求直接返回空列表。
     *
     * @return list<stdClass> 已通过 scopedSongQuery 授权且保持旋转顺序的歌曲行。
     */
    private function rotatingSongRows(
        Builder $query,
        string $seed,
        int $total,
        int $offset,
        int $limit,
    ): array {
        if ($total === 0 || $limit === 0 || $offset >= $total) {
            return [];
        }
        $start = ((int) (sprintf('%u', crc32($seed)) % $total) + $offset) % $total;
        /** @var list<stdClass> $rows */
        $rows = (clone $query)->orderBy('songs.id')->offset($start)->limit($limit)
            ->get($this->songColumns())->all();
        if (count($rows) < $limit) {
            /** @var list<stdClass> $wrapped */
            $wrapped = (clone $query)->orderBy('songs.id')->limit($limit - count($rows))
                ->get($this->songColumns())->all();
            $rows = array_merge($rows, $wrapped);
        }

        return $rows;
    }

    /**
     * Returns authorized songs for one exact normalized artist, ordered by current-user play count.
     *
     * This is the documented local fallback for Subsonic getTopSongs while no licensed external
     * ranking provider is configured. Personal counts only sort songs already inside the live media
     * scope; missing statistics fall back to stable title/ID order. The method is read-only and does
     * not update history, counts, external services, or scan state.
     *
     * @param array<string, mixed> $actor Authenticated principal and personal statistics owner.
     * @return list<array<string, mixed>> Path-free authorized song projections.
     */
    public function topSongsByArtist(array $actor, string $normalizedArtist, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->scopedSongQuery($actor)
            ->whereExists(function (Builder $artists) use ($normalizedArtist): void {
                $artists->selectRaw('1')->from('media_song_artists as top_song_artists')
                    ->join('media_artists as top_artists', 'top_artists.id', '=', 'top_song_artists.artist_id')
                    ->whereColumn('top_song_artists.song_id', 'songs.id')
                    ->where('top_artists.normalized_name', $normalizedArtist);
            });
        /** @var list<stdClass> $rows */
        $rows = $query->orderByRaw(
            '(SELECT COALESCE(top_stats.play_count, 0) FROM user_song_play_stats AS top_stats'
            . ' WHERE top_stats.song_id = songs.id AND top_stats.user_id = ? LIMIT 1) DESC',
            [(string) ($actor['id'] ?? '')],
        )->orderBy('songs.title')->orderBy('songs.id')->limit($limit)->get($this->songColumns())->all();

        return $this->mapSongRows($actor, $rows);
    }

    /**
     * 按稳定艺人 ID 返回当前账号可见歌曲，供错误使用 ID 的 Subsonic 客户端兼容调用。
     *
     * 标准 `getTopSongs.artist` 参数是艺人名称，但部分客户端直接传 `getArtist.id`。本方法只接受规范
     * ULID，并通过已授权歌曲关系证明艺人可见；全局艺人词表本身不能产生结果。排序与名称入口完全
     * 一致，个人播放统计只参与顺序，不授予歌曲权限。读取不修改播放次数、历史或媒体文件。
     *
     * @param array<string, mixed> $actor 当前已认证账号及实时音乐库授权。
     * @return list<array<string, mixed>> 已完成路径和库存身份脱敏的歌曲摘要。
     */
    public function topSongsByArtistId(array $actor, string $artistId, int $limit): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $artistId) !== 1) {
            return [];
        }
        $artistId = (new MetadataEntityRedirectResolver())->resolve('artist', $artistId);
        $limit = max(1, min(500, $limit));
        $query = $this->scopedSongQuery($actor)->whereExists(function (Builder $artists) use ($artistId): void {
            $artists->selectRaw('1')->from('media_song_artists as top_song_artists')
                ->whereColumn('top_song_artists.song_id', 'songs.id')
                ->where('top_song_artists.artist_id', $artistId);
        });
        /** @var list<stdClass> $rows */
        $rows = $query->orderByRaw(
            '(SELECT COALESCE(top_stats.play_count, 0) FROM user_song_play_stats AS top_stats'
            . ' WHERE top_stats.song_id = songs.id AND top_stats.user_id = ? LIMIT 1) DESC',
            [(string) ($actor['id'] ?? '')],
        )->orderBy('songs.title')->orderBy('songs.id')->limit($limit)->get($this->songColumns())->all();

        return $this->mapSongRows($actor, $rows);
    }

    /**
     * 根据本地共同艺人、同专辑和共同流派返回授权范围内的相似歌曲。
     *
     * 种子歌曲首先通过统一可播放范围复验；候选随后再次使用同一活动库、可用文件、元数据成功和实时
     * grant 边界。共同艺人优先于同专辑，同专辑优先于共同流派；这些信号只排序已有本地媒体，不声称
     * 是外部推荐或全局相似度。没有任何本地关系时返回空列表。方法只读且不会写推荐缓存或播放统计。
     *
     * @param array<string, mixed> $actor 当前已认证账号及实时音乐库授权。
     * @return list<array<string, mixed>> 不含种子歌曲的授权候选。
     * @throws MediaDetailNotFound 种子 ID 非法、不可用或已撤权。
     */
    public function similarSongs(array $actor, string $songId, int $limit): array
    {
        $this->assertMediaId($songId);
        /** @var stdClass|null $seed */
        $seed = $this->scopedSongQuery($actor)->where('songs.id', $songId)
            ->first(['songs.id', 'songs.album_id']);
        if (!$seed instanceof stdClass) {
            throw new MediaDetailNotFound('Song not found.');
        }
        $artistIds = Db::table('media_song_artists')->where('song_id', $songId)
            ->pluck('artist_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $genreIds = Db::table('media_song_genres')->where('song_id', $songId)
            ->pluck('genre_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $limit = max(1, min(500, $limit));
        $query = $this->scopedSongQuery($actor)->where('songs.id', '!=', $songId)
            ->where(function (Builder $related) use ($seed, $artistIds, $genreIds): void {
                $related->where('songs.album_id', (string) $seed->album_id);
                if ($artistIds !== []) {
                    $related->orWhereExists(function (Builder $artists) use ($artistIds): void {
                        $artists->selectRaw('1')->from('media_song_artists as similar_song_artists')
                            ->whereColumn('similar_song_artists.song_id', 'songs.id')
                            ->whereIn('similar_song_artists.artist_id', $artistIds);
                    });
                }
                if ($genreIds !== []) {
                    $related->orWhereExists(function (Builder $genres) use ($genreIds): void {
                        $genres->selectRaw('1')->from('media_song_genres as similar_song_genres')
                            ->whereColumn('similar_song_genres.song_id', 'songs.id')
                            ->whereIn('similar_song_genres.genre_id', $genreIds);
                    });
                }
            });
        if ($artistIds !== []) {
            $placeholders = implode(',', array_fill(0, count($artistIds), '?'));
            $query->orderByRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM media_song_artists AS ranked_similar_artists'
                . ' WHERE ranked_similar_artists.song_id = songs.id'
                . ' AND ranked_similar_artists.artist_id IN (' . $placeholders . ')) THEN 0 ELSE 1 END',
                $artistIds,
            );
        }
        $query->orderByRaw('CASE WHEN songs.album_id = ? THEN 0 ELSE 1 END', [(string) $seed->album_id]);
        if ($genreIds !== []) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
            $query->orderByRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM media_song_genres AS ranked_similar_genres'
                . ' WHERE ranked_similar_genres.song_id = songs.id'
                . ' AND ranked_similar_genres.genre_id IN (' . $placeholders . ')) THEN 0 ELSE 1 END',
                $genreIds,
            );
        }
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.title')->orderBy('songs.id')->limit($limit)
            ->get($this->songColumns())->all();

        return $this->mapSongRows($actor, $rows);
    }

    /**
     * 根据共同歌曲署名和共同流派投影本地相似艺人。
     *
     * 种子与候选都必须通过可见歌曲关系证明权限；隐藏库中的同名艺人、共享词表和图片不能进入结果。
     * 共同署名优先，其次按共同流派、名称和稳定 ID 排序。返回结构可直接复用艺人列表映射，包含当前
     * 授权范围的专辑/歌曲计数与封面存在性。读取失败不回退外部网络，也不持久化推断关系。
     *
     * @param array<string, mixed> $actor 当前已认证账号及实时音乐库授权。
     * @return list<array<string, mixed>> 已授权且不包含种子本身的艺人摘要。
     * @throws MediaDetailNotFound 种子艺人 ID 非法、不可见或已撤权。
     */
    public function similarArtists(array $actor, string $artistId, int $limit): array
    {
        $this->assertMediaId($artistId);
        $artistId = (new MetadataEntityRedirectResolver())->resolve('artist', $artistId);
        $seedSongs = $this->scopedSongQuery($actor)->whereExists(function (Builder $credits) use ($artistId): void {
            $credits->selectRaw('1')->from('media_song_artists as seed_artist_songs')
                ->whereColumn('seed_artist_songs.song_id', 'songs.id')
                ->where('seed_artist_songs.artist_id', $artistId);
        })->pluck('songs.id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($seedSongs === []) {
            throw new MediaDetailNotFound('Artist not found.');
        }
        $genreIds = Db::table('media_song_genres')->whereIn('song_id', $seedSongs)
            ->distinct()->pluck('genre_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $limit = max(1, min(100, $limit));
        $query = $this->scopedArtistQuery($actor)->where('artists.id', '!=', $artistId)
            ->where(function (Builder $related) use ($seedSongs, $genreIds): void {
                $related->whereIn('songs.id', $seedSongs);
                if ($genreIds !== []) {
                    $related->orWhereExists(function (Builder $genres) use ($genreIds): void {
                        $genres->selectRaw('1')->from('media_song_genres as similar_artist_genres')
                            ->whereColumn('similar_artist_genres.song_id', 'songs.id')
                            ->whereIn('similar_artist_genres.genre_id', $genreIds);
                    });
                }
            })->groupBy('artists.id', 'artists.name', 'artists.sort_name');
        $seedPlaceholders = implode(',', array_fill(0, count($seedSongs), '?'));
        /** @var list<stdClass> $rankedRows */
        $rankedRows = $query->orderByRaw(
            'CASE WHEN EXISTS (SELECT 1 FROM media_song_artists AS shared_song_credits'
            . ' WHERE shared_song_credits.artist_id = artists.id'
            . ' AND shared_song_credits.song_id IN (' . $seedPlaceholders . ')) THEN 0 ELSE 1 END',
            $seedSongs,
        )->orderBy('artists.name')->orderBy('artists.id')->limit($limit)->get([
            'artists.id',
        ])->all();
        $rankedIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rankedRows);
        if ($rankedIds === []) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $rows = $this->scopedArtistQuery($actor)->whereIn('artists.id', $rankedIds)
            ->groupBy('artists.id', 'artists.name', 'artists.sort_name')->get([
            'artists.id', 'artists.name', 'artists.sort_name',
            Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
            Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
        ])->all();
        $positions = array_flip($rankedIds);
        usort($rows, static fn (stdClass $left, stdClass $right): int =>
            $positions[(string) $left->id] <=> $positions[(string) $right->id]);
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        return $this->mapArtistRows($actor, $rows);
    }

    /**
     * Lists artists only when they are connected to an in-scope available song.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array{artists: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function artists(array $actor, ?string $libraryId, int $limit, int $offset, bool $favoritesOnly = false): array
    {
        [$limit, $offset] = $this->bounds($limit, $offset);
        $query = $this->scopedArtistQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        $this->filterFavorites($query, $actor, 'artists', 'artists.id', $favoritesOnly);
        $total = (clone $query)->distinct()->count('artists.id');
        /** @var list<stdClass> $rows */
        $rows = $query
            ->groupBy('artists.id', 'artists.name', 'artists.sort_name')
            ->orderBy('artists.name')->orderBy('artists.id')->offset($offset)->limit($limit)
            ->get([
                'artists.id', 'artists.name', 'artists.sort_name',
                Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
                Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
            ])->all();

        return [
            'artists' => $this->mapArtistRows($actor, $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Lists visible multi-value genres with distinct song and album counts.
     *
     * A shared genre vocabulary row is never sufficient for visibility: every aggregate is reached
     * through an active authorized library and an available, successfully parsed song. A song may
     * legitimately contribute to multiple genres, while each genre count de-duplicates song IDs.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array{genres: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function genres(array $actor, ?string $libraryId, int $limit, int $offset): array
    {
        [$limit, $offset] = $this->bounds($limit, $offset);
        $query = $this->scopedGenreQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        $total = (clone $query)->distinct()->count('genres.id');
        /** @var list<stdClass> $rows */
        $rows = $query->groupBy('genres.id', 'genres.name', 'genres.normalized_name')
            ->orderBy('genres.normalized_name')->orderBy('genres.id')->offset($offset)->limit($limit)
            ->get([
                'genres.id', 'genres.name',
                Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
                Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
            ])->all();

        return [
            'genres' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'songCount' => (int) $row->song_count,
                'albumCount' => (int) $row->album_count,
            ], $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Aggregates release years only from songs visible under the principal's current grants.
     *
     * Null is retained as an explicit "unknown" group instead of being coerced to year zero. Album
     * and song counts are distinct because a multi-track album contributes once. The year domain is
     * naturally small, so the portable SQLite/MySQL implementation counts grouped rows in memory;
     * it does not depend on dialect-specific COUNT(DISTINCT tuple) or null ordering behavior.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array{years: list<array{year: int|null, label: string, songCount: int, albumCount: int}>, total: int, limit: int, offset: int}
     */
    public function years(array $actor, ?string $libraryId, int $limit, int $offset): array
    {
        [$limit, $offset] = $this->bounds($limit, $offset);
        $query = $this->scopedSongQuery($actor);
        $this->filterLibrary($query, $libraryId, 'songs.library_id');
        /** @var list<stdClass> $groups */
        $groups = $query->groupBy('songs.release_year')
            ->orderByRaw('CASE WHEN songs.release_year IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('songs.release_year')
            ->get([
                'songs.release_year',
                Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
                Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
            ])->all();
        $total = count($groups);
        $groups = array_slice($groups, $offset, $limit);

        return [
            'years' => array_map(static fn (stdClass $row): array => [
                'year' => $row->release_year === null ? null : (int) $row->release_year,
                'label' => $row->release_year === null ? '未知年份' : (string) $row->release_year,
                'songCount' => (int) $row->song_count,
                'albumCount' => (int) $row->album_count,
            ], $groups),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns one authorized album and its currently playable tracks in disc/track order.
     *
     * The album header is reachable only through at least one active, available, successfully
     * indexed song in a currently granted library. Missing and unauthorized IDs share one
     * exception. Stored track totals may include temporarily unavailable files, so the response
     * reports `unavailableSongCount` without exposing their names or paths. No filesystem access or
     * persistence occurs in this query.
     *
     * @param array<string, mixed> $actor Authenticated principal with the play capability.
     * @return array{album: array<string, mixed>, songs: list<array<string, mixed>>, genres: list<array{id: string, name: string}>, unavailableSongCount: int}
     * @throws MediaDetailNotFound Invalid, missing, unavailable, or unauthorized album.
     */
    public function albumDetail(array $actor, string $albumId): array
    {
        $this->assertMediaId($albumId);
        $albumId = (new MetadataEntityRedirectResolver())->resolve('album', $albumId);
        /** @var stdClass|null $row */
        $row = $this->scopedAlbumQuery($actor)->where('albums.id', $albumId)
            ->distinct()->first($this->albumColumns());
        if (!$row instanceof stdClass) {
            throw new MediaDetailNotFound('Album not found.');
        }
        /** @var list<stdClass> $songRows */
        $songRows = $this->scopedSongQuery($actor)->where('songs.album_id', $albumId)
            ->orderByRaw('COALESCE(songs.disc_number, 1)')
            ->orderByRaw('COALESCE(songs.track_number, 2147483647)')
            ->orderBy('songs.title')->orderBy('songs.id')->get($this->songColumns())->all();
        $songs = $this->mapSongRows($actor, $songRows);
        /** @var list<stdClass> $genreRows */
        $genreRows = $this->scopedGenreQuery($actor)->where('songs.album_id', $albumId)
            ->groupBy('genres.id', 'genres.name', 'genres.normalized_name')
            ->orderBy('genres.normalized_name')->orderBy('genres.id')->get(['genres.id', 'genres.name'])->all();
        return [
            'album' => $this->mapAlbumRows($actor, [$row])[0],
            'songs' => $songs,
            'genres' => array_map(static fn (stdClass $genre): array => ['id' => (string) $genre->id, 'name' => (string) $genre->name], $genreRows),
            'unavailableSongCount' => max(0, (int) $row->song_count - count($songs)),
        ];
    }

    /**
     * Returns one authorized song with extended tags and normalized genre references.
     *
     * The song is selected through the same live library/file/metadata scope as streaming. The
     * extended fields are safe embedded tags only; inventory IDs, paths, device/inode identity,
     * probe errors, and source filenames never enter the projection. Lyrics remain a separate
     * endpoint because they have independent versions and redistribution policy.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array{song: array<string, mixed>, tags: array<string, mixed>, genres: list<array{id: string, name: string}>}
     * @throws MediaDetailNotFound Invalid, missing, unavailable, or unauthorized song.
     */
    public function songDetail(array $actor, string $songId): array
    {
        $this->assertMediaId($songId);
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        /** @var stdClass|null $row */
        $row = $this->scopedSongQuery($actor)->where('songs.id', $songId)->first([
            ...$this->songColumns(),
            'songs.composer', 'songs.comment', 'songs.bpm', 'songs.isrc', 'songs.musicbrainz_track_id',
            'songs.replaygain_track_gain', 'songs.replaygain_track_peak',
            'songs.replaygain_album_gain', 'songs.replaygain_album_peak',
        ]);
        if (!$row instanceof stdClass) {
            throw new MediaDetailNotFound('Song not found.');
        }
        /** @var list<stdClass> $genreRows */
        $genreRows = $this->scopedGenreQuery($actor)->where('songs.id', $songId)
            ->orderBy('song_genres.position')->get(['genres.id', 'genres.name'])->all();

        return [
            'song' => $this->mapSongRows($actor, [$row])[0],
            'tags' => [
                'composer' => $row->composer === null ? null : (string) $row->composer,
                'comment' => $row->comment === null ? null : (string) $row->comment,
                'bpm' => $row->bpm === null ? null : (float) $row->bpm,
                'isrc' => $row->isrc === null ? null : (string) $row->isrc,
                'musicBrainzTrackId' => $row->musicbrainz_track_id === null ? null : (string) $row->musicbrainz_track_id,
                'replayGain' => [
                    'trackGainDb' => $row->replaygain_track_gain === null ? null : (float) $row->replaygain_track_gain,
                    'trackPeak' => $row->replaygain_track_peak === null ? null : (float) $row->replaygain_track_peak,
                    'albumGainDb' => $row->replaygain_album_gain === null ? null : (float) $row->replaygain_album_gain,
                    'albumPeak' => $row->replaygain_album_peak === null ? null : (float) $row->replaygain_album_peak,
                ],
            ],
            'genres' => array_map(static fn (stdClass $genre): array => ['id' => (string) $genre->id, 'name' => (string) $genre->name], $genreRows),
        ];
    }

    /**
     * Returns one authorized artist with scoped songs and albums from current library grants.
     *
     * A row in the global artist vocabulary never grants visibility. The artist must participate
     * in at least one currently playable song, and every child collection independently reuses the
     * same live authorization scope. Albums include both primary album-artist credits and albums
     * reached through song participation. Results are bounded to 50 songs and 100 albums until the
     * detail contract adds cursor pagination. 艺人资料只能在上述可见性成立后读取，避免全局 profile 表让
     * 无权账号枚举隐藏艺人；资料缺失返回 null，详情请求不触发网络或后台任务。
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array{artist: array<string, mixed>, topSongs: list<array<string, mixed>>, albums: list<array<string, mixed>>}
     * @throws MediaDetailNotFound Invalid, missing, unavailable, or unauthorized artist.
     */
    public function artistDetail(array $actor, string $artistId): array
    {
        $this->assertMediaId($artistId);
        $artistId = (new MetadataEntityRedirectResolver())->resolve('artist', $artistId);
        /** @var stdClass|null $row */
        $row = $this->scopedArtistQuery($actor)->where('artists.id', $artistId)
            ->groupBy('artists.id', 'artists.name', 'artists.sort_name')->first([
                'artists.id', 'artists.name', 'artists.sort_name',
                Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
                Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
            ]);
        if (!$row instanceof stdClass) {
            throw new MediaDetailNotFound('Artist not found.');
        }
        /** @var list<stdClass> $songRows */
        $songRows = $this->scopedSongQuery($actor)->whereExists(function (Builder $credits) use ($artistId): void {
            $credits->selectRaw('1')->from('media_song_artists as selected_song_artists')
                ->whereColumn('selected_song_artists.song_id', 'songs.id')
                ->where('selected_song_artists.artist_id', $artistId);
        })->orderByDesc('songs.release_date')->orderBy('songs.title')->orderBy('songs.id')
            ->limit(50)->get($this->songColumns())->all();

        $albumQuery = $this->scopedAlbumQuery($actor)->where(function (Builder $credits) use ($artistId): void {
            $credits->whereExists(function (Builder $albumCredits) use ($artistId): void {
                $albumCredits->selectRaw('1')->from('media_album_artists as selected_album_artists')
                    ->whereColumn('selected_album_artists.album_id', 'albums.id')
                    ->where('selected_album_artists.artist_id', $artistId);
            })->orWhereExists(function (Builder $songCredits) use ($artistId): void {
                $songCredits->selectRaw('1')->from('media_song_artists as selected_song_artists')
                    ->whereColumn('selected_song_artists.song_id', 'songs.id')
                    ->where('selected_song_artists.artist_id', $artistId);
            });
        });
        /** @var list<stdClass> $albumRows */
        $albumRows = $albumQuery->distinct()->orderByDesc('albums.release_date')
            ->orderBy('albums.title')->orderBy('albums.id')->limit(100)
            ->get($this->albumColumns())->all();
        $artist = $this->mapArtistRows($actor, [$row])[0];
        $artist['profile'] = $this->artistProfile($artistId);

        return [
            'artist' => $artist,
            'topSongs' => $this->mapSongRows($actor, $songRows),
            'albums' => $this->mapAlbumRows($actor, $albumRows),
        ];
    }

    /**
     * Searches songs, albums, and artists inside the same live authorization scopes as browsing.
     *
     * Every selected section is queried independently so counts remain meaningful and one entity's
     * joins cannot multiply another entity. User wildcard characters are escaped before LIKE and
     * all structural choices originate from SearchValidator's allowlists. The original query is
     * returned for URL/view reconciliation but is never written to logs or server-side history.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @return array<string, mixed> Path-free section results and per-section pagination metadata.
     */
    public function search(array $actor, SearchQueryInput $input): array
    {
        $results = ['songs' => [], 'albums' => [], 'artists' => []];
        $totals = ['songs' => 0, 'albums' => 0, 'artists' => 0];
        foreach (['songs', 'albums', 'artists'] as $type) {
            if ($input->type !== 'all' && $input->type !== $type) {
                continue;
            }
            $section = match ($type) {
                'songs' => $this->searchSongs($actor, $input),
                'albums' => $this->searchAlbums($actor, $input),
                'artists' => $this->searchArtists($actor, $input),
            };
            $results[$type] = $section['items'];
            $totals[$type] = $section['total'];
        }

        return [
            'query' => $input->query,
            'type' => $input->type,
            'results' => $results,
            'totals' => $totals,
            'limit' => $input->limit,
            'offset' => $input->offset,
            'hasMore' => [
                'songs' => $input->offset + count($results['songs']) < $totals['songs'],
                'albums' => $input->offset + count($results['albums']) < $totals['albums'],
                'artists' => $input->offset + count($results['artists']) < $totals['artists'],
            ],
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function searchSongs(array $actor, SearchQueryInput $input): array
    {
        $pattern = $this->containsPattern($input->normalizedQuery);
        $query = $this->scopedSongQuery($actor);
        $this->filterLibrary($query, $input->libraryId, 'songs.library_id');
        $query->where(function (Builder $match) use ($pattern): void {
            $match->whereRaw("songs.normalized_title LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereRaw("albums.normalized_title LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereExists(function (Builder $artists) use ($pattern): void {
                    $artists->selectRaw('1')
                        ->from('media_song_artists as search_song_artists')
                        ->join('media_artists as search_artists', 'search_artists.id', '=', 'search_song_artists.artist_id')
                        ->whereColumn('search_song_artists.song_id', 'songs.id')
                        ->whereRaw("search_artists.normalized_name LIKE ? ESCAPE '\\'", [$pattern]);
                });
        });
        $total = (clone $query)->count('songs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.normalized_title')->orderBy('songs.id')
            ->offset($input->offset)->limit($input->limit)->get($this->songColumns())->all();
        $artists = $this->songArtists(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
        $albumArtists = $this->includeOpenSubsonicFacts
            ? $this->albumArtists(array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->album_id,
                $rows,
            ))))
            : [];
        $covers = $this->songArtworkVersions($rows);
        $preferences = $this->preferenceMap($actor, 'songs', array_map(static fn (stdClass $row): string => (string) $row->id, $rows));

        return [
            'items' => array_map(
                fn (stdClass $row): array => $this->mapSong($row, $artists, $albumArtists, $covers, $preferences),
                $rows,
            ),
            'total' => $total,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function searchAlbums(array $actor, SearchQueryInput $input): array
    {
        $pattern = $this->containsPattern($input->normalizedQuery);
        $query = $this->scopedAlbumQuery($actor);
        $this->filterLibrary($query, $input->libraryId, 'albums.library_id');
        $query->where(function (Builder $match) use ($pattern): void {
            $match->whereRaw("albums.normalized_title LIKE ? ESCAPE '\\'", [$pattern])
                ->orWhereExists(function (Builder $artists) use ($pattern): void {
                    $artists->selectRaw('1')
                        ->from('media_album_artists as search_album_artists')
                        ->join('media_artists as search_artists', 'search_artists.id', '=', 'search_album_artists.artist_id')
                        ->whereColumn('search_album_artists.album_id', 'albums.id')
                        ->whereRaw("search_artists.normalized_name LIKE ? ESCAPE '\\'", [$pattern]);
                });
        });
        $total = (clone $query)->distinct()->count('albums.id');
        /** @var list<stdClass> $rows */
        $rows = $query->distinct()->orderBy('albums.normalized_title')->orderBy('albums.id')
            ->offset($input->offset)->limit($input->limit)->get($this->albumColumns())->all();
        return [
            'items' => $this->mapAlbumRows($actor, $rows),
            'total' => $total,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function searchArtists(array $actor, SearchQueryInput $input): array
    {
        $pattern = $this->containsPattern($input->normalizedQuery);
        $query = $this->scopedArtistQuery($actor);
        $this->filterLibrary($query, $input->libraryId, 'songs.library_id');
        $query->whereRaw("artists.normalized_name LIKE ? ESCAPE '\\'", [$pattern]);
        $total = (clone $query)->distinct()->count('artists.id');
        /** @var list<stdClass> $rows */
        $rows = $query->groupBy('artists.id', 'artists.name', 'artists.sort_name')
            ->orderBy('artists.normalized_name')->orderBy('artists.id')
            ->offset($input->offset)->limit($input->limit)->get([
                'artists.id', 'artists.name', 'artists.sort_name',
                Db::raw('COUNT(DISTINCT songs.id) AS song_count'),
                Db::raw('COUNT(DISTINCT songs.album_id) AS album_count'),
            ])->all();

        return ['items' => $this->mapArtistRows($actor, $rows), 'total' => $total];
    }

    /** Builds the mandatory song scope shared by list and future detail operations. */
    private function scopedSongQuery(array $actor): Builder
    {
        $query = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        $this->applyGrant($query, $actor, 'songs.library_id');
        (new SongDuplicateRedirectResolver())->excludeActiveSources($query, 'songs');

        return $query;
    }

    /** Builds album scope from visible child songs so empty or stale albums cannot leak. */
    private function scopedAlbumQuery(array $actor): Builder
    {
        $query = Db::table('media_albums as albums')
            ->join('media_songs as songs', 'songs.album_id', '=', 'albums.id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'albums.library_id')
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        $this->applyGrant($query, $actor, 'albums.library_id');
        (new SongDuplicateRedirectResolver())->excludeActiveSources($query, 'songs');

        return $query;
    }

    /** Builds artist scope through visible song participation, never through the global vocabulary alone. */
    private function scopedArtistQuery(array $actor): Builder
    {
        $query = Db::table('media_artists as artists')
            ->join('media_song_artists as song_artists', 'song_artists.artist_id', '=', 'artists.id')
            ->join('media_songs as songs', 'songs.id', '=', 'song_artists.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        $this->applyGrant($query, $actor, 'songs.library_id');
        (new SongDuplicateRedirectResolver())->excludeActiveSources($query, 'songs');

        return $query;
    }

    /** Builds genre scope through current songs rather than exposing the global tag vocabulary. */
    private function scopedGenreQuery(array $actor): Builder
    {
        $query = Db::table('media_genres as genres')
            ->join('media_song_genres as song_genres', 'song_genres.genre_id', '=', 'genres.id')
            ->join('media_songs as songs', 'songs.id', '=', 'song_genres.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        $this->applyGrant($query, $actor, 'songs.library_id');
        (new SongDuplicateRedirectResolver())->excludeActiveSources($query, 'songs');

        return $query;
    }

    /** Adds the current principal's live grant unless the principal is a super administrator. */
    private function applyGrant(Builder $query, array $actor, string $libraryColumn): void
    {
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as media_grant', function ($join) use ($actor, $libraryColumn): void {
                $join->on('media_grant.library_id', '=', $libraryColumn)
                    ->where('media_grant.user_id', '=', (string) $actor['id']);
            });
        }
    }

    /** Applies only syntactically valid opaque IDs; invalid filters yield an empty scope. */
    private function filterLibrary(Builder $query, ?string $libraryId, string $column): void
    {
        if ($libraryId === null || $libraryId === '') {
            return;
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where($column, $libraryId);
    }

    /**
     * Restricts songs through the normalized many-to-many relation without multiplying rows.
     *
     * Invalid IDs force an empty scope and reveal no genre existence. The EXISTS form preserves one
     * row per song, so totals and pagination remain stable even when a song has several genres.
     */
    private function filterGenre(Builder $query, ?string $genreId): void
    {
        if ($genreId === null || $genreId === '') {
            return;
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $genreId) !== 1) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereExists(function (Builder $genres) use ($genreId): void {
            $genres->selectRaw('1')->from('media_song_genres as selected_genres')
                ->whereColumn('selected_genres.song_id', 'songs.id')
                ->where('selected_genres.genre_id', $genreId);
        });
    }

    /**
     * Restricts an already visible outer song row by exact normalized genre name.
     *
     * `songColumn` is a service-owned alias and never request input. The portable EXISTS query keeps
     * one outer row per song/album and treats empty or normalization-only values as an empty scope,
     * avoiding wildcard and collation differences between SQLite and future MySQL.
     */
    private function filterGenreName(Builder $query, ?string $genre, string $songColumn): void
    {
        if ($genre === null) {
            return;
        }
        $normalized = (new SearchTextNormalizer())->normalize(trim($genre));
        if ($normalized === '') {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereExists(function (Builder $genres) use ($normalized, $songColumn): void {
            $genres->selectRaw('1')->from('media_song_genres as selected_named_genres')
                ->join('media_genres as selected_genre_names', 'selected_genre_names.id', '=', 'selected_named_genres.genre_id')
                ->whereColumn('selected_named_genres.song_id', $songColumn)
                ->where('selected_genre_names.normalized_name', $normalized);
        });
    }

    /**
     * Applies a release-year selector without treating malformed input as SQL or year zero.
     *
     * The public token `unknown` maps only to SQL NULL. Numeric years are bounded to 1000-3000,
     * matching metadata validation tolerance; all other values force an empty scope. Column names
     * come exclusively from service-owned call sites and must never be request-derived.
     */
    private function filterReleaseYear(Builder $query, int|string|null $releaseYear, string $column): void
    {
        if ($releaseYear === null || $releaseYear === '') {
            return;
        }
        if ($releaseYear === 'unknown') {
            $query->whereNull($column);
            return;
        }
        $numericYear = filter_var($releaseYear, FILTER_VALIDATE_INT);
        if ($numericYear === false || $numericYear < 1000 || $numericYear > 3000) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where($column, $numericYear);
    }

    /**
     * Applies inclusive bounded years while preserving descending-range ordering for the caller.
     *
     * Bounds are service-validated integers; this defensive layer still forces an empty scope for
     * out-of-domain values. SQL uses the lower/upper numeric values regardless of presentation
     * direction, and any supplied bound naturally excludes NULL release years.
     */
    private function filterYearBounds(
        Builder $query,
        ?int $fromYear,
        ?int $toYear,
        string $column,
    ): void {
        foreach ([$fromYear, $toYear] as $year) {
            if ($year !== null && ($year < 1000 || $year > 3000)) {
                $query->whereRaw('1 = 0');
                return;
            }
        }
        if ($fromYear !== null && $toYear !== null) {
            $query->whereBetween($column, [min($fromYear, $toYear), max($fromYear, $toYear)]);
        } elseif ($fromYear !== null) {
            $query->where($column, '>=', $fromYear);
        } elseif ($toYear !== null) {
            $query->where($column, '<=', $toYear);
        }
    }

    /**
     * Restricts a scoped catalog query to favorites owned by the current user.
     *
     * Table and key names are selected only from this closed map. EXISTS avoids multiplying album
     * and artist rows and preserves the surrounding query's aggregate semantics. The caller must
     * already have applied the media authorization scope; this filter never grants visibility.
     *
     * @param array<string, mixed> $actor Authenticated principal whose preferences are requested.
     */
    private function filterFavorites(
        Builder $query,
        array $actor,
        string $type,
        string $mediaColumn,
        bool $favoritesOnly,
    ): void {
        if (!$favoritesOnly) {
            return;
        }
        [$table, $key] = $this->preferenceStorage($type);
        $userId = (string) ($actor['id'] ?? '');
        $query->whereExists(function (Builder $preferences) use ($table, $key, $mediaColumn, $userId): void {
            $preferences->selectRaw('1')->from($table . ' as selected_preferences')
                ->whereColumn('selected_preferences.' . $key, $mediaColumn)
                ->where('selected_preferences.user_id', $userId)
                ->where('selected_preferences.is_favorite', 1);
        });
    }

    /**
     * Loads only the current user's preference rows for an already authorized result page.
     *
     * IDs originate from the scoped query rather than request input. Missing rows intentionally map
     * to empty favorite/rating values in the projection, keeping response shape stable without leaking another
     * user's private state. At most one bounded catalog page is loaded at a time.
     *
     * @param array<string, mixed> $actor Authenticated owner of the personal values.
     * @param list<string> $mediaIds IDs already proven visible by the surrounding catalog query.
     * @return array<string, array{favorite:bool,favoritedAt:string|null,rating:int|null,ratedAt:string|null,playedAt?:string}>
     */
    private function preferenceMap(array $actor, string $type, array $mediaIds): array
    {
        $mediaIds = array_values(array_unique($mediaIds));
        if ($mediaIds === []) {
            return [];
        }
        [$table, $key] = $this->preferenceStorage($type);
        $rows = Db::table($table)->where('user_id', (string) ($actor['id'] ?? ''))
            ->whereIn($key, $mediaIds)->get([$key, 'is_favorite', 'favorited_at', 'rating', 'rated_at']);
        $result = [];
        $played = $this->includeOpenSubsonicFacts ? $this->playedMap($actor, $type, $mediaIds) : [];
        foreach ($played as $mediaId => $playedAt) {
            $result[$mediaId] = [
                'favorite' => false,
                'favoritedAt' => null,
                'rating' => null,
                'ratedAt' => null,
                'playedAt' => $playedAt,
            ];
        }
        foreach ($rows as $row) {
            $playedAt = $result[(string) $row->{$key}]['playedAt'] ?? null;
            $preference = [
                'favorite' => (bool) $row->is_favorite,
                'favoritedAt' => $row->favorited_at === null ? null : (string) $row->favorited_at,
                'rating' => $row->rating === null ? null : (int) $row->rating,
                'ratedAt' => $row->rated_at === null ? null : (string) $row->rated_at,
            ];
            if (is_string($playedAt)) {
                $preference['playedAt'] = $playedAt;
            }
            $result[(string) $row->{$key}] = $preference;
        }

        return $result;
    }

    /**
     * 批量读取当前账号在已授权媒体上的最近有效播放时间。
     *
     * OpenSubsonic 的 `played` 是个人字段，不能从全局目录时间或其他用户统计推导。输入 ID 已由外层
     * 目录查询证明可见，本方法仍从 `scopedSongQuery` 出发重新应用活动库、可用文件、元数据成功与实时
     * grant：专辑取其可见歌曲的最大时间，艺人只统计当前可见且实际署名的歌曲，隐藏库中的同名或共享
     * 艺人不会影响结果。只有 `play_count > 0` 且具有 `last_played_at` 的事实才输出；读取不增加计次、
     * 不更新时间线，也不创建缺失统计。滚动部署或精简测试库尚无统计表时安全退化为空映射。
     *
     * @param array<string,mixed> $actor 当前认证账号及实时授权快照。
     * @param list<string> $mediaIds 已完成对象授权的稳定媒体 ID。
     * @return array<string,string> 媒体 ID 到标准 UTC 播放时间的映射。
     */
    private function playedMap(array $actor, string $type, array $mediaIds): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('user_song_play_stats') || $mediaIds === []) {
            return [];
        }
        $query = $this->scopedSongQuery($actor)
            ->join('user_song_play_stats as personal_play_stats', function ($join) use ($actor): void {
                $join->on('personal_play_stats.song_id', '=', 'songs.id')
                    ->where('personal_play_stats.user_id', '=', (string) ($actor['id'] ?? ''));
            })
            ->where('personal_play_stats.play_count', '>', 0)
            ->whereNotNull('personal_play_stats.last_played_at');
        $mediaColumn = match ($type) {
            'songs' => 'songs.id',
            'albums' => 'songs.album_id',
            'artists' => 'played_song_artists.artist_id',
            default => throw new \InvalidArgumentException('Unsupported playback media type.'),
        };
        if ($type === 'artists') {
            $query->join('media_song_artists as played_song_artists', 'played_song_artists.song_id', '=', 'songs.id');
        }
        $rows = $query->whereIn($mediaColumn, $mediaIds)->groupBy($mediaColumn)->get([
            $mediaColumn . ' as media_id',
            Db::raw('MAX(personal_play_stats.last_played_at) AS played_at'),
        ]);
        $result = [];
        foreach ($rows as $row) {
            if ($row->played_at !== null) {
                $result[(string) $row->media_id] = (string) $row->played_at;
            }
        }

        return $result;
    }

    /** @return array{string, string} Server-owned preference table and media key. */
    private function preferenceStorage(string $type): array
    {
        return match ($type) {
            'songs' => ['user_song_preferences', 'song_id'],
            'albums' => ['user_album_preferences', 'album_id'],
            'artists' => ['user_artist_preferences', 'artist_id'],
            default => throw new \InvalidArgumentException('Unsupported preference media type.'),
        };
    }

    /** @return array{int, int} */
    private function bounds(int $limit, int $offset): array
    {
        return [max(1, min(100, $limit)), max(0, min(10_000, $offset))];
    }

    /**
     * Maps an already authorized row batch while keeping related queries bounded and user-scoped.
     *
     * @param list<stdClass> $rows Rows selected exclusively through scopedSongQuery.
     * @return list<array<string, mixed>> Path-free song summaries in the original row order.
     */
    private function mapSongRows(array $actor, array $rows): array
    {
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $artists = $this->songArtists($songIds);
        $albumArtists = $this->includeOpenSubsonicFacts
            ? $this->albumArtists(array_values(array_unique(array_map(
                static fn (stdClass $row): string => (string) $row->album_id,
                $rows,
            ))))
            : [];
        $covers = $this->songArtworkVersions($rows);
        $preferences = $this->preferenceMap($actor, 'songs', $songIds);

        return array_map(
            fn (stdClass $row): array => $this->mapSong($row, $artists, $albumArtists, $covers, $preferences),
            $rows,
        );
    }

    /** Rejects malformed opaque IDs before an existence query to preserve one not-found boundary. */
    private function assertMediaId(string $mediaId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $mediaId) !== 1) {
            throw new MediaDetailNotFound('Media not found.');
        }
    }

    /** @return list<string> Shared columns required by list and queue song projections. */
    private function songColumns(): array
    {
        return [
            'songs.id', 'songs.title', 'songs.sort_title', 'songs.track_number', 'songs.track_total',
            'songs.disc_number', 'songs.disc_total', 'songs.release_date', 'songs.release_year',
            'songs.duration_ms', 'songs.codec_name', 'songs.container_name', 'songs.bitrate',
            'songs.bit_depth', 'songs.sample_rate', 'songs.channels', 'songs.album_id',
            'albums.title as album_title', 'libraries.id as library_id', 'libraries.name as library_name',
        ];
    }

    /**
     * 把已完成权限过滤的数据库行映射为唯一的公开歌曲摘要结构。
     *
     * 调用方必须先使用本类的授权查询取得行，并批量加载艺术家、封面版本和当前账号偏好；本方法不再
     * 查询数据库，也不会扩大媒体可见范围。封面只输出带不透明缓存版本的同源 API URL，绝不返回文件
     * 路径、图片摘要或索引时间。缺少关联数据时使用空列表、未收藏状态或空封面，不让单张损坏封面使
     * 整个歌曲列表失败。
     *
     * @param array<string, list<array{id: string, name: string}>> $artists 按歌曲 ID 预加载的艺术家。
     * @param array<string, list<array{id: string, name: string}>> $albumArtists 按专辑 ID 预加载的专辑艺人。
     * @param array<string, string> $covers 按 `song:`/`album:` 前缀预加载的不透明封面缓存版本。
     * @param array<string, array{favorite:bool,favoritedAt:string|null,rating:int|null,ratedAt:string|null,playedAt?:string}> $preferences 当前账号偏好与可选播放时间。
     * @return array<string, mixed> 不含物理路径、可直接编码为 JSON 的歌曲元数据。
     */
    private function mapSong(
        stdClass $row,
        array $artists,
        array $albumArtists,
        array $covers,
        array $preferences,
    ): array
    {
        $preference = $preferences[(string) $row->id]
            ?? ['favorite' => false, 'favoritedAt' => null, 'rating' => null, 'ratedAt' => null];
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'sortTitle' => $row->sort_title === null ? null : (string) $row->sort_title,
            'artists' => $artists[(string) $row->id] ?? [],
            'album' => [
                'id' => (string) $row->album_id,
                'title' => (string) $row->album_title,
                'artists' => $albumArtists[(string) $row->album_id] ?? [],
                'coverUrl' => isset($covers['song:' . (string) $row->id])
                    ? '/api/v1/songs/' . rawurlencode((string) $row->id) . '/cover?v='
                        . rawurlencode($covers['song:' . (string) $row->id])
                    : (isset($covers['album:' . (string) $row->album_id])
                        ? '/api/v1/albums/' . rawurlencode((string) $row->album_id) . '/cover?v='
                            . rawurlencode($covers['album:' . (string) $row->album_id])
                        : null),
            ],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'trackNumber' => $row->track_number === null ? null : (int) $row->track_number,
            'trackTotal' => $row->track_total === null ? null : (int) $row->track_total,
            'discNumber' => $row->disc_number === null ? null : (int) $row->disc_number,
            'discTotal' => $row->disc_total === null ? null : (int) $row->disc_total,
            'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
            'releaseYear' => $row->release_year === null ? null : (int) $row->release_year,
            'durationMs' => (int) $row->duration_ms,
            'audio' => [
                'codec' => $row->codec_name === null ? null : (string) $row->codec_name,
                'container' => $row->container_name === null ? null : (string) $row->container_name,
                'bitrate' => $row->bitrate === null ? null : (int) $row->bitrate,
                'bitDepth' => $row->bit_depth === null ? null : (int) $row->bit_depth,
                'sampleRate' => $row->sample_rate === null ? null : (int) $row->sample_rate,
                'channels' => $row->channels === null ? null : (int) $row->channels,
            ],
            'preferences' => $preference,
        ];
    }

    /**
     * 把已授权专辑行映射为浏览、搜索和详情接口共享的公开摘要。
     *
     * 授权边界由调用方的 scoped 查询保证，本方法只组合已批量加载的数据。封面 URL 的版本随封面索引
     * 更新时间变化，用于使浏览器淘汰扫描期间缓存的失败响应；版本为不可逆短摘要，不暴露服务器路径、
     * 内容哈希或原始时间。某张封面缺失时只返回 `null`，不会影响同批次其他专辑。
     *
     * @param array<string, list<array{id: string, name: string}>> $artists 按专辑 ID 预加载的艺术家。
     * @param array<string, string> $covers 按专辑 ID 预加载的不透明封面缓存版本。
     * @param array<string, array{favorite:bool,favoritedAt:string|null,rating:int|null,ratedAt:string|null}> $preferences 当前账号偏好。
     * @param array<string, list<array{id:string,name:string}>> $genres 按专辑 ID 聚合的可见流派。
     * @return array<string, mixed> 不含物理路径的专辑摘要。
     */
    private function mapAlbum(
        stdClass $row,
        array $artists,
        array $covers,
        array $preferences,
        array $genres,
    ): array
    {
        $preference = $preferences[(string) $row->id]
            ?? ['favorite' => false, 'favoritedAt' => null, 'rating' => null, 'ratedAt' => null];
        $result = [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'coverUrl' => isset($covers[(string) $row->id])
                ? '/api/v1/albums/' . rawurlencode((string) $row->id) . '/cover?v='
                    . rawurlencode($covers[(string) $row->id])
                : null,
            'sortTitle' => $row->sort_title === null ? null : (string) $row->sort_title,
            'artists' => $artists[(string) $row->id] ?? [],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
            'releaseYear' => $row->release_year === null ? null : (int) $row->release_year,
            'songCount' => (int) $row->song_count,
            'durationMs' => (int) $row->duration_ms,
            'preferences' => $preference,
        ];
        if ($this->includeOpenSubsonicFacts) {
            $result['discTotal'] = property_exists($row, 'disc_total') && $row->disc_total !== null
                ? (int) $row->disc_total
                : null;
            $result['musicBrainzReleaseId'] = property_exists($row, 'musicbrainz_release_id')
                && $row->musicbrainz_release_id !== null
                ? (string) $row->musicbrainz_release_id
                : null;
            $result['musicBrainzReleaseGroupId'] = property_exists($row, 'musicbrainz_release_group_id')
                && $row->musicbrainz_release_group_id !== null
                ? (string) $row->musicbrainz_release_group_id
                : null;
            $result['genres'] = $genres[(string) $row->id] ?? [];
        }

        return $result;
    }

    /**
     * 为已授权专辑批量补齐署名、封面、个人状态和 OpenSubsonic 扩展事实。
     *
     * 调用方必须先从 scopedAlbumQuery 取得行；扩展事实只在兼容协议显式启用时查询，以免 Web 浏览列表承担
     * 额外开销。流派查询再次经过歌曲、活动库、库存可用性和当前账号 grant，不能因为全局词表或同 ID 的
     * 隐藏歌曲扩大可见范围。全部关系按批次读取，输入顺序不变，方法只读且不会触发扫描或远端请求。
     *
     * @param array<string, mixed> $actor Authenticated owner of preference values.
     * @param list<stdClass> $rows Rows previously filtered by scopedAlbumQuery.
     * @return list<array<string, mixed>>
     */
    private function mapAlbumRows(array $actor, array $rows): array
    {
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $artists = $this->albumArtists($ids);
        $covers = $this->albumCovers($ids);
        $preferences = $this->preferenceMap($actor, 'albums', $ids);
        $genres = $this->includeOpenSubsonicFacts ? $this->albumGenres($actor, $ids) : [];

        return array_map(
            fn (stdClass $row): array => $this->mapAlbum($row, $artists, $covers, $preferences, $genres),
            $rows,
        );
    }

    /**
     * Returns the fixed safe album projection columns shared by discovery queries.
     *
     * Keeping this closed list prevents discovery endpoints from accidentally exposing identity
     * keys or timestamps. SQL structure is service-owned and never derived from request input.
     *
     * @return list<string>
     */
    private function albumColumns(): array
    {
        $columns = [
            'albums.id', 'albums.title', 'albums.sort_title', 'albums.release_date',
            'albums.release_year', 'albums.song_count', 'albums.duration_ms',
            'libraries.id as library_id', 'libraries.name as library_name',
        ];
        if (!$this->includeOpenSubsonicFacts) {
            return $columns;
        }
        $schema = Db::connection()->getSchemaBuilder();
        foreach (['disc_total', 'musicbrainz_release_id', 'musicbrainz_release_group_id'] as $column) {
            if ($schema->hasColumn('media_albums', $column)) {
                $columns[] = 'albums.' . $column;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, array{favorite:bool,favoritedAt:string|null,rating:int|null,ratedAt:string|null}> $preferences Current-user values.
     * @return array<string, mixed> Permission-scoped artist aggregate shared by browse and search.
     */
    private function mapArtist(stdClass $row, array $preferences, array $images, array $facts): array
    {
        $preference = $preferences[(string) $row->id]
            ?? ['favorite' => false, 'favoritedAt' => null, 'rating' => null, 'ratedAt' => null];
        $result = [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'imageUrl' => isset($images[(string) $row->id])
                ? '/api/v1/artists/' . rawurlencode((string) $row->id) . '/image'
                : null,
            'sortName' => $row->sort_name === null ? null : (string) $row->sort_name,
            'songCount' => (int) $row->song_count,
            'albumCount' => (int) $row->album_count,
            'preferences' => $preference,
        ];
        if ($this->includeOpenSubsonicFacts) {
            $fact = $facts[(string) $row->id] ?? [];
            $result['musicBrainzId'] = is_string($fact['musicBrainzId'] ?? null)
                ? $fact['musicBrainzId']
                : null;
            $result['roles'] = is_array($fact['roles'] ?? null) ? $fact['roles'] : [];
        }

        return $result;
    }

    /**
     * 为已经证明可见的艺人行批量组合 OpenSubsonic 身份和角色。
     *
     * 输入行必须来自 scopedArtistQuery，角色查询还会独立经过当前账号的歌曲可见范围；因此共享艺人表、
     * 隐藏库专辑署名或失效库存不能让角色或 MusicBrainz ID 泄露。精简测试 schema 缺列时安全省略扩展，
     * 正式 schema 则一次批量查询完成，不产生逐艺人查询。方法只读，不更新资料或触发刮削。
     *
     * @param array<string,mixed> $actor 当前认证账号及实时音乐库授权。
     * @param list<stdClass> $rows 已授权艺人聚合行。
     * @return list<array<string,mixed>> 保持输入顺序的路径无关艺人投影。
     */
    private function mapArtistRows(array $actor, array $rows): array
    {
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $preferences = $this->preferenceMap($actor, 'artists', $ids);
        $images = $this->artistImages($actor, $ids);
        $facts = $this->includeOpenSubsonicFacts ? $this->artistOpenSubsonicFacts($actor, $ids) : [];

        return array_map(
            fn (stdClass $row): array => $this->mapArtist($row, $preferences, $images, $facts),
            $rows,
        );
    }

    /**
     * 读取已授权艺人的稳定资料投影；调用方必须先通过 scopedArtistQuery 证明当前可见性。
     *
     * tags/sources 在写入时已被限制为 JSON 列表，这里仍失败关闭，避免旧数据或人工修库把非列表结构
     * 暴露给客户端。资料表尚未迁移时返回 null，支持滚动部署；本方法只读且不触发任何远端请求。
     *
     * @return array<string,mixed>|null
     */
    private function artistProfile(string $artistId): ?array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('artist_profiles')) return null;
        /** @var stdClass|null $profile */
        $profile = Db::table('artist_profiles')->where('artist_id', $artistId)->first();
        if (!$profile instanceof stdClass) return null;
        $tags = json_decode((string) $profile->tags_json, true);
        $sources = json_decode((string) $profile->sources_json, true);
        return [
            'musicBrainzId' => (string) $profile->musicbrainz_artist_id,
            'wikidataId' => $profile->wikidata_id === null ? null : (string) $profile->wikidata_id,
            'canonicalName' => $profile->canonical_name === null ? null : (string) $profile->canonical_name,
            'artistType' => $profile->artist_type === null ? null : (string) $profile->artist_type,
            'gender' => $profile->gender === null ? null : (string) $profile->gender,
            'countryCode' => $profile->country_code === null ? null : (string) $profile->country_code,
            'areaName' => $profile->area_name === null ? null : (string) $profile->area_name,
            'beginDate' => $profile->begin_date === null ? null : (string) $profile->begin_date,
            'endDate' => $profile->end_date === null ? null : (string) $profile->end_date,
            'disambiguation' => $profile->disambiguation === null ? null : (string) $profile->disambiguation,
            'biography' => $profile->biography === null ? null : (string) $profile->biography,
            'officialUrl' => $profile->official_url === null ? null : (string) $profile->official_url,
            'wikipediaUrl' => $profile->wikipedia_url === null ? null : (string) $profile->wikipedia_url,
            'tags' => is_array($tags) && array_is_list($tags) ? $tags : [],
            'sources' => is_array($sources) && array_is_list($sources) ? $sources : [],
            'refreshedAt' => (string) $profile->refreshed_at,
        ];
    }

    /** Escapes SQL LIKE metacharacters before adding the service-owned contains wildcards. */
    private function containsPattern(string $normalizedQuery): string
    {
        return '%' . addcslashes($normalizedQuery, '\\%_') . '%';
    }

    /** @param list<string> $songIds @return array<string, list<array{id: string, name: string}>> */
    private function songArtists(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }
        $rows = Db::table('media_song_artists as links')
            ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('links.song_id', $songIds)->orderBy('links.position')
            ->get(['links.song_id', 'artists.id', 'artists.name']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->song_id][] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $result;
    }

    /**
     * 批量读取专辑艺人关系，供歌曲与专辑公开投影复用。
     *
     * 输入 ID 必须来自已授权外层查询，本方法不单独授予对象可见性。滚动部署或精简兼容库尚未创建
     * `media_album_artists` 时返回空映射，由协议层省略可选字段；不会退回歌曲主艺人或猜测关系，也不
     * 写入数据库。正式 schema 下按索引 position 保持稳定署名顺序。
     *
     * @param list<string> $albumIds
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function albumArtists(array $albumIds): array
    {
        if ($albumIds === [] || !Db::connection()->getSchemaBuilder()->hasTable('media_album_artists')) {
            return [];
        }
        $rows = Db::table('media_album_artists as links')
            ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('links.album_id', $albumIds)->orderBy('links.position')
            ->get(['links.album_id', 'artists.id', 'artists.name']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->album_id][] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $result;
    }

    /**
     * 批量返回专辑中当前账号实际可见歌曲贡献的流派。
     *
     * 专辑 ID 必须来自已授权外层查询，但共享流派词表本身不构成授权，所以这里仍复用 scopedGenreQuery
     * 校验活动库、库存状态、元数据状态和实时 grant。一个流派在同专辑多首歌出现时只返回一次，并按
     * 规范化名称及稳定 ID 排序；隐藏库歌曲、失效文件和未完成索引不会进入结果。方法只读且无缓存副作用。
     *
     * @param array<string,mixed> $actor 当前认证账号及实时音乐库授权。
     * @param list<string> $albumIds 已由专辑查询证明可见的 ID。
     * @return array<string,list<array{id:string,name:string}>> 按专辑 ID 分组的流派引用。
     */
    private function albumGenres(array $actor, array $albumIds): array
    {
        $albumIds = array_values(array_unique($albumIds));
        $schema = Db::connection()->getSchemaBuilder();
        if ($albumIds === [] || !$schema->hasTable('media_genres') || !$schema->hasTable('media_song_genres')) {
            return [];
        }
        $rows = $this->scopedGenreQuery($actor)->whereIn('songs.album_id', $albumIds)
            ->distinct()->orderBy('songs.album_id')->orderBy('genres.normalized_name')->orderBy('genres.id')
            ->get(['songs.album_id', 'genres.id', 'genres.name', 'genres.normalized_name']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->album_id][] = [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ];
        }

        return $result;
    }

    /**
     * 批量加载已授权艺人的 MusicBrainz 身份与 OpenSubsonic 署名角色。
     *
     * `artist` 仅表示艺人出现在当前可见歌曲署名，`albumartist` 仅表示艺人出现在当前可见专辑署名；
     * 两种角色都通过 scoped 查询重新证明授权，不能由全局关系表单独授予。MusicBrainz ID 只读取调用方
     * 已证明可见的艺人 ID。旧测试库或滚动升级缺少表/列时省略相应事实，不猜测角色，也不发起远端查询。
     *
     * @param array<string,mixed> $actor 当前认证账号及实时音乐库授权。
     * @param list<string> $artistIds 已由外层艺人查询证明可见的 ID。
     * @return array<string,array{musicBrainzId:?string,roles:list<string>}>
     */
    private function artistOpenSubsonicFacts(array $actor, array $artistIds): array
    {
        $artistIds = array_values(array_unique($artistIds));
        if ($artistIds === []) {
            return [];
        }
        $result = array_fill_keys($artistIds, ['musicBrainzId' => null, 'roles' => []]);
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasColumn('media_artists', 'musicbrainz_artist_id')) {
            $rows = Db::table('media_artists')->whereIn('id', $artistIds)
                ->get(['id', 'musicbrainz_artist_id']);
            foreach ($rows as $row) {
                if ($row->musicbrainz_artist_id !== null && trim((string) $row->musicbrainz_artist_id) !== '') {
                    $result[(string) $row->id]['musicBrainzId'] = (string) $row->musicbrainz_artist_id;
                }
            }
        }
        if ($schema->hasTable('media_song_artists')) {
            $songArtistIds = $this->scopedSongQuery($actor)
                ->join('media_song_artists as open_role_song_artists', 'open_role_song_artists.song_id', '=', 'songs.id')
                ->whereIn('open_role_song_artists.artist_id', $artistIds)->distinct()
                ->pluck('open_role_song_artists.artist_id')
                ->map(static fn (mixed $id): string => (string) $id)->all();
            foreach ($songArtistIds as $artistId) {
                $result[$artistId]['roles'][] = 'artist';
            }
        }
        if ($schema->hasTable('media_album_artists')) {
            $albumArtistIds = $this->scopedAlbumQuery($actor)
                ->join('media_album_artists as open_role_album_artists', 'open_role_album_artists.album_id', '=', 'albums.id')
                ->whereIn('open_role_album_artists.artist_id', $artistIds)->distinct()
                ->pluck('open_role_album_artists.artist_id')
                ->map(static fn (mixed $id): string => (string) $id)->all();
            foreach ($albumArtistIds as $artistId) {
                $result[$artistId]['roles'][] = 'albumartist';
            }
        }

        return $result;
    }

    /**
     * 返回专辑封面的不透明缓存版本，而不只返回“是否存在”。
     *
     * 扫描期间封面行可能先于全部音频投影完成，此时浏览器会短暂请求失败。若 URL 永远不变，原生图片
     * 缓存和前端失败状态可能继续沿用旧结果。这里把封面索引更新时间与专辑 ID 混合为短摘要；专辑没有
     * 专属图时，同专辑可用歌曲的已选 Provider 图片也会产生回退版本，使首页、目录与收藏使用同一个
     * `/albums/{id}/cover` 权限边界。重新选择或更新任一实际来源后 URL 必然变化，同时响应不会暴露
     * 物理路径、内容摘要或原始更新时间。输入 album ID 已来自外层授权查询；回退仍绑定同专辑歌曲、
     * 同库候选和可用库存，图片读取端点会再次复验账号 grant。
     *
     * @param list<string> $albumIds
     * @return array<string, string> album ID 到短缓存版本的映射。
     */
    private function albumCovers(array $albumIds): array
    {
        $albumIds = array_values(array_unique($albumIds));
        if ($albumIds === []) {
            return [];
        }
        $rows = Db::table('media_album_artworks')->whereIn('album_id', $albumIds)
            ->get(['album_id', 'updated_at']);
        $result = [];
        foreach ($rows as $row) {
            $albumId = (string) $row->album_id;
            // 滚动升级或兼容测试的旧封面投影可能尚无 updated_at；此时仍返回稳定、不泄露的版本，
            // 后续迁移补齐时间戳后自然切换到可随封面变化失效的摘要。
            $updatedAt = property_exists($row, 'updated_at') ? (string) $row->updated_at : '';
            $result[$albumId] = substr(
                hash('sha256', $albumId . "\0" . $updatedAt),
                0,
                16,
            );
        }

        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('media_artwork_selection_overrides')
            && $schema->hasTable('media_manual_artwork_candidates')
            && $schema->hasColumn('media_artwork_selection_overrides', 'song_id')
            && $schema->hasColumn('media_manual_artwork_candidates', 'song_id')) {
            $fallbackVersions = [];
            $fallbackRows = Db::table('media_artwork_selection_overrides as song_selections')
                ->join('media_manual_artwork_candidates as song_candidates', function ($join): void {
                    $join->on('song_candidates.id', '=', 'song_selections.candidate_id')
                        ->on('song_candidates.song_id', '=', 'song_selections.song_id')
                        ->on('song_candidates.library_id', '=', 'song_selections.library_id');
                })
                ->join('media_songs as selected_songs', function ($join): void {
                    $join->on('selected_songs.id', '=', 'song_selections.song_id')
                        ->on('selected_songs.library_id', '=', 'song_selections.library_id');
                })
                ->join('library_file_inventory as selected_files', 'selected_files.id', '=', 'selected_songs.inventory_file_id')
                ->whereIn('selected_songs.album_id', $albumIds)
                ->where('selected_files.status', 'available')
                ->where('selected_files.metadata_status', 'ready')
                ->orderBy('selected_songs.album_id')->orderByDesc('song_selections.updated_at')
                ->orderBy('selected_songs.id')
                ->get(['selected_songs.album_id', 'selected_songs.id as song_id', 'song_selections.updated_at']);
            foreach ($fallbackRows as $row) {
                $albumId = (string) $row->album_id;
                if (isset($fallbackVersions[$albumId])) continue;
                $fallbackVersions[$albumId] = substr(hash(
                    'sha256',
                    $albumId . "\0song-fallback\0" . (string) $row->song_id . "\0" . (string) $row->updated_at,
                ), 0, 16);
            }
            foreach ($fallbackVersions as $albumId => $fallbackVersion) {
                $result[$albumId] = isset($result[$albumId])
                    ? substr(hash('sha256', $result[$albumId] . "\0" . $fallbackVersion), 0, 16)
                    : $fallbackVersion;
            }
        }

        if ($schema->hasTable('media_artwork_selection_overrides')
            && $schema->hasColumn('media_artwork_selection_overrides', 'album_id')) {
            foreach (Db::table('media_artwork_selection_overrides')->whereIn('album_id', $albumIds)
                ->get(['album_id', 'updated_at']) as $row) {
                $albumId = (string) $row->album_id;
                $result[$albumId] = substr(
                    hash('sha256', $albumId . "\0manual\0" . (string) $row->updated_at),
                    0,
                    16,
                );
            }
        }

        return $result;
    }

    /**
     * 为已授权歌曲批量构造歌曲优先、专辑回退的封面版本映射。
     *
     * 输入行必须来自 `scopedSongQuery`，本方法不承担授权。歌曲选择只读取选择版本，不读取候选 BLOB；
     * 版本键带实体前缀，避免不同表偶然使用同一 ULID 时互相覆盖。迁移尚未应用的滚动升级窗口会只
     * 返回专辑封面，待 song_id 列出现后自动启用歌曲覆盖。
     *
     * @param list<stdClass> $rows
     * @return array<string,string>
     */
    private function songArtworkVersions(array $rows): array
    {
        $albumIds = array_values(array_unique(array_map(
            static fn (stdClass $row): string => (string) $row->album_id,
            $rows,
        )));
        $result = [];
        foreach ($this->albumCovers($albumIds) as $albumId => $version) {
            $result['album:' . $albumId] = $version;
        }
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasColumn('media_artwork_selection_overrides', 'song_id')) return $result;
        $songIds = array_values(array_unique(array_map(
            static fn (stdClass $row): string => (string) $row->id,
            $rows,
        )));
        if ($songIds === []) return $result;
        foreach (Db::table('media_artwork_selection_overrides')->whereIn('song_id', $songIds)
            ->get(['song_id', 'updated_at']) as $row) {
            $songId = (string) $row->song_id;
            $result['song:' . $songId] = substr(
                hash('sha256', $songId . "\0" . (string) $row->updated_at),
                0,
                16,
            );
        }
        return $result;
    }

    /**
     * 返回当前账号可见且确实存在有效图片来源的艺人 ID 集合。
     *
     * 艺人是跨库共享词条，本地扫描图和 Provider/上传选择却都按音乐库隔离，因此图片记录本身绝不能
     * 授予可见性。艺人专属来源和代表歌曲回退都必须从 `media_song_artists` 回到同库内可用且元数据
     * 成功的歌曲；普通账号还要实时命中 library grant。这样只有歌曲署名、不是专辑主创的客串艺人也能
     * 展示图片，同时撤销授权后列表、搜索、收藏和详情会立即去掉 URL。候选选择额外绑定候选的实体与库，
     * 损坏或跨库关系不会被投影。方法只返回布尔索引，不读取 BLOB、不修改缓存或媒体文件。
     *
     * @param array<string, mixed> $actor 已认证且由控制器证明具备全局 play 能力的账号。
     * @param list<string> $artistIds 外层媒体查询已经证明可见的艺人 ID。
     * @return array<string, true>
     */
    private function artistImages(array $actor, array $artistIds): array
    {
        $artistIds = array_values(array_unique($artistIds));
        if ($artistIds === []) {
            return [];
        }

        $localQuery = Db::table('media_artist_artworks as artwork')
            ->join('music_libraries as artwork_libraries', 'artwork_libraries.id', '=', 'artwork.library_id')
            ->join('library_file_inventory as artwork_source', 'artwork_source.id', '=', 'artwork.source_inventory_file_id')
            ->join('media_song_artists as artwork_song_artists', 'artwork_song_artists.artist_id', '=', 'artwork.artist_id')
            ->join('media_songs as artwork_songs', function ($join): void {
                $join->on('artwork_songs.id', '=', 'artwork_song_artists.song_id')
                    ->on('artwork_songs.library_id', '=', 'artwork.library_id');
            })
            ->join('library_file_inventory as artwork_files', 'artwork_files.id', '=', 'artwork_songs.inventory_file_id')
            ->whereIn('artwork.artist_id', $artistIds)
            ->where('artwork_libraries.status', 'active')
            ->where('artwork_source.status', 'available')
            ->where('artwork_files.status', 'available')
            ->where('artwork_files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $localQuery->join('library_user_grants as artwork_grant', function ($join) use ($actor): void {
                $join->on('artwork_grant.library_id', '=', 'artwork.library_id')
                    ->where('artwork_grant.user_id', '=', (string) $actor['id']);
            });
        }
        $result = [];
        foreach ($localQuery->distinct()->get(['artwork.artist_id']) as $row) {
            $result[(string) $row->artist_id] = true;
        }

        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasTable('media_manual_artwork_candidates')
            || !$schema->hasColumn('media_artwork_selection_overrides', 'artist_id')
            || !$schema->hasColumn('media_manual_artwork_candidates', 'artist_id')) {
            return $result;
        }
        $selectedQuery = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.artist_id', '=', 'selections.artist_id')
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->join('music_libraries as selected_libraries', 'selected_libraries.id', '=', 'selections.library_id')
            ->join('media_song_artists as selected_song_artists', 'selected_song_artists.artist_id', '=', 'selections.artist_id')
            ->join('media_songs as selected_songs', function ($join): void {
                $join->on('selected_songs.id', '=', 'selected_song_artists.song_id')
                    ->on('selected_songs.library_id', '=', 'selections.library_id');
            })
            ->join('library_file_inventory as selected_files', 'selected_files.id', '=', 'selected_songs.inventory_file_id')
            ->whereIn('selections.artist_id', $artistIds)
            ->where('selected_libraries.status', 'active')
            ->where('selected_files.status', 'available')
            ->where('selected_files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $selectedQuery->join('library_user_grants as selected_grant', function ($join) use ($actor): void {
                $join->on('selected_grant.library_id', '=', 'selections.library_id')
                    ->where('selected_grant.user_id', '=', (string) $actor['id']);
            });
        }
        foreach ($selectedQuery->distinct()->get(['selections.artist_id']) as $row) {
            $result[(string) $row->artist_id] = true;
        }

        if (!$schema->hasColumn('media_artwork_selection_overrides', 'song_id')
            || !$schema->hasColumn('media_manual_artwork_candidates', 'song_id')) {
            return $result;
        }
        $songFallbackQuery = Db::table('media_artwork_selection_overrides as song_selections')
            ->join('media_manual_artwork_candidates as song_candidates', function ($join): void {
                $join->on('song_candidates.id', '=', 'song_selections.candidate_id')
                    ->on('song_candidates.song_id', '=', 'song_selections.song_id')
                    ->on('song_candidates.library_id', '=', 'song_selections.library_id');
            })
            ->join('media_songs as fallback_songs', function ($join): void {
                $join->on('fallback_songs.id', '=', 'song_selections.song_id')
                    ->on('fallback_songs.library_id', '=', 'song_selections.library_id');
            })
            ->join('media_song_artists as fallback_credits', 'fallback_credits.song_id', '=', 'fallback_songs.id')
            ->join('music_libraries as fallback_libraries', 'fallback_libraries.id', '=', 'fallback_songs.library_id')
            ->join('library_file_inventory as fallback_files', 'fallback_files.id', '=', 'fallback_songs.inventory_file_id')
            ->whereIn('fallback_credits.artist_id', $artistIds)
            ->where('fallback_libraries.status', 'active')
            ->where('fallback_files.status', 'available')
            ->where('fallback_files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $songFallbackQuery->join('library_user_grants as fallback_grants', function ($join) use ($actor): void {
                $join->on('fallback_grants.library_id', '=', 'fallback_songs.library_id')
                    ->where('fallback_grants.user_id', '=', (string) $actor['id']);
            });
        }
        foreach ($songFallbackQuery->distinct()->get(['fallback_credits.artist_id']) as $row) {
            $result[(string) $row->artist_id] = true;
        }

        return $result;
    }
}
