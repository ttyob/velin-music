<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 记录受管本地歌曲移入隔离回收区后的恢复凭据。
 *
 * 歌曲和库存行在删除后会按既有外键级联清理，因此本表不建立到媒体业务表的外键，避免回收记录
 * 被级联删除。原始相对路径和文件身份只供后续受控恢复/运维核对，任何 HTTP 投影都不得返回物理路径。
 * 文件移动与本表写入由应用服务按“先移动、后短事务提交、失败补偿移动”顺序完成；本迁移只创建结构。
 */
final class CreateMediaSongDeletions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE media_song_deletions (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL,
    library_id TEXT NOT NULL,
    inventory_file_id TEXT NOT NULL,
    original_relative_path TEXT NOT NULL,
    trash_entry_name TEXT NOT NULL,
    device_id INTEGER NOT NULL,
    inode INTEGER NOT NULL,
    file_size INTEGER NOT NULL CHECK (file_size >= 0),
    modified_at INTEGER NOT NULL CHECK (modified_at >= 0),
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    request_id TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('completed', 'restored')),
    created_at TEXT NOT NULL,
    completed_at TEXT NULL,
    UNIQUE (request_id)
);
CREATE INDEX idx_media_song_deletions_song ON media_song_deletions(song_id, created_at);
CREATE INDEX idx_media_song_deletions_library ON media_song_deletions(library_id, created_at);
SQL);
    }

    /** 回滚只删除结构，不触碰回收区文件；文件恢复必须由专用恢复流程完成。 */
    public function down(): void
    {
        $this->execute(<<<'SQL'
DROP INDEX IF EXISTS idx_media_song_deletions_library;
DROP INDEX IF EXISTS idx_media_song_deletions_song;
DROP TABLE IF EXISTS media_song_deletions;
SQL);
    }
}
