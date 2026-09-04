<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Metadata\MetadataEntityRedirectResolver;
use app\application\Media\SongDuplicateRedirectResolver;
use stdClass;
use support\Db;

/**
 * 在实时音乐库授权和文件身份校验后解析歌曲、专辑或艺术家图片。
 *
 * 专辑必须仍有至少一首可用且元数据成功的歌曲；超级管理员也必须在 Controller 层具备全局 `play`
 * 能力。sidecar 路径由库存音频目录和扫描器保存的安全文件名重建；embedded 来源先复验音频库存身份，
 * 再通过固定流序号从私有缓存读取或重新物化。专辑或艺人没有可用专属图时，可回退到同一授权库内
 * 代表歌曲已经确认的 Provider 图片；回退仍从可用歌曲关系证明访问权，不能由候选或缓存反向授予媒体
 * 可见性。所有来源都不会向响应暴露物理路径，缓存也不能绕过账号和音乐库授权。此服务只读音乐库，
 * 不修改音频、标签、图片源文件或管理员已经确认的图片选择。
 */
final class ArtworkService
{
    public function __construct(
        private readonly EmbeddedArtworkSource $embedded = new EmbeddedArtworkExtractor(),
        private readonly ArtworkBlobStore $blobs = new ArtworkBlobStore(),
    )
    {
    }

