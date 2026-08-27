<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Media\MediaDetailNotFound;
use app\application\Media\MediaQueryService;
use app\application\Search\SearchQueryInput;
use app\application\Search\SearchTextNormalizer;
use stdClass;
use Symfony\Component\Uid\Ulid;

/**
 * Adapts authorized Velin catalog projections to Subsonic ID3 browsing objects.
 *
 * MediaQueryService remains the sole catalog authorization/query boundary: every song, album,
 * artist, and genre is filtered by active library, available file, ready metadata, and live user
 * grant before this class sees it. Mapping converts milliseconds/bit-per-second units and adds only
 * label-derived virtual paths. No inventory identity, filesystem path, failed metadata, or hidden
 * aggregate is queried here. All operations are read-only and repeat current `play` authorization.
 */
final readonly class SubsonicCatalogService
{
    private const MAX_UNPAGED_ITEMS = 10_100;

    /** Uses the shared path-free media query boundary rather than a parallel Subsonic repository. */
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(includeOpenSubsonicFacts: true),
        private SubsonicArtworkTicketService $artworkTickets = new SubsonicArtworkTicketService(),
    ) {
    }

    /**
     * Returns one complete compatible song after live object authorization.
     *
     * Optional OpenSubsonic metadata comes only from indexed tags returned by songDetail. Missing,
     * malformed, unavailable, and unauthorized IDs all become protocol error 70.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @return array{song: array<string, mixed>}
     */
    public function song(array $actor, mixed $songId): array
    {
        $this->requirePlay($actor);
        $songId = $this->requiredId($songId);
        try {
            $detail = $this->media->songDetail($actor, $songId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Song was not found.', previous: $exception);
        }

        return ['song' => $this->mapSong($detail['song'], $detail['tags'], $detail['genres'])];
    }

    /**
     * Maps a song projection already authorized by a shared application service to Subsonic ID3.
     *
     * This narrow adapter exists for playlist/queue protocol services that receive path-free song
     * summaries from MediaQueryService through their owning domain service. It performs no database
     * query and must never be called with raw inventory rows or client-provided arrays. Missing
     * extended tags remain omitted, matching album-list and playlist response behavior.
     *
     * @param array<string, mixed> $song Authorization-safe shared song projection.
     * @return array<string, mixed> Subsonic-compatible child object without physical media identity.
     */
    public function mapAuthorizedSong(array $song): array
    {
        return $this->mapSong($song);
    }

    /**
     * Maps an album projection already authorized by a shared application service.
     *
     * Public-share adaptation uses this only after ShareService has repeated live owner/library
     * authorization. The method performs no lookup and emits a directory-like ID3 object without
     * private preferences, physical paths, or an invented cover when none was projected.
     */
    public function mapAuthorizedAlbum(array $album): array
    {
        return $this->mapAlbum($album) + ['isDir' => true, 'type' => 'album'];
    }

    /**
     * Returns ID3 artists grouped by stable ASCII initials, optionally within one granted library.
     *
     * Subsonic does not paginate this endpoint. The response therefore uses the same bounded 10,100
     * item safety ceiling as getIndexes; no offset clamp can repeat a page indefinitely.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @return array{artists: array{ignoredArticles: string, index: list<array{name: string, artist: list<array<string, mixed>>}>}}
     */
    public function artists(array $actor, mixed $musicFolderId): array
    {
        $this->requirePlay($actor);
        $libraryId = $this->optionalLibrary($actor, $musicFolderId);
        $groups = [];
        foreach ($this->artistPages($actor, $libraryId) as $artist) {
            $name = is_string($artist['name'] ?? null) ? $artist['name'] : '';
            $id = is_string($artist['id'] ?? null) ? $artist['id'] : '';
            if ($name === '' || $id === '') {
                continue;
            }
            $sortName = is_string($artist['sortName'] ?? null) && $artist['sortName'] !== ''
                ? $artist['sortName']
                : $name;
            $groups[$this->initial($sortName)][] = $this->mapArtist($artist);
        }
        uksort($groups, static fn (string $left, string $right): int => $left === '#'
            ? -1
            : ($right === '#' ? 1 : strcmp($left, $right)));
        $indexes = [];
        foreach ($groups as $name => $items) {
            $indexes[] = ['name' => $name, 'artist' => $items];
        }

        return ['artists' => [
            'ignoredArticles' => 'The El La Los Las Le Les',
            'index' => $indexes,
        ]];
    }

    /** Returns one authorized artist plus authorized albums; top songs remain a separate endpoint. */
    public function artist(array $actor, mixed $artistId): array
    {
        $this->requirePlay($actor);
        $artistId = $this->requiredId($artistId);
        try {
            $detail = $this->media->artistDetail($actor, $artistId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Artist was not found.', previous: $exception);
        }
        $artist = $this->mapArtist($detail['artist']);
        $artist['album'] = array_map(fn (array $album): array => $this->mapAlbum($album), $detail['albums']);

        return ['artist' => $artist];
    }

    /** Returns one authorized album with songs in shared disc/track order. */
    public function album(array $actor, mixed $albumId): array
    {
        $this->requirePlay($actor);
        $albumId = $this->requiredId($albumId);
        try {
            $detail = $this->media->albumDetail($actor, $albumId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Album was not found.', previous: $exception);
        }
        $album = $this->mapAlbum($detail['album']);
        $album['song'] = array_map(
            fn (array $song): array => $this->mapSong($song),
            $detail['songs'],
        );

        return ['album' => $album];
    }

    /**
     * 返回经过实时授权的艺人图片与本地相似艺人信息。
     *
     * 艺人详情仍由 MediaQueryService 从可播放歌曲关系证明可见性；全局艺人词条或图片候选本身不能
     * 授权。没有已确认图片时返回真正的空对象，不伪造外部头像；有图片时三个规格都指向同源的短时
     * 加密票据端点，并分别请求有界尺寸。URL 只使用部署显式配置的可信 Origin，不读取 Host，也绝不
     * 回显 salt/token；图片子请求即使不附加协议认证，仍会通过票据恢复账号并实时复验权限。相似
     * 艺人只来自共同署名或共同流派的授权本地歌曲；读取不访问第三方、不写图片选择或推断关系。
     */
    public function artistInfo(array $actor, mixed $artistId, bool $id3): array
    {
        $this->requirePlay($actor);
        $artistId = $this->requiredId($artistId);
        try {
            $detail = $this->media->artistDetail($actor, $artistId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Artist was not found.', previous: $exception);
        }
        $artist = is_array($detail['artist'] ?? null) ? $detail['artist'] : [];
        $artistName = is_string($artist['name'] ?? null) ? trim($artist['name']) : '';
        $albumCount = max(0, (int) ($artist['albumCount'] ?? 0));
        $songCount = max(0, (int) ($artist['songCount'] ?? 0));
        $profile = is_array($artist['profile'] ?? null) ? $artist['profile'] : [];
        $biography = is_string($profile['biography'] ?? null) && trim($profile['biography']) !== ''
            ? trim($profile['biography'])
            : sprintf(
                '当前授权曲库收录%s的 %d 张专辑和 %d 首歌曲。',
                $artistName === '' ? '该艺人' : $artistName,
                $albumCount,
                $songCount,
            );
        $info = ['biography' => $biography];
        $this->optional($info, 'musicBrainzId', is_string($profile['musicBrainzId'] ?? null)
            ? $profile['musicBrainzId'] : null);
        if (is_string($artist['imageUrl'] ?? null)) {
            try {
                $baseUrl = $this->artworkTickets->createUrl($actor, $artistId);
                $info += [
                    'smallImageUrl' => $baseUrl . '?size=160',
                    'mediumImageUrl' => $baseUrl . '?size=320',
                    'largeImageUrl' => $baseUrl . '?size=640',
                ];
            } catch (\Throwable) {
                // 图片票据配置失败不能阻断本地相似艺人；只省略三档图片字段。
            }
        }
        $similar = $this->media->similarArtists($actor, $artistId, 20);
        if ($similar !== []) {
            $info['similarArtist'] = array_map(fn (array $item): array => $this->mapArtist($item), $similar);
        }

        return [$id3 ? 'artistInfo2' : 'artistInfo' => $info === [] ? new stdClass() : $info];
    }

    /**
     * Returns a protocol-compatible empty album-info object after live album authorization.
     *
     * Local catalog fields remain available from getAlbum; this endpoint is reserved for external
     * notes, provider URLs, and provider images. Until a licensed provider is configured, the empty
     * object truthfully represents absence and performs no network request, cache write, or scan.
     */
    public function albumInfo(array $actor, mixed $albumId, bool $id3): array
    {
        $this->requirePlay($actor);
        $albumId = $this->requiredId($albumId);
        try {
            $this->media->albumDetail($actor, $albumId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Album was not found.', previous: $exception);
        }

        return [$id3 ? 'albumInfo2' : 'albumInfo' => new stdClass()];
    }

    /**
     * 返回精确艺人名称下的本地热门歌曲，并支持 OpenSubsonic 艺人 ID 扩展。
     *
     * OpenSubsonic `topSongsByArtistId` v1 使用 `id` 参数且优先于标准 `artist` 名称参数；同时保留部分
     * 客户端把 ULID 错放进 `artist` 的兼容分支。名称按索引规范名精确匹配，避免模糊查询混入无关
     * 艺人。count 默认 50、最大 500；空授权结果成功返回空数组，不访问外部 Provider 或写播放统计。
     */
    public function topSongs(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        if (array_key_exists('id', $parameters)) {
            $artistId = $this->requiredId($parameters['id']);
            $songs = $this->media->topSongsByArtistId(
                $actor,
                $artistId,
                $this->boundedInteger($parameters['count'] ?? 50, 1, 500),
            );

            return ['topSongs' => ['song' => array_map(
                fn (array $song): array => $this->mapSong($song),
                $songs,
            )]];
        }
        $artist = $parameters['artist'] ?? null;
        if (!is_string($artist) || trim($artist) === '' || mb_strlen(trim($artist), 'UTF-8') > 255) {
            throw new SubsonicRequestInvalid('Artist name is missing or invalid.');
        }
        $count = $this->boundedInteger($parameters['count'] ?? 50, 1, 500);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', trim($artist)) === 1) {
            $songs = $this->media->topSongsByArtistId($actor, trim($artist), $count);
        } else {
            $normalized = (new SearchTextNormalizer())->normalize($artist);
            if ($normalized === '') {
                throw new SubsonicRequestInvalid('Artist name is invalid.');
            }
            $songs = $this->media->topSongsByArtist($actor, $normalized, $count);
        }

        return ['topSongs' => ['song' => array_map(
            fn (array $song): array => $this->mapSong($song),
            $songs,
        )]];
    }

    /**
     * 返回基于本地共同艺人、专辑和流派关系的授权相似歌曲。
     *
     * `id` 仍严格表示歌曲而不是艺人；种子和每个候选均由 MediaQueryService 重新应用实时媒体权限。
     * 本地没有关系时返回空列表，不调用外部 Provider，也不把播放统计或共享词表当成访问授权。
     */
    public function similarSongs(array $actor, array $parameters, bool $id3): array
    {
        $this->requirePlay($actor);
        $songId = $this->requiredId($parameters['id'] ?? null);
        try {
            $songs = $this->media->similarSongs(
                $actor,
                $songId,
                $this->boundedInteger($parameters['count'] ?? 50, 1, 500),
            );
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Seed song was not found.');
        }

        return [$id3 ? 'similarSongs2' : 'similarSongs' => ['song' => array_map(
            fn (array $song): array => $this->mapSong($song),
            $songs,
        )]];
    }

    /**
     * Returns all visible genre aggregates under Subsonic's unpaged response shape.
     *
     * Each JSON genre has `value`; SubsonicResponseFactory renders that key as XML element text.
     * Counts are distinct authorized song/album counts from MediaQueryService, never global totals.
     *
     * @return array{genres: array{genre: list<array{value: string, songCount: int, albumCount: int}>}}
     */
    public function genres(array $actor, mixed $musicFolderId): array
    {
        $this->requirePlay($actor);
        $libraryId = $this->optionalLibrary($actor, $musicFolderId);
        $items = [];
        for ($offset = 0; $offset <= 10_000 && count($items) < self::MAX_UNPAGED_ITEMS; $offset += 100) {
            $page = $this->media->genres($actor, $libraryId, 100, $offset);
            foreach ($page['genres'] as $genre) {
                $items[] = [
                    'value' => (string) ($genre['name'] ?? ''),
                    'songCount' => (int) ($genre['songCount'] ?? 0),
                    'albumCount' => (int) ($genre['albumCount'] ?? 0),
                ];
            }
            if (count($items) >= $page['total'] || $page['genres'] === []) {
                break;
            }
        }

        return ['genres' => ['genre' => array_slice($items, 0, self::MAX_UNPAGED_ITEMS)]];
    }

    /**
     * Executes Subsonic `search3` with independent artist/album/song windows.
     *
     * The protocol exposes three count/offset pairs, so each section receives its own bounded shared
     * search command. An absent/empty query or conventional `*` means authorized catalog browsing;
     * text queries retain normalized literal matching. Counts are capped at Subsonic's common 500
     * item window while Web search keeps its independent smaller validator. Raw queries are never
     * logged or persisted and offsets remain capped at 10,000.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @param array<string, mixed> $parameters Merged Subsonic query/form parameters.
     * @return array{searchResult3: array{artist: list<array<string, mixed>>, album: list<array<string, mixed>>, song: list<array<string, mixed>>}}
     */
    public function search3(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $queryValue = $parameters['query'] ?? '';
        if (!is_string($queryValue) || mb_strlen(trim($queryValue), 'UTF-8') > 100) {
            throw new SubsonicRequestInvalid('Search query is invalid.');
        }
        $query = trim($queryValue);
        $browseAll = $query === '' || $query === '*';
        $normalized = $browseAll ? '' : (new SearchTextNormalizer())->normalize($query);
        if (!$browseAll && $normalized === '') {
            throw new SubsonicRequestInvalid('Search query is invalid.');
        }
        $libraryId = $this->optionalLibrary($actor, $parameters['musicFolderId'] ?? null);
        $windows = [
            'artists' => [
                $this->boundedInteger($parameters['artistCount'] ?? 20, 0, 500),
                $this->boundedInteger($parameters['artistOffset'] ?? 0, 0, 10_000),
            ],
            'albums' => [
                $this->boundedInteger($parameters['albumCount'] ?? 20, 0, 500),
                $this->boundedInteger($parameters['albumOffset'] ?? 0, 0, 10_000),
            ],
            'songs' => [
                $this->boundedInteger($parameters['songCount'] ?? 20, 0, 500),
                $this->boundedInteger($parameters['songOffset'] ?? 0, 0, 10_000),
            ],
        ];
        $result = ['artist' => [], 'album' => [], 'song' => []];
        foreach ($windows as $type => [$limit, $offset]) {
            if ($limit === 0) {
                continue;
            }
            if ($browseAll) {
                $items = $this->browseSection($actor, $type, $libraryId, $limit, $offset);
                $result[match ($type) {
                    'artists' => 'artist',
                    'albums' => 'album',
                    default => 'song',
                }] = match ($type) {
                    'artists' => array_map(fn (array $item): array => $this->mapArtist($item), $items),
                    'albums' => array_map(fn (array $item): array => $this->mapAlbum($item), $items),
                    default => array_map(fn (array $item): array => $this->mapSong($item), $items),
                };
                continue;
            }
            $page = $this->media->search($actor, new SearchQueryInput(
                query: $query,
                normalizedQuery: $normalized,
                type: $type,
                libraryId: $libraryId,
                limit: $limit,
                offset: $offset,
            ));
            $items = $page['results'][$type];
            $result[match ($type) {
                'artists' => 'artist',
                'albums' => 'album',
                default => 'song',
            }] = match ($type) {
                'artists' => array_map(fn (array $item): array => $this->mapArtist($item), $items),
                'albums' => array_map(fn (array $item): array => $this->mapAlbum($item), $items),
                default => array_map(fn (array $item): array => $this->mapSong($item), $items),
            };
        }

        return ['searchResult3' => $result];
    }

    /**
     * 为 Subsonic 空搜索聚合稳定的授权目录页，最多读取客户端请求的 500 项。
     *
     * MediaQueryService 的常规页固定最多 100 项，因此这里从已校验 offset 开始连续读取；任何短页都
     * 立即终止。每页重新应用活动库、可用文件、元数据成功和实时 grant，聚合不缓存、不写搜索历史，
     * 也不会通过总数或隐藏行填充扩大授权范围。
     *
     * @param 'artists'|'albums'|'songs' $type
     * @return list<array<string, mixed>>
     */
    private function browseSection(
        array $actor,
        string $type,
        ?string $libraryId,
        int $limit,
        int $offset,
    ): array {
        $items = [];
        while (count($items) < $limit && $offset <= 10_000) {
            $pageSize = min(100, $limit - count($items));
            $page = match ($type) {
                'artists' => $this->media->artists($actor, $libraryId, $pageSize, $offset),
                'albums' => $this->media->albums($actor, $libraryId, $pageSize, $offset),
                default => $this->media->songs($actor, $libraryId, $pageSize, $offset),
            };
            $pageItems = is_array($page[$type] ?? null) ? $page[$type] : [];
            $items = [...$items, ...$pageItems];
            if (count($pageItems) < $pageSize) {
                break;
            }
            $offset += count($pageItems);
        }

        return $items;
    }

    /**
     * Exposes search2 over the same authorized ID3 search implementation as search3.
     *
     * Both supported contracts use identical query/count/offset/musicFolderId semantics here. Only
     * the response member changes, keeping normalization, wildcard escaping, bounded pagination,
     * live grants, and non-persistence of the raw query in one implementation.
     */
    public function search2(array $actor, array $parameters): array
    {
        $result = $this->search3($actor, $parameters);

        return ['searchResult2' => $result['searchResult3']];
    }

    /**
     * Returns a stable daily random song window using Subsonic discovery filters.
     *
     * `size` is 1-500 and defaults to 10. Optional years are inclusive and each must remain in
     * 1000-3000; reverse bounds are accepted as the same set because random results have no year
     * ordering. Genre is normalized then matched exactly by MediaQueryService, so wildcard-looking
     * input remains literal. The operation is read-only, does not mutate playback statistics, and
     * applies live music-folder authorization before selecting any row.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @param array<string, mixed> $parameters Merged Subsonic query/form parameters.
     * @return array{randomSongs: array{song: list<array<string, mixed>>}}
     */
    public function randomSongs(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $songs = $this->media->randomSongs(
            $actor,
            $this->optionalLibrary($actor, $parameters['musicFolderId'] ?? null),
            $this->boundedInteger($parameters['size'] ?? 10, 1, 500),
            $this->optionalGenre($parameters['genre'] ?? null),
            $this->optionalYear($parameters['fromYear'] ?? null),
            $this->optionalYear($parameters['toYear'] ?? null),
        );

        return ['randomSongs' => [
            'song' => array_map(fn (array $song): array => $this->mapSong($song), $songs),
        ]];
    }

    /**
     * Pages songs for one exact genre name without exposing the internal genre vocabulary ID.
     *
     * `genre` is required and bounded to 255 UTF-8 characters; `count` defaults to 10 and is capped
     * at 500, while offset is capped at 10,000 for the current SQLite query contract. An unknown
     * genre returns an empty successful list. A hidden music folder remains indistinguishable from
     * other missing catalog data and cannot be reached through a globally shared genre row.
     *
     * @param array<string, mixed> $actor Authenticated principal with live grants.
     * @param array<string, mixed> $parameters Merged Subsonic query/form parameters.
     * @return array{songsByGenre: array{song: list<array<string, mixed>>}}
     */
    public function songsByGenre(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $songs = $this->media->songsByGenreName(
            $actor,
            $this->requiredGenre($parameters['genre'] ?? null),
            $this->optionalLibrary($actor, $parameters['musicFolderId'] ?? null),
            $this->boundedInteger($parameters['count'] ?? 10, 1, 500),
            $this->boundedInteger($parameters['offset'] ?? 0, 0, 10_000),
        );

        return ['songsByGenre' => [
            'song' => array_map(fn (array $song): array => $this->mapSong($song), $songs),
        ]];
    }

    /**
     * 实现 ID3 专辑列表端点的完整 Subsonic 类型词表。
     *
     * `type` 使用严格白名单；`byYear` 要求两个年份并保留反向区间表示倒序的协议规则，`byGenre` 要求
     * 精确流派名，`highest` 只返回当前用户已评分专辑。收藏、评分与播放事实只作用于已经授权的专辑，
     * random 页面按用户和 UTC 日期稳定轮转；所有类型均只读，不刷新评分时间或写播放统计。
     *
     * @param array<string, mixed> $actor 已认证主体及个人数据所有者。
     * @param array<string, mixed> $parameters 合并后的 Subsonic query/form 参数。
     * @return array{albumList2: array{album: list<array<string, mixed>>}}
     */
    public function albumList2(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $type = $parameters['type'] ?? null;
        $types = [
            'random', 'newest', 'frequent', 'recent', 'highest',
            'alphabeticalByName', 'alphabeticalByArtist', 'starred', 'byYear', 'byGenre',
        ];
        if (!is_string($type) || !in_array($type, $types, true)) {
            throw new SubsonicRequestInvalid('Album list type is missing or invalid.');
        }
        $fromYear = $type === 'byYear' ? $this->requiredYear($parameters['fromYear'] ?? null) : null;
        $toYear = $type === 'byYear' ? $this->requiredYear($parameters['toYear'] ?? null) : null;
        $genre = $type === 'byGenre' ? $this->requiredGenre($parameters['genre'] ?? null) : null;
        $albums = $this->media->discoverAlbums(
            $actor,
            $type,
            $this->optionalLibrary($actor, $parameters['musicFolderId'] ?? null),
            $this->boundedInteger($parameters['size'] ?? 10, 1, 500),
            $this->boundedInteger($parameters['offset'] ?? 0, 0, 10_000),
            $fromYear,
            $toYear,
            $genre,
        );

        return ['albumList2' => [
            'album' => array_map(fn (array $album): array => $this->mapAlbum($album), $albums),
        ]];
    }

    /**
     * Converts ID3 album discovery to legacy directory children without restoring physical paths.
     *
     * Filtering, sorting, personal statistics, bounds, and music-folder authorization remain owned
     * by albumList2. This compatibility layer adds only label-derived Child fields and deliberately
     * omits a physical parent/path because Velin's media identity is independent of filenames.
     */
    public function albumList(array $actor, array $parameters): array
    {
        $albums = $this->albumList2($actor, $parameters)['albumList2']['album'];

        return ['albumList' => ['album' => array_map(
            fn (array $album): array => $this->legacyAlbum($album),
            $albums,
        )]];
    }

    /**
     * Returns the current user's visible song, album, and artist favorites in ID3 form.
     *
     * Subsonic does not paginate `getStarred2`, so each category is read in stable 100-row pages and
     * capped at the same 10,100-item safety ceiling used by other unpaged compatibility endpoints.
     * Every page starts from MediaQueryService's live media scope before applying the current-user
     * favorite filter; stale preference rows therefore cannot reveal disabled, unavailable, or
     * revoked media. This read is side-effect free and does not refresh `favorited_at`.
     *
     * @param array<string, mixed> $actor Authenticated principal and preference owner.
     * @return array{starred2: array{artist: list<array<string, mixed>>, album: list<array<string, mixed>>, song: list<array<string, mixed>>}}
     */
    public function starred2(array $actor, mixed $musicFolderId): array
    {
        $this->requirePlay($actor);
        $libraryId = $this->optionalLibrary($actor, $musicFolderId);

        return ['starred2' => [
            'artist' => array_map(
                fn (array $artist): array => $this->mapArtist($artist),
                $this->favoritePages($actor, $libraryId, 'artists'),
            ),
            'album' => array_map(
                fn (array $album): array => $this->mapAlbum($album),
                $this->favoritePages($actor, $libraryId, 'albums'),
            ),
            'song' => array_map(
                fn (array $song): array => $this->mapSong($song),
                $this->favoritePages($actor, $libraryId, 'songs'),
            ),
        ]];
    }

    /** Returns legacy starred album children while preserving every starred2 isolation rule. */
    public function starred(array $actor, mixed $musicFolderId): array
    {
        $starred = $this->starred2($actor, $musicFolderId)['starred2'];
        $starred['album'] = array_map(
            fn (array $album): array => $this->legacyAlbum($album),
            $starred['album'],
        );

        return ['starred' => $starred];
    }

    /**
     * 把已授权艺人投影映射为 OpenSubsonic `ArtistID3`。
     *
     * MusicBrainz ID 和 roles 已由 MediaQueryService 按当前账号可见歌曲批量证明；本方法只省略缺失值并
     * 保留协议定义的 `artist|albumartist` 小写角色，不读取全局关系表，也不因字段缺失猜测艺人身份。
     * 收藏、评分和播放时间同样仅属于当前账号。映射过程只读、确定性且无外部请求。
     */
    private function mapArtist(array $artist): array
    {
        $result = [
            'id' => (string) ($artist['id'] ?? ''),
            'name' => (string) ($artist['name'] ?? ''),
            'albumCount' => (int) ($artist['albumCount'] ?? 0),
        ];
        $this->optional($result, 'sortName', is_string($artist['sortName'] ?? null) ? $artist['sortName'] : null);
        if (is_string($artist['imageUrl'] ?? null) && $result['id'] !== '') {
            $result['coverArt'] = 'ar:' . $result['id'];
        }
        $preferences = is_array($artist['preferences'] ?? null) ? $artist['preferences'] : [];
        $this->preferenceFields($result, $preferences);
        $this->optional(
            $result,
            'musicBrainzId',
            is_string($artist['musicBrainzId'] ?? null) && trim($artist['musicBrainzId']) !== ''
                ? trim($artist['musicBrainzId'])
                : null,
        );
        $roles = array_values(array_filter(
            is_array($artist['roles'] ?? null) ? $artist['roles'] : [],
            static fn (mixed $role): bool => $role === 'artist' || $role === 'albumartist',
        ));
        if ($roles !== []) {
            $result['roles'] = $roles;
        }

        return $result;
    }

    /**
     * 把已授权专辑投影映射为 OpenSubsonic `AlbumID3`。
     *
     * 多艺人与显示名只来自索引中的专辑署名；结构化发行日期仅接受经过日历校验的年、年月或完整日期，
     * 非法旧数据直接省略。MusicBrainz ID 使用发行版 ID 而不是 release-group ID；流派只聚合当前账号
     * 可见歌曲并输出协议的 `{name}` 列表。`played`、收藏与评分均属于当前账号，已由 MediaQueryService
     * 在实时授权范围内批量读取；本方法不查询数据库、不刷新播放时间，也不从歌曲或外部 Provider 猜测
     * 缺失字段。数据库虽保存 discTotal，但 AlbumID3 没有对应标量，不能伪装成 discTitles 输出。
     */
    private function mapAlbum(array $album): array
    {
        $artists = is_array($album['artists'] ?? null) ? $album['artists'] : [];
        $displayArtist = $this->displayArtist($artists);
        $result = [
            'id' => (string) ($album['id'] ?? ''),
            'name' => (string) ($album['title'] ?? ''),
            'songCount' => (int) ($album['songCount'] ?? 0),
            'duration' => intdiv(max(0, (int) ($album['durationMs'] ?? 0)), 1000),
            'artist' => $displayArtist,
            'artists' => $this->artistReferences($artists),
            'displayArtist' => $displayArtist,
        ];
        $firstArtistId = is_array($artists[0] ?? null) && is_string($artists[0]['id'] ?? null)
            ? $artists[0]['id']
            : null;
        $this->optional($result, 'artistId', $firstArtistId);
        $this->optional($result, 'year', is_int($album['releaseYear'] ?? null) ? $album['releaseYear'] : null);
        $releaseDate = is_string($album['releaseDate'] ?? null)
            ? $this->structuredDate($album['releaseDate'])
            : null;
        if ($releaseDate !== null) {
            $result['releaseDate'] = $releaseDate;
        }
        if (is_string($album['coverUrl'] ?? null)) {
            $result['coverArt'] = 'al:' . $result['id'];
        }
        $this->optional($result, 'created', $this->createdFromId($result['id']));
        $preferences = is_array($album['preferences'] ?? null) ? $album['preferences'] : [];
        $this->preferenceFields($result, $preferences);
        $this->optional(
            $result,
            'musicBrainzId',
            is_string($album['musicBrainzReleaseId'] ?? null) && trim($album['musicBrainzReleaseId']) !== ''
                ? trim($album['musicBrainzReleaseId'])
                : null,
        );
        $genres = [];
        foreach (is_array($album['genres'] ?? null) ? $album['genres'] : [] as $genre) {
            if (is_array($genre) && is_string($genre['name'] ?? null) && trim($genre['name']) !== '') {
                $genres[] = ['name' => trim($genre['name'])];
            }
        }
        if ($genres !== []) {
            $result['genres'] = $genres;
        }

        return $result;
    }

    /** Converts an already-authorized mapped album to the minimal legacy Child album shape. */
    private function legacyAlbum(array $album): array
    {
        $name = (string) ($album['name'] ?? '');

        return $album + [
            'isDir' => true,
            'title' => $name,
            'album' => $name,
            'albumId' => (string) ($album['id'] ?? ''),
            'type' => 'album',
        ];
    }

    /**
     * 把已授权歌曲投影转换为 Subsonic `Child`，供所有歌曲列表端点复用。
     *
     * `id`、`isDir` 和 `title` 是协议必填字段；音频条目同时固定输出 `isVideo=false`，避免严格客户端
     * 把缺失值当成未知媒体类型后丢弃整行。其余字段只来自已完成实时授权的路径无关投影，`path` 是
     * 标签合成的虚拟路径，不暴露库存或文件系统位置。专辑艺人来自同一已授权歌曲所属专辑的索引关系，
     * `played` 只来自当前账号统计；方法只做确定性映射，不查询数据库、不写播放统计。缺少可选标签时
     * 省略对应字段，不伪造 contributor ID，也不使同批歌曲失败。
     */
    private function mapSong(array $song, array $tags = [], array $genres = []): array
    {
        $artists = is_array($song['artists'] ?? null) ? $song['artists'] : [];
        $displayArtist = $this->displayArtist($artists);
        $album = is_array($song['album'] ?? null) ? $song['album'] : [];
        $albumArtists = is_array($album['artists'] ?? null) ? $this->artistReferences($album['artists']) : [];
        $audio = is_array($song['audio'] ?? null) ? $song['audio'] : [];
        $suffix = $this->suffix($audio);
        $track = is_int($song['trackNumber'] ?? null) ? $song['trackNumber'] : null;
        $title = (string) ($song['title'] ?? '');
        $albumTitle = (string) ($album['title'] ?? '');
        $result = [
            'id' => (string) ($song['id'] ?? ''),
            'parent' => (string) ($album['id'] ?? ''),
            'isDir' => false,
            'isVideo' => false,
            'title' => $title,
            'album' => $albumTitle,
            'artist' => $displayArtist,
            'contentType' => $this->contentType($suffix),
            'suffix' => $suffix,
            'duration' => intdiv(max(0, (int) ($song['durationMs'] ?? 0)), 1000),
            'path' => $this->virtualPath($displayArtist, $albumTitle, $title, $track, $suffix),
            'albumId' => (string) ($album['id'] ?? ''),
            'type' => 'music',
            'mediaType' => 'song',
            'artists' => $this->artistReferences($artists),
            'displayArtist' => $displayArtist,
        ];
        if ($albumArtists !== []) {
            $result['albumArtists'] = $albumArtists;
            $result['displayAlbumArtist'] = implode(', ', array_column($albumArtists, 'name'));
        }
        $firstArtistId = is_array($artists[0] ?? null) && is_string($artists[0]['id'] ?? null)
            ? $artists[0]['id']
            : null;
        $this->optional($result, 'artistId', $firstArtistId);
        $this->optional($result, 'track', $track);
        $this->optional($result, 'year', is_int($song['releaseYear'] ?? null) ? $song['releaseYear'] : null);
        $this->optional($result, 'discNumber', is_int($song['discNumber'] ?? null) ? $song['discNumber'] : null);
        $this->optional($result, 'bitRate', is_int($audio['bitrate'] ?? null) ? intdiv($audio['bitrate'], 1000) : null);
        $this->optional($result, 'bitDepth', is_int($audio['bitDepth'] ?? null) ? $audio['bitDepth'] : null);
        $this->optional($result, 'samplingRate', is_int($audio['sampleRate'] ?? null) ? $audio['sampleRate'] : null);
        $this->optional($result, 'channelCount', is_int($audio['channels'] ?? null) ? $audio['channels'] : null);
        $this->optional($result, 'sortName', is_string($song['sortTitle'] ?? null) ? $song['sortTitle'] : null);
        $this->optional($result, 'created', $this->createdFromId($result['id']));
        if (is_string($album['coverUrl'] ?? null) && $result['albumId'] !== '') {
            $result['coverArt'] = str_starts_with($album['coverUrl'], '/api/v1/songs/')
                ? 'so:' . $result['id']
                : 'al:' . $result['albumId'];
        }
        $preferences = is_array($song['preferences'] ?? null) ? $song['preferences'] : [];
        $this->preferenceFields($result, $preferences);

        $this->optional($result, 'comment', is_string($tags['comment'] ?? null) ? $tags['comment'] : null);
        $this->optional($result, 'bpm', is_numeric($tags['bpm'] ?? null) ? (int) round((float) $tags['bpm']) : null);
        $this->optional(
            $result,
            'displayComposer',
            is_string($tags['composer'] ?? null) && trim($tags['composer']) !== ''
                ? trim($tags['composer'])
                : null,
        );
        $this->optional($result, 'musicBrainzId', is_string($tags['musicBrainzTrackId'] ?? null) ? $tags['musicBrainzTrackId'] : null);
        if (is_string($tags['isrc'] ?? null) && $tags['isrc'] !== '') {
            $result['isrc'] = [$tags['isrc']];
        }
        if ($genres !== []) {
            $names = [];
            foreach ($genres as $genre) {
                if (is_array($genre) && is_string($genre['name'] ?? null) && $genre['name'] !== '') {
                    $names[] = $genre['name'];
                }
            }
            if ($names !== []) {
                $result['genre'] = implode(', ', $names);
                $result['genres'] = array_map(static fn (string $name): array => ['name' => $name], $names);
            }
        }
        $replayGain = is_array($tags['replayGain'] ?? null) ? array_filter([
            'trackGain' => $tags['replayGain']['trackGainDb'] ?? null,
            'trackPeak' => $tags['replayGain']['trackPeak'] ?? null,
            'albumGain' => $tags['replayGain']['albumGainDb'] ?? null,
            'albumPeak' => $tags['replayGain']['albumPeak'] ?? null,
        ], static fn (mixed $value): bool => $value !== null) : [];
        if ($replayGain !== []) {
            $result['replayGain'] = $replayGain;
        }

        return $result;
    }

    /**
     * 从服务端生成的时间有序 ULID 还原标准 UTC 创建时间。
     *
     * 媒体 ID 只在首次入库时生成，后续扫描和刮削保留，因此其时间与 created 的业务语义一致且不会
     * 暴露物理路径或库存身份。传入值已来自授权投影；异常旧值只省略可选字段，不使整批列表失败。
     */
    private function createdFromId(string $id): ?string
    {
        if (!Ulid::isValid($id)) {
            return null;
        }

        return Ulid::fromString($id)->getDateTime()->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * 把已规范化的媒体发行日期转换为 OpenSubsonic `ItemDate`。
     *
     * 扫描与刮削允许只知道年份或月份，因此缺失部分不会补成一月一日；完整日期使用 `checkdate` 阻止
     * 旧数据中的无效闰日或月份进入协议。方法无时区换算和写入副作用，无法证明有效时返回 null，由
     * 调用方省略整个可选字段。
     *
     * @return array{year:int,month?:int,day?:int}|null
     */
    private function structuredDate(string $value): ?array
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})$/D', $value, $matches) === 1) {
            return ['year' => (int) $matches[1]];
        }
        if (preg_match('/^(\d{4})-(\d{2})$/D', $value, $matches) === 1) {
            $month = (int) $matches[2];
            return $month >= 1 && $month <= 12
                ? ['year' => (int) $matches[1], 'month' => $month]
                : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1) {
            return null;
        }
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year)
            ? ['year' => $year, 'month' => $month, 'day' => $day]
            : null;
    }

    /** @return list<array{id: string, name: string}> */
    private function artistReferences(array $artists): array
    {
        $result = [];
        foreach ($artists as $artist) {
            if (is_array($artist) && is_string($artist['id'] ?? null) && is_string($artist['name'] ?? null)) {
                $result[] = ['id' => $artist['id'], 'name' => $artist['name']];
            }
        }

        return $result;
    }

    /** Joins only authorized, already projected artist names for legacy single-value fields. */
    private function displayArtist(array $artists): string
    {
        $names = array_column($this->artistReferences($artists), 'name');

        return $names === [] ? 'Unknown Artist' : implode(', ', $names);
    }

    /**
     * 输出当前用户已有的播放时间、收藏时间和 1..5 评分。
     *
     * 输入偏好由共享媒体查询在实时可见范围内加载；缺失状态不输出伪造的 false、0 或目录时间。本方法
     * 只修改待序列化数组，不读取或写入个人数据，重复映射不会刷新任何时间戳。
     */
    private function preferenceFields(array &$target, array $preferences): void
    {
        if (is_string($preferences['playedAt'] ?? null) && $preferences['playedAt'] !== '') {
            $target['played'] = $preferences['playedAt'];
        }
        if (($preferences['favorite'] ?? false) === true && is_string($preferences['favoritedAt'] ?? null)) {
            $target['starred'] = $preferences['favoritedAt'];
        }
        if (is_int($preferences['rating'] ?? null)
            && $preferences['rating'] >= 1 && $preferences['rating'] <= 5) {
            $target['userRating'] = $preferences['rating'];
        }
    }

    /** Returns artist pages without allowing MediaQueryService's 10,000 offset clamp to repeat. */
    private function artistPages(array $actor, ?string $libraryId): array
    {
        $items = [];
        for ($offset = 0; $offset <= 10_000 && count($items) < self::MAX_UNPAGED_ITEMS; $offset += 100) {
            $page = $this->media->artists($actor, $libraryId, 100, $offset);
            foreach ($page['artists'] as $artist) {
                $items[] = $artist;
            }
            if (count($items) >= $page['total'] || $page['artists'] === []) {
                break;
            }
        }

        return array_slice($items, 0, self::MAX_UNPAGED_ITEMS);
    }

    /**
     * Collects one unpaged favorite category through live authorization-safe media pages.
     *
     * `type` is supplied only by starred2's fixed call sites and selects both the repository method
     * and response collection key. The loop stops on reported total, an empty page, or the hard
     * safety ceiling; this avoids repeating MediaQueryService's clamped 10,000 offset forever.
     *
     * @param array<string, mixed> $actor Authenticated principal and preference owner.
     * @return list<array<string, mixed>> Authorized path-free media projections.
     */
    private function favoritePages(array $actor, ?string $libraryId, string $type): array
    {
        $items = [];
        for ($offset = 0; $offset <= 10_000 && count($items) < self::MAX_UNPAGED_ITEMS; $offset += 100) {
            $page = match ($type) {
                'songs' => $this->media->songs($actor, $libraryId, 100, $offset, null, true),
                'albums' => $this->media->albums($actor, $libraryId, 100, $offset, true),
                'artists' => $this->media->artists($actor, $libraryId, 100, $offset, true),
                default => throw new \LogicException('Unsupported favorite media type.'),
            };
            foreach ($page[$type] as $item) {
                $items[] = $item;
            }
            if (count($items) >= $page['total'] || $page[$type] === []) {
                break;
            }
        }

        return array_slice($items, 0, self::MAX_UNPAGED_ITEMS);
    }

    /** Validates an optional music-folder filter against the authenticator's live grant snapshot. */
    private function optionalLibrary(array $actor, mixed $musicFolderId): ?string
    {
        if ($musicFolderId === null || $musicFolderId === '') {
            return null;
        }
        if (!is_string($musicFolderId) || strlen($musicFolderId) > 64) {
            throw new SubsonicRequestInvalid('A Subsonic parameter is invalid.');
        }
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['id'] ?? null) === $musicFolderId) {
                return $musicFolderId;
            }
        }

        throw new SubsonicEntityNotFound('Music folder was not found.');
    }

    /** Rejects missing/array/oversized IDs before the shared service applies its strict ULID grammar. */
    private function requiredId(mixed $id): string
    {
        if (!is_string($id)) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is missing.');
        }
        $id = trim($id);
        if ($id === '' || strlen($id) > 64) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is invalid.');
        }

        return $id;
    }

    /** Parses one strict decimal integer and enforces its endpoint-specific inclusive range. */
    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new SubsonicRequestInvalid('A pagination parameter is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new SubsonicRequestInvalid('A pagination parameter is outside the supported range.');
        }

        return $integer;
    }

    /** Parses an optional strict year; supplied empty strings are invalid rather than absent. */
    private function optionalYear(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredYear($value);
    }

    /** Parses one required four-digit release year inside the catalog's supported domain. */
    private function requiredYear(mixed $value): int
    {
        return $this->boundedInteger($value, 1000, 3000);
    }

    /** Validates an optional exact genre label without logging or persisting the submitted text. */
    private function optionalGenre(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->requiredGenre($value);
    }

    /** Requires a bounded non-empty genre whose normalized representation can be queried exactly. */
    private function requiredGenre(mixed $value): string
    {
        if (!is_string($value)) {
            throw new SubsonicRequestInvalid('Genre is missing or invalid.');
        }
        $genre = trim($value);
        if ($genre === '' || mb_strlen($genre, 'UTF-8') > 255
            || (new SearchTextNormalizer())->normalize($genre) === '') {
            throw new SubsonicRequestInvalid('Genre is missing or invalid.');
        }

        return $genre;
    }

    /** Enforces global play independently from object/library scope. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic catalog browsing requires play capability.');
        }
    }

    /** Groups ASCII names predictably and leaves non-ASCII transliteration for a versioned index. */
    private function initial(string $name): string
    {
        $first = function_exists('mb_substr') ? mb_substr(trim($name), 0, 1, 'UTF-8') : substr(trim($name), 0, 1);
        $first = strtoupper($first);

        return preg_match('/^[A-Z]$/', $first) === 1 ? $first : '#';
    }

    /** Selects only a bounded alphanumeric indexed container/codec as the virtual suffix. */
    private function suffix(array $audio): string
    {
        foreach ([$audio['container'] ?? null, $audio['codec'] ?? null] as $candidate) {
            if (is_string($candidate) && preg_match('/^[a-z0-9]{1,10}$/', strtolower($candidate)) === 1) {
                return strtolower($candidate);
            }
        }

        return 'bin';
    }

    /** Maps a known suffix to protocol metadata without using it as an actual stream header. */
    private function contentType(string $suffix): string
    {
        return match ($suffix) {
            'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'm4a', 'mp4' => 'audio/mp4',
            'aac' => 'audio/aac', 'ogg', 'opus' => 'audio/ogg', 'wav', 'wave' => 'audio/wav',
            'aif', 'aiff' => 'audio/aiff', 'wma' => 'audio/x-ms-wma', 'ape' => 'audio/x-ape',
            default => 'application/octet-stream',
        };
    }

    /** Creates a metadata-only relative display path and strips separators/control characters. */
    private function virtualPath(string $artist, string $album, string $title, ?int $track, string $suffix): string
    {
        $prefix = $track === null ? '' : str_pad((string) $track, 2, '0', STR_PAD_LEFT) . ' - ';

        return $this->segment($artist, 'Unknown Artist') . '/'
            . $this->segment($album, 'Unknown Album') . '/'
            . $prefix . $this->segment($title, 'Unknown Song') . '.' . $suffix;
    }

    /** Sanitizes one label segment without touching the filesystem or accepting traversal syntax. */
    private function segment(string $value, string $fallback): string
    {
        $value = preg_replace('/[\\x00-\\x1F\\x7F\\\\\/]+/u', ' - ', trim($value));
        $value = is_string($value) ? preg_replace('/\\s+/u', ' ', $value) : '';
        $value = is_string($value) ? trim($value, " .\t\n\r\0\x0B") : '';

        return $value === '' ? $fallback : $value;
    }

    /** Adds an optional scalar only when present, keeping XML free of empty attributes. */
    private function optional(array &$target, string $name, string|int|float|null $value): void
    {
        if ($value !== null && $value !== '') {
            $target[$name] = $value;
        }
    }
}
