<?php

declare(strict_types=1);

namespace app\application\Artwork;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 为歌曲、艺术家或专辑封面搜索冻结一个可复验的无路径录音证据。
 *
 * 歌曲与专辑协议以 recording 为查询证据，艺人资料图协议还会携带实体名，但仍使用目标库中按
 * 专辑、碟号、曲号稳定排序的首个可用曲目证明平台内署名关系；歌曲直接使用自身。实体全部关联库必须处于 actor 的 manage 范围，请求库
 * 还必须属于该实体；随后复用歌词证据服务验证歌曲可用性及 read/manage grant。摘要额外绑定实体、
 * 音乐库和代表歌曲，使曲目移出实体、失效或描述证据变化都会让旧搜索和导入失败关闭。
 */
final class ArtworkProviderEvidenceService
{
    public function __construct(
        private readonly ArtworkSongEvidenceService $songs = new ArtworkSongEvidenceService(),
    ) {}

    /**
     * 返回当前 actor 可管理实体的冻结证据。
     *
     * @param array<string,mixed> $actor 已通过 `edit_metadata` 的身份快照。
     * @return array{type:string,entityId:string,entityName:string,libraryId:string,songId:string,evidence:array<string,mixed>,evidenceSha256:string,locale:string,region:string}
     */
    public function scoped(string $type, string $entityId, string $libraryId, array $actor): array
    {
        $this->requireUlid($entityId);
        $this->requireUlid($libraryId);
        if (!in_array($type, ['song', 'artist', 'album'], true)) throw new ArtworkAdminInvalid('实体类型无效。');
        $table = match ($type) {
            'song' => 'media_songs',
            'artist' => 'media_artists',
            default => 'media_albums',
        };
        /** @var stdClass|null $entity */
        $entity = Db::table($table)->where('id', $entityId)->first([
            'id', $type === 'artist' ? 'name' : 'title',
        ]);
        $requiredLibraries = $this->entityLibraryIds($type, $entityId);
        $managed = $this->managedLibraryIds($actor);
        if (!$entity instanceof stdClass || $requiredLibraries === [] || !in_array($libraryId, $requiredLibraries, true)
            || (($actor['isSuperAdmin'] ?? false) !== true && array_diff($requiredLibraries, $managed) !== [])) {
            throw new ArtworkAdminNotFound('实体不存在或不可管理。');
        }
        $entityName = (string) ($type === 'artist' ? $entity->name : $entity->title);

        $songId = $this->representativeSong($type, $entityId, $libraryId);
        $song = $this->songs->scoped($songId, $actor);
        $identity = [
            'type' => $type, 'entityId' => $entityId, 'entityName' => $entityName,
            'libraryId' => $libraryId, 'songId' => $songId, 'evidence' => $song['evidence'],
        ];
        $json = json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'type' => $type, 'entityId' => $entityId, 'entityName' => $entityName,
            'libraryId' => $libraryId, 'songId' => $songId, 'evidence' => $song['evidence'],
            'evidenceSha256' => hash('sha256', $json), 'locale' => $song['locale'], 'region' => $song['region'],
        ];
    }

    /**
     * 选择稳定代表曲目；查询不读取路径、原始标签、文件身份或歌词。
     *
     * 排序变化会由最终 evidence 摘要检测。没有可用且元数据 ready 的曲目时拒绝在线搜索，不用已丢失
     * 文件或其他音乐库的歌曲代替。
     */
    private function representativeSong(string $type, string $entityId, string $libraryId): string
    {
        if ($type === 'song') return $entityId;
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.library_id', $libraryId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        if ($type === 'album') {
            $query->where('songs.album_id', $entityId);
        } else {
            $query->join('media_song_artists as artist_links', 'artist_links.song_id', '=', 'songs.id')
                ->where('artist_links.artist_id', $entityId);
        }
        /** @var stdClass|null $row */
        $row = $this->ordered($query)->first(['songs.id']);
        if (!$row instanceof stdClass) throw new ArtworkAdminConflict('实体没有可用于远程封面匹配的歌曲。');
        return (string) $row->id;
    }

    /** 稳定排序避免数据库自然顺序把同一实体随机绑定到不同证据。 */
    private function ordered(Builder $query): Builder
    {
        return $query->orderBy('songs.album_id')->orderBy('songs.disc_number')->orderBy('songs.track_number')
            ->orderBy('songs.id');
    }

    /** @return list<string> 歌曲/专辑返回唯一事实库；艺术家覆盖歌曲和专辑署名的全部库。 */
    private function entityLibraryIds(string $type, string $entityId): array
    {
        if ($type !== 'artist') {
            $table = $type === 'song' ? 'media_songs' : 'media_albums';
            $library = Db::table($table)->where('id', $entityId)->value('library_id');
            return is_string($library) ? [$library] : [];
        }
        $songs = Db::table('media_song_artists as links')->join('media_songs as songs', 'songs.id', '=', 'links.song_id')
            ->where('links.artist_id', $entityId)->distinct()->pluck('songs.library_id')->map('strval')->all();
        $albums = Db::table('media_album_artists as links')->join('media_albums as albums', 'albums.id', '=', 'links.album_id')
            ->where('links.artist_id', $entityId)->distinct()->pluck('albums.library_id')->map('strval')->all();
        return array_values(array_unique(array_merge($songs, $albums)));
    }

    /** @return list<string> 只有 manage grant 可满足共享实体范围；read 只允许消费媒体。 */
    private function managedLibraryIds(array $actor): array
    {
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && ($library['accessLevel'] ?? null) === 'manage') $ids[] = $library['id'];
        }
        return array_values(array_unique($ids));
    }

    /** ULID 校验只验证形状；存在性和授权由实体与歌曲范围查询共同决定。 */
    private function requireUlid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) throw new ArtworkAdminInvalid('对象标识无效。');
    }
}
