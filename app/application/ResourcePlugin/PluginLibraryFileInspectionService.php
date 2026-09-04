<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\application\Artwork\ArtworkCurrentStateService;
use app\application\Lyrics\LyricsAdminQueryService;
use Illuminate\Database\Query\Builder;
use JsonException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 为受信插件页面提供本地音乐库文件与业务目录的只读对照。
 *
 * 该服务由核心拥有，插件只能通过固定 HTTP DTO 读取当前管理员可管理的本地库；它不会把音乐库根、
 * `resolved_path`、设备/inode、歌词存储定位或第三方资源地址交给插件。列表只查询数据库索引且不读取歌词
 * 正文，详情在重新验证歌曲范围后才调用歌词安全存储。所有方法都不扫描目录、不修改数据库、不触发刮削，
 * 因而可重复调用；数据库或歌词内容损坏时整次详情失败，不能返回一半可信、一半缺失的投影。
 */
final readonly class PluginLibraryFileInspectionService
{
    private const STATES = ['all', 'ready', 'scan_error', 'lyrics_missing', 'artwork_missing'];

    public function __construct(
        private LyricsAdminQueryService $lyrics = new LyricsAdminQueryService(),
        private ArtworkCurrentStateService $artwork = new ArtworkCurrentStateService(),
    ) {}

    /**
     * 返回当前管理员可管理本地库中的已索引音频文件页。
     *
     * 搜索和状态过滤在数据库完成，分页上限为 50；歌词只聚合数量，封面只投影当前是否可读取。超出范围的
     * libraryId 与不存在 ID 统一视为无效筛选，避免借插件页面枚举其他音乐库。offset 在数据减少后归一到
     * 最后有效页，前端必须采用响应 offset 更新分页控件。
     *
     * @param array<string,mixed> $actor 已通过 `manage_system` 与 `manage_library` 的实时身份快照
     * @return array<string,mixed>
     */
    public function page(
        array $actor,
        ?string $libraryId,
        ?string $search,
        string $state,
        int $limit,
        int $offset,
    ): array {
        $this->validatePage($libraryId, $search, $state, $limit, $offset);
        $libraries = $this->managedLocalLibraries($actor);
        if ($libraryId !== null && !isset($libraries[$libraryId])) {
            throw new PluginLibraryFileInspectionInvalid('LIBRARY_FILE_INSPECTION_LIBRARY_INVALID');
        }
        if ($libraries === []) {
            return ['files' => [], 'total' => 0, 'limit' => $limit, 'offset' => 0,
                'filterOptions' => ['libraries' => []], 'permissions' => $this->permissions($actor)];
        }

        $query = $this->scopedSongs(array_keys($libraries));
        if ($libraryId !== null) $query->where('songs.library_id', $libraryId);
        if ($search !== null) {
            $pattern = '%' . $this->escapeLike($search) . '%';
            $query->where(function (Builder $match) use ($pattern): void {
                $match->whereRaw("songs.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("albums.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("files.relative_path LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereExists(function (Builder $artists) use ($pattern): void {
                        $artists->selectRaw('1')->from('media_song_artists as credits')
                            ->join('media_artists as artists', 'artists.id', '=', 'credits.artist_id')
                            ->whereColumn('credits.song_id', 'songs.id')
                            ->whereRaw("artists.name LIKE ? ESCAPE '\\'", [$pattern]);
                    });
            });
        }
        if ($state === 'ready') $query->where('files.metadata_status', 'ready');
        elseif ($state === 'scan_error') $query->where('files.metadata_status', 'failed');
        elseif ($state === 'lyrics_missing') $query->whereNotExists(function (Builder $lyrics): void {
            $lyrics->selectRaw('1')->from('media_lyrics as lyric_rows')
                ->whereColumn('lyric_rows.song_id', 'songs.id');
        });
        elseif ($state === 'artwork_missing') $this->whereArtworkMissing($query);

        $total = (clone $query)->count('songs.id');
        if ($offset > 0 && $offset >= $total) {
            $offset = max(0, intdiv(max(0, $total - 1), $limit) * $limit);
        }
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('files.relative_path')->orderBy('songs.id')->offset($offset)->limit($limit)->get([
            'songs.id', 'songs.title', 'songs.album_id', 'songs.duration_ms', 'songs.codec_name',
            'songs.container_name', 'songs.bitrate', 'songs.bit_depth', 'songs.sample_rate', 'songs.channels',
            'songs.updated_at', 'albums.title as album_title', 'files.relative_path', 'files.extension',
            'files.file_size', 'files.modified_at', 'files.status as inventory_status', 'files.metadata_status',
            'libraries.id as library_id', 'libraries.name as library_name',
        ])->all();
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $artists = $this->songArtists($songIds);
        $lyricCounts = $this->lyricCounts($songIds);

        return [
            'files' => array_map(function (stdClass $row) use ($artists, $lyricCounts): array {
                $songId = (string) $row->id;
                $albumId = (string) $row->album_id;
                $libraryId = (string) $row->library_id;
                $relativePath = (string) $row->relative_path;
                return [
                    'songId' => $songId, 'title' => (string) $row->title,
                    'artists' => $artists[$songId] ?? [],
                    'album' => ['id' => $albumId, 'title' => (string) $row->album_title],
                    'library' => ['id' => $libraryId, 'name' => (string) $row->library_name],
                    'relativePath' => $relativePath, 'fileName' => basename($relativePath),
                    'extension' => (string) $row->extension, 'sizeBytes' => (int) $row->file_size,
                    'modifiedAt' => $this->timestamp((int) $row->modified_at),
                    'inventoryStatus' => (string) $row->inventory_status,
                    'metadataStatus' => (string) $row->metadata_status,
                    'audio' => $this->audio($row),
                    'lyrics' => ['count' => $lyricCounts[$songId]['count'] ?? 0,
                        'hasPrimary' => $lyricCounts[$songId]['hasPrimary'] ?? false],
                    'artwork' => $this->artworkSummary($songId, $libraryId),
                    'updatedAt' => (string) $row->updated_at,
                ];
            }, $rows),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'filterOptions' => ['libraries' => array_values($libraries)],
            'permissions' => $this->permissions($actor),
        ];
    }

    /**
     * 返回单个文件、歌曲字段来源、歌词正文和专辑的只读对照。
     *
     * 歌曲必须先命中本地、启用、available 且 actor 拥有 manage 的库范围；失权与不存在统一返回 not
     * found。字段 JSON 逐项严格解码，歌词再通过 LyricsFileStore 的根边界、身份摘要与大小限制读取。
     * 封面 URL 仅在当前状态服务确认可用后生成，不暴露候选图片路径或 Provider URL。
     *
     * @param array<string,mixed> $actor 当前授权主体
     * @return array<string,mixed>
     */
    public function detail(array $actor, string $songId): array
    {
        if (!$this->validUlid($songId)) throw new PluginLibraryFileInspectionNotFound();
        $libraries = $this->managedLocalLibraries($actor);
        if ($libraries === []) throw new PluginLibraryFileInspectionNotFound();
        /** @var stdClass|null $row */
        $row = $this->scopedSongs(array_keys($libraries))->where('songs.id', $songId)->first([
            'songs.id', 'songs.title', 'songs.album_id', 'songs.duration_ms', 'songs.codec_name',
            'songs.container_name', 'songs.bitrate', 'songs.bit_depth', 'songs.sample_rate', 'songs.channels',
            'songs.track_number', 'songs.track_total', 'songs.disc_number', 'songs.disc_total',
            'songs.release_date', 'songs.release_year', 'songs.composer', 'songs.comment', 'songs.bpm',
            'songs.isrc', 'songs.musicbrainz_track_id', 'songs.updated_at',
            'albums.title as album_title', 'albums.release_date as album_release_date',
            'albums.release_year as album_release_year', 'albums.disc_total as album_disc_total',
            'albums.song_count as album_song_count', 'albums.duration_ms as album_duration_ms',
            'files.relative_path', 'files.extension', 'files.file_size', 'files.modified_at',
            'files.status as inventory_status', 'files.metadata_status', 'files.metadata_error_code',
            'libraries.id as library_id', 'libraries.name as library_name',
        ]);
        if (!$row instanceof stdClass) throw new PluginLibraryFileInspectionNotFound();

        $artists = $this->songArtistDetails([$songId])[$songId] ?? [];
        $genres = $this->songGenres($songId);
        $albumArtists = $this->albumArtists((string) $row->album_id);
        $artworkAvailable = $this->artwork->exists('song', $songId, (string) $row->library_id);
        $albumArtworkAvailable = $this->artwork->exists(
            'album',
            (string) $row->album_id,
            (string) $row->library_id,
        );
        try {
            $lyrics = $this->lyrics->song($songId, $actor)['lyrics'] ?? [];
        } catch (Throwable $throwable) {
            if ((string) $row->metadata_status === 'ready') {
                throw new PluginLibraryFileInspectionUnavailable('LIBRARY_FILE_INSPECTION_LYRICS_UNAVAILABLE', 0, $throwable);
            }
            $lyrics = [];
        }

        return [
            'songId' => $songId,
            'source' => [
                'relativePath' => (string) $row->relative_path,
                'fileName' => basename((string) $row->relative_path),
                'extension' => (string) $row->extension, 'sizeBytes' => (int) $row->file_size,
                'modifiedAt' => $this->timestamp((int) $row->modified_at),
                'inventoryStatus' => (string) $row->inventory_status,
                'metadataStatus' => (string) $row->metadata_status,
                'metadataErrorCode' => $row->metadata_error_code === null ? null : (string) $row->metadata_error_code,
            ],
            'audio' => $this->audio($row),
            'database' => [
                'song' => [
                    'id' => $songId, 'title' => (string) $row->title,
                    'trackNumber' => $this->nullableInt($row->track_number),
                    'trackTotal' => $this->nullableInt($row->track_total),
                    'discNumber' => $this->nullableInt($row->disc_number),
                    'discTotal' => $this->nullableInt($row->disc_total),
                    'releaseDate' => $row->release_date === null ? null : (string) $row->release_date,
                    'releaseYear' => $this->nullableInt($row->release_year),
                    'composer' => $row->composer === null ? null : (string) $row->composer,
                    'comment' => $row->comment === null ? null : (string) $row->comment,
                    'bpm' => $row->bpm === null ? null : (float) $row->bpm,
                    'isrc' => $row->isrc === null ? null : (string) $row->isrc,
                    'musicbrainzTrackId' => $row->musicbrainz_track_id === null ? null : (string) $row->musicbrainz_track_id,
                    'updatedAt' => (string) $row->updated_at,
                ],
                'artists' => $artists, 'genres' => $genres,
                'fields' => $this->fieldStates($songId),
            ],
            'lyrics' => is_array($lyrics) ? $lyrics : [],
            'artwork' => ['available' => $artworkAvailable,
                'url' => $artworkAvailable ? '/api/v1/songs/' . rawurlencode($songId) . '/cover' : null],
            'album' => [
                'id' => (string) $row->album_id, 'title' => (string) $row->album_title,
                'releaseDate' => $row->album_release_date === null ? null : (string) $row->album_release_date,
                'releaseYear' => $this->nullableInt($row->album_release_year),
                'discTotal' => $this->nullableInt($row->album_disc_total),
                'songCount' => (int) $row->album_song_count, 'durationMs' => (int) $row->album_duration_ms,
                'artists' => $albumArtists,
                'artworkUrl' => $albumArtworkAvailable
                    ? '/api/v1/albums/' . rawurlencode((string) $row->album_id) . '/cover' : null,
            ],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'permissions' => $this->permissions($actor),
        ];
    }

    /**
     * 投影页面可用的核心编辑权限，不把能力判断委托给插件脚本。
     *
     * 文件检查本身只要求 `manage_system + manage_library`，缺少 `edit_metadata` 的管理员仍可只读浏览；
     * 前端据此隐藏写命令，但所有元数据、歌词和封面接口仍会独立重验 Cookie Session、CSRF、全局能力、
     * 逐库 manage 范围和乐观版本。该提示不是授权令牌，权限被撤销后旧页面提交仍会被核心拒绝。
     *
     * @param array<string,mixed> $actor 当前实时身份快照
     * @return array{canEditMetadata:bool}
     */
    private function permissions(array $actor): array
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        return ['canEditMetadata' => in_array('edit_metadata', $capabilities, true)];
    }

    /** @return array<string,array{id:string,name:string}> */
    private function managedLocalLibraries(array $actor): array
    {
        $query = Db::table('music_libraries')->where('status', 'active')->where('source_type', 'local');
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                    && is_string($library['id'] ?? null)) $ids[] = $library['id'];
            }
            $query->whereIn('id', array_values(array_unique($ids)) ?: ['']);
        }
        $result = [];
        foreach ($query->orderBy('name')->get(['id', 'name']) as $row) {
            $result[(string) $row->id] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }
        return $result;
    }

    /** @param list<string> $libraryIds */
    private function scopedSongs(array $libraryIds): Builder
    {
        return Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->whereIn('songs.library_id', $libraryIds ?: [''])
            ->where('libraries.status', 'active')->where('libraries.source_type', 'local')
            ->where('files.status', 'available');
    }

    private function whereArtworkMissing(Builder $query): void
    {
        $query->whereNotExists(function (Builder $override): void {
            $override->selectRaw('1')->from('media_artwork_selection_overrides as song_cover')
                ->whereColumn('song_cover.song_id', 'songs.id')
                ->whereColumn('song_cover.library_id', 'songs.library_id');
        })->whereNotExists(function (Builder $override): void {
            $override->selectRaw('1')->from('media_artwork_selection_overrides as album_cover')
                ->whereColumn('album_cover.album_id', 'songs.album_id')
                ->whereColumn('album_cover.library_id', 'songs.library_id');
        })->whereNotExists(function (Builder $cover): void {
            $cover->selectRaw('1')->from('media_album_artworks as album_artwork')
                ->join('library_file_inventory as artwork_file', 'artwork_file.id', '=', 'album_artwork.source_inventory_file_id')
                ->whereColumn('album_artwork.album_id', 'songs.album_id')->where('artwork_file.status', 'available');
        });
    }

    /** @param list<string> $songIds @return array<string,list<string>> */
    private function songArtists(array $songIds): array
    {
        $result = [];
        if ($songIds === []) return $result;
        foreach (Db::table('media_song_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('links.song_id', $songIds)->orderBy('links.song_id')->orderBy('links.position')
            ->get(['links.song_id', 'artists.name']) as $row) {
            $result[(string) $row->song_id][] = (string) $row->name;
        }
        return $result;
    }

    /** @param list<string> $songIds @return array<string,list<array<string,mixed>>> */
    private function songArtistDetails(array $songIds): array
    {
        $result = [];
        if ($songIds === []) return $result;
        foreach (Db::table('media_song_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->whereIn('links.song_id', $songIds)->orderBy('links.song_id')->orderBy('links.position')
            ->get(['links.song_id', 'artists.id', 'artists.name', 'links.role', 'links.position']) as $row) {
            $result[(string) $row->song_id][] = ['id' => (string) $row->id, 'name' => (string) $row->name,
                'role' => (string) $row->role, 'position' => (int) $row->position];
        }
        return $result;
    }

    /** @param list<string> $songIds @return array<string,array{count:int,hasPrimary:bool}> */
    private function lyricCounts(array $songIds): array
    {
        $result = [];
        if ($songIds === []) return $result;
        foreach (Db::table('media_lyrics as lyrics')
            ->leftJoin('media_lyrics_primary_selections as selections', 'selections.lyric_id', '=', 'lyrics.id')
            ->whereIn('lyrics.song_id', $songIds)->groupBy('lyrics.song_id')->get([
                'lyrics.song_id', Db::raw('COUNT(lyrics.id) AS lyric_count'),
                Db::raw('MAX(CASE WHEN selections.lyric_id IS NULL THEN 0 ELSE 1 END) AS has_primary'),
            ]) as $row) {
            $result[(string) $row->song_id] = ['count' => (int) $row->lyric_count,
                'hasPrimary' => (int) $row->has_primary === 1];
        }
        return $result;
    }

    /** @return list<array{id:string,name:string}> */
    private function albumArtists(string $albumId): array
    {
        return array_map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name],
            Db::table('media_album_artists as links')->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                ->where('links.album_id', $albumId)->orderBy('links.position')->get(['artists.id', 'artists.name'])->all());
    }

    /** @return list<array{id:string,name:string}> */
    private function songGenres(string $songId): array
    {
        return array_map(static fn (stdClass $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name],
            Db::table('media_song_genres as links')->join('media_genres as genres', 'genres.id', '=', 'links.genre_id')
                ->where('links.song_id', $songId)->orderBy('links.position')->get(['genres.id', 'genres.name'])->all());
    }

    /**
     * @return list<array{field:string,raw:mixed,scraped:mixed,manual:mixed,effective:mixed,source:string,locked:bool,version:int}>
     */
    private function fieldStates(string $songId): array
    {
        $result = [];
        foreach (Db::table('media_metadata_field_states')->where('song_id', $songId)->orderBy('field_key')->get() as $row) {
            try {
                $result[] = [
                    'field' => (string) $row->field_key,
                    'raw' => json_decode((string) $row->raw_value_json, true, 64, JSON_THROW_ON_ERROR),
                    'scraped' => $row->scraped_value_json === null ? null
                        : json_decode((string) $row->scraped_value_json, true, 64, JSON_THROW_ON_ERROR),
                    'manual' => $row->manual_value_json === null ? null
                        : json_decode((string) $row->manual_value_json, true, 64, JSON_THROW_ON_ERROR),
                    'effective' => json_decode((string) $row->effective_value_json, true, 64, JSON_THROW_ON_ERROR),
                    'source' => (string) $row->effective_source, 'locked' => (int) $row->is_locked === 1,
                    'version' => (int) $row->version,
                ];
            } catch (JsonException) {
                throw new PluginLibraryFileInspectionUnavailable('LIBRARY_FILE_INSPECTION_METADATA_INVALID');
            }
        }
        return $result;
    }

    /** @return array<string,int|string|null> */
    private function audio(stdClass $row): array
    {
        return ['durationMs' => (int) $row->duration_ms,
            'codec' => $row->codec_name === null ? null : (string) $row->codec_name,
            'container' => $row->container_name === null ? null : (string) $row->container_name,
            'bitrate' => $this->nullableInt($row->bitrate), 'bitDepth' => $this->nullableInt($row->bit_depth),
            'sampleRate' => $this->nullableInt($row->sample_rate), 'channels' => $this->nullableInt($row->channels)];
    }

    private function validatePage(?string $libraryId, ?string $search, string $state, int $limit, int $offset): void
    {
        if ($libraryId !== null && !$this->validUlid($libraryId)) throw new PluginLibraryFileInspectionInvalid();
        if ($search !== null && (mb_strlen($search, 'UTF-8') < 1 || mb_strlen($search, 'UTF-8') > 100)) {
            throw new PluginLibraryFileInspectionInvalid();
        }
        if (!in_array($state, self::STATES, true) || $limit < 1 || $limit > 50
            || $offset < 0 || $offset > 1_000_000) throw new PluginLibraryFileInspectionInvalid();
    }

    private function validUlid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function timestamp(int $value): ?string
    {
        return $value > 0 ? gmdate('c', $value) : null;
    }

    /** @return array{available:bool,url:?string} */
    private function artworkSummary(string $songId, string $libraryId): array
    {
        $available = $this->artwork->exists('song', $songId, $libraryId);
        return ['available' => $available,
            'url' => $available ? '/api/v1/songs/' . rawurlencode($songId) . '/cover' : null];
    }
}
