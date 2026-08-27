<?php

declare(strict_types=1);

namespace app\application\Scan;

use InvalidArgumentException;
use support\Db;

/**
 * 计算一个音乐库当前可浏览媒体的歌曲、专辑和艺人数量。
 *
 * 统计必须与媒体查询使用同一可见性边界：只有库存仍为 `available` 且元数据为 `ready` 的歌曲参与；
 * 专辑按歌曲的 album_id 去重，艺人按歌曲署名关系去重，不能直接统计全局实体表。调用方应在扫描终态
 * 的短事务内读取本快照并与 scan_status 一起写回，避免后台看到跨时点的三个数字。本服务只读目录表，
 * 不访问媒体文件或网络；数据库异常由外层扫描事务处理，不返回猜测值。
 */
final readonly class LibraryMediaStatistics
{
    /**
     * 返回指定音乐库的一致可见计数。
     *
     * @return array{songs:int,albums:int,artists:int}
     */
    public function snapshot(string $libraryId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
            throw new InvalidArgumentException('Invalid library identifier.');
        }
        $visibleSongs = Db::table('media_songs as songs')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.library_id', $libraryId)
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');

        $songs = (clone $visibleSongs)->count('songs.id');
        $albums = (clone $visibleSongs)->distinct()->count('songs.album_id');
        $artists = Db::table('media_song_artists as credits')
            ->join('media_songs as songs', 'songs.id', '=', 'credits.song_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->where('songs.library_id', $libraryId)
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready')
            ->distinct()->count('credits.artist_id');

        return ['songs' => $songs, 'albums' => $albums, 'artists' => $artists];
    }
}
