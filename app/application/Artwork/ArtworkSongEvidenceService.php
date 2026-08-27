<?php

declare(strict_types=1);

namespace app\application\Artwork;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 构造在线封面查询使用的最小、路径无关歌曲证据。
 *
 * 调用方必须先完成 `edit_metadata` 与实体关联库 manage 校验；本服务再次证明代表歌曲可用、音乐库
 * active 且当前 actor 至少拥有 read grant。返回值不包含路径、库存身份、原始标签或歌词正文，摘要
 * 只绑定会影响平台匹配的描述字段。查询只读且不调用外部服务，失败时统一使用封面领域错误。
 */
final class ArtworkSongEvidenceService
{
    /**
     * 返回当前 actor 可访问代表歌曲的规范证据和稳定摘要。
     *
     * @param array<string,mixed> $actor 已通过封面管理命令能力校验的身份快照。
     * @return array{songId:string,libraryId:string,evidence:array<string,mixed>,evidenceSha256:string,locale:string,region:string}
     */
    public function scoped(string $songId, array $actor): array
    {
        $this->requireUlid($songId);
        $query = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->where('songs.id', $songId)->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')->where('libraries.status', 'active');
        $this->scope($query, $actor);
        return $this->project($query, $actor);
    }

    /** 艺术家顺序和 JSON 字段顺序固定，避免无业务变化时使远程候选摘要失效。 */
    private function project(Builder $query, array $actor): array
    {
        /** @var stdClass|null $row */
        $row = $query->first([
            'songs.id', 'songs.library_id', 'songs.title', 'songs.duration_ms', 'songs.isrc',
            'songs.track_number', 'songs.disc_number', 'albums.title as album_title',
        ]);
        if (!$row instanceof stdClass) throw new ArtworkAdminNotFound('代表歌曲不存在或不可管理。');
        /** @var list<stdClass> $artistRows */
        $artistRows = Db::table('media_song_artists as links')
            ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
            ->where('links.song_id', (string) $row->id)
            ->orderBy('links.position')->orderBy('artists.id')->get(['artists.name'])->all();
        $artists = array_values(array_unique(array_filter(array_map(
            static fn (stdClass $artist): string => trim((string) $artist->name),
            $artistRows,
        ), static fn (string $name): bool => $name !== '')));
        if ($artists === [] || trim((string) $row->title) === '') {
            throw new ArtworkAdminConflict('歌曲标题或艺术家证据不足，无法检索在线封面。');
        }
        $evidence = [
            'title' => trim((string) $row->title),
            'artists' => $artists,
            'album' => trim((string) $row->album_title),
            'durationMs' => max(1, (int) $row->duration_ms),
        ];
        if ((int) $row->track_number > 0) $evidence['trackNumber'] = (int) $row->track_number;
        if ((int) $row->disc_number > 0) $evidence['discNumber'] = (int) $row->disc_number;
        $isrc = strtoupper(trim((string) ($row->isrc ?? '')));
        if (preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/', $isrc) === 1) $evidence['isrc'] = $isrc;
        $locale = is_string($actor['preferences']['locale'] ?? null) ? $actor['preferences']['locale'] : 'zh-CN';
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) $locale = 'zh-CN';
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'songId' => (string) $row->id,
            'libraryId' => (string) $row->library_id,
            'evidence' => $evidence,
            'evidenceSha256' => hash('sha256', $json),
            'locale' => $locale,
            'region' => str_starts_with(strtolower($locale), 'zh') ? 'CN' : 'US',
        ];
    }

    /** 非超级管理员只能使用其当前仍有 read/manage grant 的代表歌曲。 */
    private function scope(Builder $query, array $actor): void
    {
        if (($actor['isSuperAdmin'] ?? false) === true) return;
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)) $ids[] = $library['id'];
        }
        $query->whereIn('songs.library_id', array_values(array_unique($ids)) ?: ['']);
    }

    /** ULID 校验不授予对象权限，范围查询仍统一处理不存在与失权。 */
    private function requireUlid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new ArtworkAdminInvalid('歌曲标识无效。');
        }
    }
}
