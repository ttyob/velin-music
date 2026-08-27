<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 为歌词管理工作台提供受实时音乐库授权约束的只读投影。
 *
 * 该服务存在是因为管理员可能拥有 `edit_metadata` 而没有普通播放能力，不能借用用户端目录接口
 * 扩大或误收紧权限。Controller 负责全局 capability，本服务对每个查询再次应用 read/manage grant；
 * 超级管理员可查看全部启用库。列表不读取正文，单曲详情才解码结构化行，任何投影都不返回文件路径、
 * 来源定位摘要、内容摘要或内部文件身份。方法不写数据库、不访问文件系统，失败不会回退到无范围查询。
 *
 * SQLite 的 `EXISTS`、绑定参数和普通排序均可直接迁移到 MySQL；后续迁移不得依赖 rowid、JSON1 或
 * SQLite 专属聚合函数。
 */
final class LyricsAdminQueryService
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.85;
    private const SOURCES = ['sidecar', 'embedded', 'provider', 'manual'];
    private const KINDS = ['plain', 'line', 'word'];

    public function __construct(private readonly LyricsFileStore $files = new LyricsFileStore()) {}

    /**
     * 返回有界歌曲页及无正文歌词版本摘要。
     *
     * `state=missing` 表示当前没有任何歌词版本，`available` 表示至少有一个版本。语言、同步类型和
     * 来源筛选使用同一个歌词版本满足全部条件的 `EXISTS` 子查询，避免把不同版本的证据错误拼接。
     * 搜索只匹配歌曲标题、专辑标题和艺术家名称，最多 100 字符；分页上限保护管理请求不会遍历全库。
     *
     * @param array<string,mixed> $actor 已通过全局 `edit_metadata` 校验的当前身份快照。
     * @return array<string,mixed> 路径无关的歌曲页和当前可见音乐库筛选项。
     */
    public function page(
        array $actor,
        ?string $libraryId,
        ?string $state,
        ?string $language,
        ?string $kind,
        ?string $source,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        $this->validateFilters($libraryId, $state, $language, $kind, $source, $search, $limit, $offset);
        $query = $this->scopedSongs($actor);
        if ($libraryId !== null) {
            $query->where('songs.library_id', $libraryId);
        }

        $hasLyricFilters = $language !== null || $kind !== null || $source !== null;
        $anyLyricExists = function (Builder $lyrics): void {
            $lyrics->selectRaw('1')->from('media_lyrics as any_lyrics')
                ->whereColumn('any_lyrics.song_id', 'songs.id');
        };
        $filteredLyricExists = function (Builder $lyrics) use ($language, $kind, $source): void {
            $lyrics->selectRaw('1')->from('media_lyrics as filtered_lyrics')
                ->whereColumn('filtered_lyrics.song_id', 'songs.id');
            if ($language !== null) {
                $lyrics->where('filtered_lyrics.language', $language);
            }
            if ($kind !== null) {
                $lyrics->where('filtered_lyrics.lyric_kind', $kind);
            }
            if ($source !== null) {
                $lyrics->where('filtered_lyrics.source_kind', $source);
            }
        };
        if ($state === 'missing') {
            $query->whereNotExists($anyLyricExists);
        } elseif ($state === 'available') {
            $query->whereExists($anyLyricExists);
        } elseif ($state === 'parse_error') {
            $query->whereExists(function (Builder $diagnostics): void {
                $diagnostics->selectRaw('1')->from('media_lyrics_parse_diagnostics as lyric_errors')
                    ->whereColumn('lyric_errors.song_id', 'songs.id');
            });
        } elseif ($state === 'low_confidence') {
            $query->whereExists(function (Builder $lyrics): void {
                $lyrics->selectRaw('1')->from('media_lyrics as low_confidence_lyrics')
                    ->whereColumn('low_confidence_lyrics.song_id', 'songs.id')
                    ->where('low_confidence_lyrics.source_kind', 'provider')
                    ->whereNotNull('low_confidence_lyrics.match_score')
                    ->where('low_confidence_lyrics.match_score', '<', self::LOW_CONFIDENCE_THRESHOLD);
            });
        }
        if ($hasLyricFilters) {
            $query->whereExists($filteredLyricExists);
        }
        if ($search !== null) {
            $pattern = '%' . $this->escapeLike($search) . '%';
            $query->where(function (Builder $matching) use ($pattern): void {
                $matching->whereRaw("songs.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("albums.title LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereExists(function (Builder $artists) use ($pattern): void {
                        $artists->selectRaw('1')->from('media_song_artists as song_artists')
                            ->join('media_artists as artists', 'artists.id', '=', 'song_artists.artist_id')
                            ->whereColumn('song_artists.song_id', 'songs.id')
                            ->whereRaw("artists.name LIKE ? ESCAPE '\\'", [$pattern]);
                    });
            });
        }

        $total = (clone $query)->count('songs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.title')->orderBy('songs.id')->offset($offset)->limit($limit)->get([
            'songs.id', 'songs.title', 'songs.duration_ms', 'songs.album_id',
            'albums.title as album_title', 'libraries.id as library_id', 'libraries.name as library_name',
        ])->all();
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $lyrics = $this->lyricSummaries($songIds);
        $artists = $this->artistSummaries($songIds);

        return [
            'songs' => array_map(
                fn (stdClass $row): array => $this->mapSong($row, $artists, $lyrics),
                $rows,
            ),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filterOptions' => ['libraries' => $this->visibleLibraries($actor)],
        ];
    }

    /**
     * 返回一首当前可管理歌曲及其结构化歌词正文。
     *
     * 正文只用于已显式打开的单曲对照视图，不参与列表、任务、方案、审计或日志。无效 ID、对象不存在、
     * 媒体不可用和 grant 已撤销统一抛出 not found，避免通过管理端枚举其他库。持久 JSON 损坏时抛出
     * 固定异常，Controller 只记录异常类，绝不记录原始正文。
     *
     * @param array<string,mixed> $actor 已通过全局能力校验的身份快照。
     * @return array<string,mixed> 包含结构化行但不含路径或来源定位信息的单曲投影。
     * @throws LyricsWritebackNotFound 对象不可见或不存在。
     * @throws LyricsFileUnavailable 歌词索引对应文件缺失、越界或内容身份已经漂移。
     */
    public function song(string $songId, array $actor): array
    {
        $this->requireUlid($songId, '歌曲标识无效。');
        /** @var stdClass|null $row */
        $row = $this->scopedSongs($actor)->where('songs.id', $songId)->first([
            'songs.id', 'songs.title', 'songs.duration_ms', 'songs.album_id',
            'albums.title as album_title', 'libraries.id as library_id', 'libraries.name as library_name',
        ]);
        if (!$row instanceof stdClass) {
            throw new LyricsWritebackNotFound('歌曲不存在或不可管理。');
        }

        $artists = $this->artistSummaries([$songId]);
        /** @var list<stdClass> $lyricRows */
        $lyricRows = Db::table('media_lyrics as lyrics')
            ->leftJoin('media_lyrics_primary_selections as primary_selection', 'primary_selection.song_id', '=', 'lyrics.song_id')
            ->where('lyrics.song_id', $songId)
            ->orderByRaw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 0 ELSE 1 END')
            ->orderByDesc('lyrics.priority')->orderBy('lyrics.language')->orderBy('lyrics.id')->get([
                'lyrics.id', 'lyrics.source_kind', 'lyrics.language', 'lyrics.lyric_kind', 'lyrics.source_format',
                'lyrics.priority', 'lyrics.match_score', 'lyrics.license_policy', 'lyrics.version', 'lyrics.updated_at',
                'primary_selection.version as primary_selection_version',
                Db::raw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 1 ELSE 0 END as is_primary'),
            ])->all();
        $versions = [];
        foreach ($lyricRows as $lyric) {
            $lines = $this->files->readById((string) $lyric->id)->lines;
            $summary = $this->mapLyricSummary($lyric);
            $summary['lines'] = $lines;
            $versions[] = $summary;
        }

        $song = $this->mapSong($row, $artists, [$songId => array_map(
            fn (stdClass $lyric): array => $this->mapLyricSummary($lyric),
            $lyricRows,
        )]);
        $song['lyrics'] = $versions;

        return $song;
    }

    /** 构造歌曲、可用清单和启用音乐库的统一实时授权范围。 */
    private function scopedSongs(array $actor): Builder
    {
        $query = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready')
            ->where('libraries.status', 'active');
        $this->scope($query, $actor, 'songs.library_id');

        return $query;
    }

    /** @param list<string> $songIds @return array<string,list<array<string,mixed>>> */
    private function lyricSummaries(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }
        $result = [];
        foreach (Db::table('media_lyrics as lyrics')
            ->leftJoin('media_lyrics_primary_selections as primary_selection', 'primary_selection.song_id', '=', 'lyrics.song_id')
            ->whereIn('lyrics.song_id', $songIds)->orderBy('lyrics.song_id')
            ->orderByRaw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 0 ELSE 1 END')
            ->orderByDesc('lyrics.priority')->orderBy('lyrics.language')->orderBy('lyrics.id')->get([
                'lyrics.id', 'lyrics.song_id', 'lyrics.source_kind', 'lyrics.language', 'lyrics.lyric_kind', 'lyrics.source_format',
                'lyrics.priority', 'lyrics.match_score', 'lyrics.license_policy', 'lyrics.version', 'lyrics.updated_at',
                'primary_selection.version as primary_selection_version',
                Db::raw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 1 ELSE 0 END as is_primary'),
            ]) as $row) {
            $result[(string) $row->song_id][] = $this->mapLyricSummary($row);
        }

        return $result;
    }

    /** @param list<string> $songIds @return array<string,list<array{id:string,name:string}>> */
    private function artistSummaries(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }
        $result = [];
        foreach (Db::table('media_song_artists as credits')
            ->join('media_artists as artists', 'artists.id', '=', 'credits.artist_id')
            ->whereIn('credits.song_id', $songIds)->orderBy('credits.song_id')
            ->orderBy('credits.position')->orderBy('artists.id')
            ->get(['credits.song_id', 'artists.id', 'artists.name']) as $row) {
            $result[(string) $row->song_id][] = ['id' => (string) $row->id, 'name' => (string) $row->name];
        }

        return $result;
    }

    /** @return list<array{id:string,name:string}> */
    private function visibleLibraries(array $actor): array
    {
        $query = Db::table('music_libraries as libraries')->where('libraries.status', 'active');
        $this->scope($query, $actor, 'libraries.id');

        return $query->orderBy('libraries.name')->get(['libraries.id', 'libraries.name'])
            ->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])->all();
    }

    /**
     * 把一行歌曲映射为稳定的路径无关工作台契约。
     *
     * @param array<string,list<array{id:string,name:string}>> $artists
     * @param array<string,list<array<string,mixed>>> $lyrics
     * @return array<string,mixed>
     */
    private function mapSong(stdClass $row, array $artists, array $lyrics): array
    {
        $versions = $lyrics[(string) $row->id] ?? [];

        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'artists' => $artists[(string) $row->id] ?? [],
            'album' => ['id' => (string) $row->album_id, 'title' => (string) $row->album_title],
            'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
            'durationMs' => (int) $row->duration_ms,
            'lyricCount' => count($versions),
            'lyrics' => $versions,
        ];
    }

    /** @return array<string,mixed> */
    private function mapLyricSummary(stdClass $row): array
    {
        $license = (string) $row->license_policy;
        $kind = (string) $row->lyric_kind;

        return [
            'id' => (string) $row->id,
            'source' => (string) $row->source_kind,
            'language' => (string) $row->language,
            'kind' => $kind,
            'format' => (string) $row->source_format,
            'priority' => (int) $row->priority,
            'matchScore' => $row->match_score === null ? null : (float) $row->match_score,
            'licensePolicy' => $license,
            'version' => (int) $row->version,
            'isPrimary' => (int) ($row->is_primary ?? 0) === 1,
            'primarySelectionVersion' => $row->primary_selection_version === null
                ? null : (int) $row->primary_selection_version,
            'updatedAt' => (string) $row->updated_at,
            // 逐字版本会在创建 Dry Run 时由 Enhanced LRC 序列化器做无损能力预检。
            'canWriteback' => in_array($license, ['local_controlled', 'redistributable'], true),
        ];
    }

    /** 应用与歌词写回一致的 read/manage grant；无授权时使用永不命中的集合。 */
    private function scope(Builder $query, array $actor, string $column): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) {
            return;
        }
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)) {
                $ids[] = $library['id'];
            }
        }
        $query->whereIn($column, array_values(array_unique($ids)) ?: ['']);
    }

    /** 对所有筛选先做白名单和边界校验，禁止数组或模糊类型进入查询构造。 */
    private function validateFilters(
        ?string $libraryId,
        ?string $state,
        ?string $language,
        ?string $kind,
        ?string $source,
        ?string $search,
        int $limit,
        int $offset,
    ): void {
        if ($libraryId !== null) {
            $this->requireUlid($libraryId, '音乐库筛选无效。');
        }
        if ($state !== null && !in_array($state, ['missing', 'available', 'parse_error', 'low_confidence'], true)) {
            throw new LyricsWritebackInvalid('歌词状态筛选无效。');
        }
        if ($language !== null && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1
            && $language !== 'und') {
            throw new LyricsWritebackInvalid('歌词语言筛选无效。');
        }
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            throw new LyricsWritebackInvalid('歌词同步类型筛选无效。');
        }
        if ($source !== null && !in_array($source, self::SOURCES, true)) {
            throw new LyricsWritebackInvalid('歌词来源筛选无效。');
        }
        if ($search !== null && (mb_strlen($search) < 1 || mb_strlen($search) > 100)) {
            throw new LyricsWritebackInvalid('搜索词长度无效。');
        }
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) {
            throw new LyricsWritebackInvalid('分页参数无效。');
        }
    }

    /** 转义 LIKE 通配符，使搜索词保持字面量语义并继续通过参数绑定执行。 */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** 在访问数据库前拒绝畸形对象 ID，防止未来漏写等值条件造成枚举。 */
    private function requireUlid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new LyricsWritebackInvalid($message);
        }
    }
}
