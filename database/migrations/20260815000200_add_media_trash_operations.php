<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为媒体回收记录增加恢复和永久清理的并发领取状态。
 *
 * 原始 `status` 继续只表达音频是否仍在回收区或已经恢复，避免重写已上线表的 SQLite CHECK；永久清理
 * 由 `purged_at` 表达并保留数据库审计锚点。`operation_state` 只允许一个文件操作领取同一条记录，进程
 * 在不可逆 unlink 后中断时保留 `purging` 现场，后续同类命令可按凭据继续收口，不能与恢复并发。
 * 新列均有旧数据兼容默认值，不回填或访问媒体文件。
 */
final class AddMediaTrashOperations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE media_song_deletions ADD COLUMN operation_state TEXT NOT NULL DEFAULT 'idle' CHECK (operation_state IN ('idle', 'restoring', 'purging'))");
        $this->execute('ALTER TABLE media_song_deletions ADD COLUMN operation_request_id TEXT NULL');
        $this->execute('ALTER TABLE media_song_deletions ADD COLUMN restored_at TEXT NULL');
        $this->execute('ALTER TABLE media_song_deletions ADD COLUMN purged_at TEXT NULL');
        $this->execute('CREATE INDEX idx_media_song_deletions_trash ON media_song_deletions(status, purged_at, created_at)');
    }

    /** 回滚只移除状态列和索引，不移动、恢复或重新创建任何媒体文件。 */
    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS idx_media_song_deletions_trash');
        $this->execute('ALTER TABLE media_song_deletions DROP COLUMN purged_at');
        $this->execute('ALTER TABLE media_song_deletions DROP COLUMN restored_at');
        $this->execute('ALTER TABLE media_song_deletions DROP COLUMN operation_request_id');
        $this->execute('ALTER TABLE media_song_deletions DROP COLUMN operation_state');
    }
}
