<?php

declare(strict_types=1);

namespace app\application\Artwork;

use support\Db;

/**
 * 判断专辑或艺人当前是否已有真正可读取的高优先级图片。
 *
 * 明确选择无论来自上传还是 Provider 都阻止自动补图；扫描图片还必须由 available 库存锚定，因为公开
 * 图片读取会执行相同状态复验。指向 missing/已删除库存的陈旧索引不能被视为有效，否则实体会永久
 * 停在“数据库有行但用户看不到图片”。本服务只读，不删除陈旧索引，也不改变选择；调用方在网络前后
 * 都可重复执行，以关闭扫描器或管理员并发发布图片的竞态窗口。
 */
final readonly class ArtworkCurrentStateService
{
    /**
     * 返回指定库内实体是否已有有效图片；歌曲若没有独立选择则继承专辑有效图片。
     * 未知类型失败关闭为 true，防止自动流程扩大写入范围。
     */
    public function exists(string $type, string $entityId, string $libraryId): bool
    {
        if ($type === 'song') {
            if (Db::table('media_artwork_selection_overrides')->where('song_id', $entityId)
                ->where('library_id', $libraryId)->exists()) return true;
            $albumId = Db::table('media_songs')->where('id', $entityId)->where('library_id', $libraryId)->value('album_id');
            return is_string($albumId) && $this->exists('album', $albumId, $libraryId);
        }
        if (!in_array($type, ['album', 'artist'], true)) return true;
        if (Db::table('media_artwork_selection_overrides')->where($type . '_id', $entityId)
            ->where('library_id', $libraryId)->exists()) return true;

        return $type === 'album'
            ? Db::table('media_album_artworks as artwork')
                ->join('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
                ->where('artwork.album_id', $entityId)->where('source.status', 'available')->exists()
            : Db::table('media_artist_artworks as artwork')
                ->join('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
                ->where('artwork.artist_id', $entityId)->where('artwork.library_id', $libraryId)
                ->where('source.status', 'available')->exists();
    }
}