    /**
     * 解析专辑专属图，并在专属来源不存在或扫描身份失效时回退代表歌曲图片。
     *
     * 优先级固定为“专辑 Provider/上传选择 -> 有效本地/内嵌图 -> 同专辑已选歌曲图”。专辑选择的
     * BLOB 若损坏仍失败关闭，避免用回退掩盖管理员确认数据损坏；本地图因移动、重挂载或重扫前身份
     * 失效时允许回退，因为歌曲候选自身保存了独立摘要和规范化字节。所有查询都要求专辑仍有当前账号
     * 可播放歌曲，授权撤销后既有 URL 立即不可用。方法只可能物化私有摘要缓存，不写业务数据库。
     *
     * @param array<string, mixed> $actor 已由 Controller 证明具有全局 play 能力的账号。
     * @throws ArtworkNotFound 专辑非法、不存在、不可播放、未授权且没有可用回退。
     * @throws ArtworkUnavailable 专属选择损坏，或专属与回退来源均无法通过运行时校验。
     */
    public function resolveAlbum(array $actor, string $albumId): ResolvedArtwork
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $albumId) !== 1) {
            throw new ArtworkNotFound('Artwork not found.');
        }
        $albumId = (new MetadataEntityRedirectResolver())->resolve('album', $albumId);
        $schema = Db::connection()->getSchemaBuilder();
        $manual = $schema->hasTable('media_artwork_selection_overrides')
            && $schema->hasColumn('media_artwork_selection_overrides', 'album_id')
            ? $this->manualAlbum($actor, $albumId)
            : null;
        if ($manual instanceof stdClass) {
            return $this->resolveManual($manual, $albumId);
        }
        $songFallback = $this->manualAlbumSongFallback($actor, $albumId);
        $query = Db::table('media_album_artworks as artwork')
            ->join('media_albums as albums', 'albums.id', '=', 'artwork.album_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'albums.library_id')
            ->join('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
            ->join('media_songs as visible_songs', 'visible_songs.album_id', '=', 'albums.id')
            ->join('library_file_inventory as visible_files', 'visible_files.id', '=', 'visible_songs.inventory_file_id')
            ->where('albums.id', $albumId)
            ->where('libraries.status', 'active')
            ->where('source.status', 'available')
            ->where('visible_files.status', 'available')
            ->where('visible_files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as artwork_grant', function ($join) use ($actor): void {
                $join->on('artwork_grant.library_id', '=', 'albums.library_id')
                    ->where('artwork_grant.user_id', '=', (string) $actor['id']);
            });
        }
        /** @var stdClass|null $row */
        $row = $query->first([
            'artwork.album_id', 'artwork.source_file_name', 'artwork.source_kind',
            'artwork.embedded_stream_index', 'artwork.mime_type', 'artwork.file_size',
            'artwork.modified_at', 'artwork.device_id', 'artwork.inode', 'artwork.content_sha256',
            'source.resolved_path as source_audio_path', 'source.device_id as source_device_id',
            'source.inode as source_inode', 'source.file_size as source_file_size',
            'source.modified_at as source_modified_at', 'libraries.resolved_root_path',
        ]);
        if (!$row instanceof stdClass) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $albumId,
                new ArtworkNotFound('Artwork not found.'),
            );
        }

        if ((string) $row->source_kind === 'embedded') {
            try {
                return $this->resolveEmbeddedAlbum($row);
            } catch (ArtworkUnavailable $failure) {
                return $this->resolveFallbackOrThrow($songFallback, $albumId, $failure);
            }
        }
        if ((string) $row->source_kind !== 'sidecar') {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $albumId,
                new ArtworkUnavailable('ARTWORK_SOURCE_UNSUPPORTED'),
            );
        }

        $registeredRoot = (string) $row->resolved_root_path;
        $registered = dirname((string) $row->source_audio_path)
            . DIRECTORY_SEPARATOR . (string) $row->source_file_name;
        $currentPath = realpath($registered);
        $rootPrefix = rtrim($registeredRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($currentPath === false
            || $currentPath !== $registered
            || !str_starts_with($currentPath, $rootPrefix)
            || !is_file($currentPath)
            || !is_readable($currentPath)
            || is_link($registered)
        ) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $albumId,
                new ArtworkUnavailable('ARTWORK_PATH_CHANGED'),
            );
        }
        $stat = @stat($currentPath);
        if (!is_array($stat)
            || (int) $stat['dev'] !== (int) $row->device_id
            || (int) $stat['ino'] !== (int) $row->inode
            || (int) $stat['size'] !== (int) $row->file_size
            || (int) $stat['mtime'] !== (int) $row->modified_at
            || (int) $stat['size'] <= 0
        ) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $albumId,
                new ArtworkUnavailable('ARTWORK_IDENTITY_CHANGED'),
            );
        }

        return new ResolvedArtwork(
            albumId: (string) $row->album_id,
            path: $currentPath,
            mimeType: (string) $row->mime_type,
            fileSize: (int) $row->file_size,
            modifiedAt: (int) $row->modified_at,
            etag: '"' . (string) $row->content_sha256 . '"',
        );
    }

    /**
     * 解析一首可见歌曲的有效封面，歌曲手工选择优先，缺失时回退所属专辑。
     *
     * 歌曲选择记录不授予播放权；查询必须同时证明歌曲库存 available、元数据 ready、音乐库 active，
     * 普通账号还必须保有库 grant。回退调用 `resolveAlbum` 再次执行专辑级授权和源文件身份校验，不会
     * 因知道 song ID 绕过访问范围。歌曲候选损坏时稳定失败，不静默回退到专辑，以便管理员发现已选
     * 数据损坏；没有歌曲选择才执行正常回退。方法只物化私有缓存，不修改音频或图片来源。
     */
    public function resolveSong(array $actor, string $songId): ResolvedArtwork
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
            throw new ArtworkNotFound('Artwork not found.');
        }
        // 重定向只替换查询 ID；目标歌曲仍需通过下方实时库存、活动库和账号授权，缓存或旧链接不会
        // 因此获得额外访问范围。强证据失效时解析器保持原 ID，让来源重新按自身封面事实处理。
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'songs.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        /** @var stdClass|null $song */
        $song = $query->first(['songs.id', 'songs.album_id', 'songs.library_id']);
        if (!$song instanceof stdClass) throw new ArtworkNotFound('Artwork not found.');

        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('media_artwork_selection_overrides')
            && $schema->hasColumn('media_artwork_selection_overrides', 'song_id')) {
            $manual = $this->manualSong($songId, (string) $song->library_id);
            if ($manual instanceof stdClass) return $this->resolveManual($manual, $songId);
        }
        return $this->resolveAlbum($actor, (string) $song->album_id);
    }

    /**
     * 解析已授权 embedded 行，并在必要时从同一音频流重新生成私有缓存。
     *
     * 数据库流序号只在迁移后的 embedded 行允许使用。调用前查询已证明专辑、源库存和可见歌曲均活动；
     * 本方法继续要求源音频真实路径位于登记库根，且当前 dev/inode/size/mtime 同时匹配库存与封面索引
     * 快照。物化结果还会核对 MIME、字节数和 SHA-256。任一步失败都返回稳定不可用状态，不回退到未
     * 校验字节，也不修改源音频。
     */
    private function resolveEmbeddedAlbum(stdClass $row): ResolvedArtwork
    {
        $streamIndex = $row->embedded_stream_index === null ? null : (int) $row->embedded_stream_index;
        $audioPath = (string) $row->source_audio_path;
        $currentPath = realpath($audioPath);
        $rootPrefix = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($streamIndex === null || $streamIndex < 0 || $streamIndex > 4096
            || $currentPath === false || $currentPath !== $audioPath
            || !str_starts_with($currentPath, $rootPrefix)
            || !is_file($currentPath) || !is_readable($currentPath) || is_link($audioPath)
        ) {
            throw new ArtworkUnavailable('ARTWORK_PATH_CHANGED');
        }
        $stat = @stat($currentPath);
        if (!is_array($stat)
            || (int) $stat['dev'] !== (int) $row->source_device_id
            || (int) $stat['ino'] !== (int) $row->source_inode
            || (int) $stat['size'] !== (int) $row->source_file_size
            || (int) $stat['mtime'] !== (int) $row->source_modified_at
            || (int) $stat['dev'] !== (int) $row->device_id
            || (int) $stat['ino'] !== (int) $row->inode
            || (int) $stat['mtime'] !== (int) $row->modified_at
        ) {
            throw new ArtworkUnavailable('ARTWORK_IDENTITY_CHANGED');
        }
        try {
            $path = $this->embedded->materialize(
                $currentPath,
                $streamIndex,
                (string) $row->mime_type,
                (int) $row->file_size,
                (string) $row->content_sha256,
            );
        } catch (EmbeddedArtworkExtractionFailed) {
            throw new ArtworkUnavailable('EMBEDDED_ARTWORK_UNAVAILABLE');
        }

        return new ResolvedArtwork(
            albumId: (string) $row->album_id,
            path: $path,
            mimeType: (string) $row->mime_type,
            fileSize: (int) $row->file_size,
            modifiedAt: (int) $row->modified_at,
            etag: '"' . (string) $row->content_sha256 . '"',
        );
    }

    /**
     * 解析当前账号可见音乐库中的一张艺人图片。
     *
     * 艺人全局词条和图片记录都不能授予访问权；Provider/上传选择及本地扫描图分别查询，但都必须经
     * `media_song_artists` 证明目标艺人在同一库内仍有可播放歌曲。普通账号实时复验 library grant，
     * 因此客串艺人无需成为专辑主创即可读取图片，授权撤销也不会被已物化缓存绕过。选择损坏时不会
     * 静默回退，避免隐藏管理数据问题；没有专属选择时先读取并复验本地图片，本地来源不存在或身份
     * 失效才回退该艺人署名歌曲的已选图片。方法不修改图片来源或音频文件，仅可能把已校验的数据库
     * BLOB 物化到私有摘要缓存。
     *
     * @param array<string, mixed> $actor 已认证且由控制器证明具备全局 play 能力的账号。
     * @throws ArtworkNotFound ID 非法、艺人无当前可播放歌曲或账号已失去库授权。
     * @throws ArtworkUnavailable 已选图片字节或本地文件身份不再满足保存时约束。
     */
    public function resolveArtist(array $actor, string $artistId): ResolvedArtwork
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $artistId) !== 1) {
            throw new ArtworkNotFound('Artwork not found.');
        }
        $artistId = (new MetadataEntityRedirectResolver())->resolve('artist', $artistId);
        $schema = Db::connection()->getSchemaBuilder();
        $manual = $schema->hasTable('media_artwork_selection_overrides')
            && $schema->hasColumn('media_artwork_selection_overrides', 'artist_id')
            ? $this->manualArtist($actor, $artistId)
            : null;
        if ($manual instanceof stdClass) {
            return $this->resolveManual($manual, $artistId);
        }
        $songFallback = $this->manualArtistSongFallback($actor, $artistId);
        $query = Db::table('media_artist_artworks as artwork')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'artwork.library_id')
            ->join('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
            ->join('media_song_artists as artist_songs', 'artist_songs.artist_id', '=', 'artwork.artist_id')
            ->join('media_songs as visible_songs', function ($join): void {
                $join->on('visible_songs.id', '=', 'artist_songs.song_id')
                    ->on('visible_songs.library_id', '=', 'artwork.library_id');
            })
            ->join('library_file_inventory as visible_files', 'visible_files.id', '=', 'visible_songs.inventory_file_id')
            ->where('artwork.artist_id', $artistId)
            ->where('libraries.status', 'active')
            ->where('source.status', 'available')
            ->where('visible_files.status', 'available')
            ->where('visible_files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as artwork_grant', function ($join) use ($actor): void {
                $join->on('artwork_grant.library_id', '=', 'artwork.library_id')
                    ->where('artwork_grant.user_id', '=', (string) $actor['id']);
            });
        }
        /** @var stdClass|null $row */
        $row = $query->orderByDesc('artwork.selection_priority')->orderByDesc('artwork.modified_at')
            ->orderBy('artwork.selection_key')->first([
                'artwork.artist_id', 'artwork.source_file_name', 'artwork.directory_levels_up',
                'artwork.mime_type', 'artwork.file_size', 'artwork.modified_at', 'artwork.device_id',
                'artwork.inode', 'artwork.content_sha256', 'source.resolved_path as source_audio_path',
                'libraries.resolved_root_path',
        ]);
        if (!$row instanceof stdClass) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $artistId,
                new ArtworkNotFound('Artwork not found.'),
            );
        }

        $directory = dirname((string) $row->source_audio_path);
        for ($level = 0; $level < (int) $row->directory_levels_up; ++$level) {
            $directory = dirname($directory);
        }
        $registered = $directory . DIRECTORY_SEPARATOR . (string) $row->source_file_name;
        $currentPath = realpath($registered);
        $rootPrefix = rtrim((string) $row->resolved_root_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($currentPath === false || $currentPath !== $registered || !str_starts_with($currentPath, $rootPrefix)
            || !is_file($currentPath) || !is_readable($currentPath) || is_link($registered)) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $artistId,
                new ArtworkUnavailable('ARTWORK_PATH_CHANGED'),
            );
        }
        $stat = @stat($currentPath);
        if (!is_array($stat)
            || (int) $stat['dev'] !== (int) $row->device_id
            || (int) $stat['ino'] !== (int) $row->inode
            || (int) $stat['size'] !== (int) $row->file_size
            || (int) $stat['mtime'] !== (int) $row->modified_at
            || (int) $stat['size'] <= 0) {
            return $this->resolveFallbackOrThrow(
                $songFallback,
                $artistId,
                new ArtworkUnavailable('ARTWORK_IDENTITY_CHANGED'),
            );
        }

        return new ResolvedArtwork(
            albumId: (string) $row->artist_id,
            path: $currentPath,
            mimeType: (string) $row->mime_type,
            fileSize: (int) $row->file_size,
            modifiedAt: (int) $row->modified_at,
            etag: '"' . (string) $row->content_sha256 . '"',
        );
    }

    /**
     * 读取已选手工专辑候选，同时证明专辑仍有当前账号可播放的可用歌曲。
     *
     * 选择记录本身不授予访问权；超级管理员仍需由 Controller 证明全局 play，普通账号还必须保有
     * library grant。查询只返回规范化图片字节和摘要，不返回创建者或管理审计。
     */
    private function manualAlbum(array $actor, string $albumId): ?stdClass
    {
        $query = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', 'candidates.id', '=', 'selections.candidate_id')
            ->join('media_albums as albums', 'albums.id', '=', 'selections.album_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'selections.library_id')
            ->join('media_songs as songs', 'songs.album_id', '=', 'albums.id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('selections.album_id', $albumId)->where('libraries.status', 'active')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'selections.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        return $query->first(['candidates.image_bytes', 'candidates.byte_size', 'candidates.mime_type',
            'candidates.content_sha256', 'selections.updated_at']);
    }

    /**
     * 读取已完成歌曲可见性校验后的独立选择。
     *
     * 调用者必须先证明同一 song/library 当前可播放；候选查询再次绑定实体与库，防止数据库异常关系把
     * 另一个歌曲或库的 BLOB 返回。字节摘要由 `resolveManual` 统一复验。
     */
    private function manualSong(string $songId, string $libraryId): ?stdClass
    {
        return Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.song_id', '=', 'selections.song_id')
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->where('selections.song_id', $songId)->where('selections.library_id', $libraryId)
            ->first(['candidates.image_bytes', 'candidates.byte_size', 'candidates.mime_type',
                'candidates.content_sha256', 'selections.updated_at']);
    }

    /**
     * 读取专辑内最新确认的歌曲图片，作为专辑专属来源不可用时的受控回退。
     *
     * 候选、选择、歌曲和音乐库四个身份必须一致；歌曲还必须 available、metadata ready 且位于活动库。
     * 普通账号实时复验 library grant。排序只决定同一专辑多个有效歌曲候选中的稳定代表项，不改变任何
     * 选择记录。滚动升级期间缺少歌曲选择列时返回 null，继续沿用原有专辑图片行为。
     *
     * @param array<string, mixed> $actor 当前账号。
     */
    private function manualAlbumSongFallback(array $actor, string $albumId): ?stdClass
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasTable('media_manual_artwork_candidates')
            || !$schema->hasColumn('media_artwork_selection_overrides', 'song_id')
            || !$schema->hasColumn('media_manual_artwork_candidates', 'song_id')) {
            return null;
        }
        $query = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.song_id', '=', 'selections.song_id')
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->join('media_songs as songs', function ($join): void {
                $join->on('songs.id', '=', 'selections.song_id')
                    ->on('songs.library_id', '=', 'selections.library_id');
            })
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.album_id', $albumId)->where('libraries.status', 'active')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'songs.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        return $query->orderByDesc('selections.updated_at')->orderBy('songs.id')->first([
            'candidates.image_bytes', 'candidates.byte_size', 'candidates.mime_type',
            'candidates.content_sha256', 'selections.updated_at',
        ]);
    }

    /**
     * 读取某个可见库内已选艺人候选。
     *
     * 选择、候选、艺人和音乐库四个身份必须一致，并从歌曲署名关系证明当前可见性，不能把“专辑主创”
     * 当成艺人目录的必要条件。普通账号还需实时命中同库 grant；跨库错误关系、不可用歌曲和解析失败
     * 歌曲都不会返回 BLOB。方法只读取候选，字节长度和摘要由 `resolveManual` 再次验证。
     */
    private function manualArtist(array $actor, string $artistId): ?stdClass
    {
        $query = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.artist_id', '=', 'selections.artist_id')
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->join('music_libraries as libraries', 'libraries.id', '=', 'selections.library_id')
            ->join('media_song_artists as song_artists', 'song_artists.artist_id', '=', 'selections.artist_id')
            ->join('media_songs as songs', function ($join): void {
                $join->on('songs.id', '=', 'song_artists.song_id')
                    ->on('songs.library_id', '=', 'selections.library_id');
            })
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('selections.artist_id', $artistId)->where('libraries.status', 'active')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'selections.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        return $query->orderBy('selections.library_id')->first(['candidates.image_bytes', 'candidates.byte_size',
            'candidates.mime_type', 'candidates.content_sha256', 'selections.updated_at']);
    }

    /**
     * 读取艺人署名歌曲中最新确认的图片，作为艺人专属来源不可用时的受控回退。
     *
     * 署名关系、歌曲选择和候选必须落在同一活动音乐库，且歌曲当前仍可播放；普通账号必须实时拥有该库
     * grant。艺人全局词条、另一音乐库的同名歌曲和候选 BLOB 都不能单独授予图片访问。该方法只选取
     * 稳定代表项，不持久化“艺人使用了歌曲封面”的派生关系，因而撤销授权后不会留下可访问缓存。
     *
     * @param array<string, mixed> $actor 当前账号。
     */
    private function manualArtistSongFallback(array $actor, string $artistId): ?stdClass
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_artwork_selection_overrides')
            || !$schema->hasTable('media_manual_artwork_candidates')
            || !$schema->hasColumn('media_artwork_selection_overrides', 'song_id')
            || !$schema->hasColumn('media_manual_artwork_candidates', 'song_id')) {
            return null;
        }
        $query = Db::table('media_artwork_selection_overrides as selections')
            ->join('media_manual_artwork_candidates as candidates', function ($join): void {
                $join->on('candidates.id', '=', 'selections.candidate_id')
                    ->on('candidates.song_id', '=', 'selections.song_id')
                    ->on('candidates.library_id', '=', 'selections.library_id');
            })
            ->join('media_songs as songs', function ($join): void {
                $join->on('songs.id', '=', 'selections.song_id')
                    ->on('songs.library_id', '=', 'selections.library_id');
            })
            ->join('media_song_artists as credits', 'credits.song_id', '=', 'songs.id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('credits.artist_id', $artistId)->where('libraries.status', 'active')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as grants', function ($join) use ($actor): void {
                $join->on('grants.library_id', '=', 'songs.library_id')
                    ->where('grants.user_id', '=', (string) $actor['id']);
            });
        }
        return $query->orderByDesc('selections.updated_at')->orderBy('songs.id')->first([
            'candidates.image_bytes', 'candidates.byte_size', 'candidates.mime_type',
            'candidates.content_sha256', 'selections.updated_at',
        ]);
    }

    /**
     * 在专属图片读取失败时解析已完成授权查询的歌曲回退，否则原样抛出原失败。
     *
     * 调用方必须保证 fallback 来自本类的专辑或艺人歌曲回退查询；本方法不接受请求字段，也不重新选择
     * 候选。回退字节继续执行长度、MIME 和 SHA-256 校验。没有回退时保留原错误分类，避免把路径失效
     * 错报为普通无图；回退物化失败同样向上抛出，不尝试未确认来源。
     *
     * @param ArtworkNotFound|ArtworkUnavailable $failure 专属来源的原始失败。
     */
    private function resolveFallbackOrThrow(
        ?stdClass $fallback,
        string $entityId,
        ArtworkNotFound|ArtworkUnavailable $failure,
    ): ResolvedArtwork {
        if ($fallback instanceof stdClass) {
            return $this->resolveManual($fallback, $entityId);
        }
        throw $failure;
    }

    /**
     * 验证数据库 BLOB 摘要并物化到私有摘要缓存，供现有文件响应和缩略图链路复用。
     *
     * 缓存名仅由内容摘要组成，目录固定且拒绝符号链接。临时文件以排他方式创建、落盘后原子发布；
     * 并发发布已存在同摘要文件视为成功。数据库 BLOB 始终是事实来源，缓存丢失可重建且不能授予权限。
     */
    private function resolveManual(stdClass $row, string $entityId): ResolvedArtwork
    {
        try {
            $bytes = $this->blobs->bytes($row);
        } catch (\RuntimeException) {
            throw new ArtworkUnavailable('MANUAL_ARTWORK_STORAGE_INVALID');
        }
        $digest = (string) $row->content_sha256;
        if ((string) $row->mime_type !== 'image/webp' || strlen($bytes) !== (int) $row->byte_size
            || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1 || !hash_equals($digest, hash('sha256', $bytes))) {
            throw new ArtworkUnavailable('MANUAL_ARTWORK_STORAGE_INVALID');
        }
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $root = rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'manual-artwork-cache';
        if ((!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) || is_link($root) || !is_writable($root)) {
            throw new ArtworkUnavailable('MANUAL_ARTWORK_CACHE_UNSAFE');
        }
        @chmod($root, 0700);
        $path = $root . DIRECTORY_SEPARATOR . $digest . '.webp';
        if (!is_file($path) || is_link($path) || filesize($path) !== strlen($bytes)
            || !hash_equals($digest, (string) @hash_file('sha256', $path))) {
            $temporary = $root . DIRECTORY_SEPARATOR . '.' . $digest . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $handle = @fopen($temporary, 'xb');
            if (!is_resource($handle)) throw new ArtworkUnavailable('MANUAL_ARTWORK_CACHE_WRITE_FAILED');
            try {
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                        || (function_exists('fsync') && !fsync($handle)) || !chmod($temporary, 0600)) {
                        throw new ArtworkUnavailable('MANUAL_ARTWORK_CACHE_WRITE_FAILED');
                    }
                } finally {
                    fclose($handle);
                    $handle = null;
                }
                if (!@rename($temporary, $path) && !is_file($path)) {
                    throw new ArtworkUnavailable('MANUAL_ARTWORK_CACHE_PUBLISH_FAILED');
                }
            } catch (\Throwable $error) {
                @unlink($temporary);
                throw $error;
            } finally {
                if (is_resource($handle)) fclose($handle);
            }
        }
        $modifiedAt = strtotime((string) $row->updated_at) ?: 0;
        return new ResolvedArtwork($entityId, $path, 'image/webp', strlen($bytes), $modifiedAt,
            '"manual-' . substr($digest, 0, 32) . '"');
    }
}
