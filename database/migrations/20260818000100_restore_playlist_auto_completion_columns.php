<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 修复公开热门歌单迁移重建 `playlists` 时遗漏的自动补全字段（PLAYLIST-REC-002 / PLAYLIST-AUTO-001）。
 *
 * 目的：恢复 `auto_completion_enabled` 和 `auto_completion_updated_at`，使后台歌单摘要、自动补全
 * Worker 与版本化开关继续共享同一 schema。前置条件是已执行自动补全和公开歌单迁移；如果某个字段已
 * 存在则跳过，便于修复被部分执行的开发数据库。SQLite 使用短暂 ALTER TABLE，并保留现有歌单值；
 * 未来 MySQL 迁移应使用等价的 ADD COLUMN IF NOT EXISTS 或 INFORMATION_SCHEMA 检查，不复用 SQLite
 * 语句。字段恢复不读取或改写歌曲、导入证据、同步规则、插件任务和媒体文件。
 *
 * 锁与耗时：SQLite 只修改表定义，锁持有时间与表大小无关但仍要求启动时没有其他写事务；生产启动前
 * 必须完成在线备份并停止并发 Worker。向前执行的成功不变量是两个字段存在、旧行默认关闭自动补全、
 * 已有时间值保持 NULL，随后 Worker 才能安全查询。回滚只删除本迁移恢复的字段，不删除歌单或任务；
 * 回滚后后台自动补全功能会再次不可用，必须在停用相关代码后执行，不能把它当作业务数据恢复操作。
 */
final class RestorePlaylistAutoCompletionColumns extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'sqlite') {
            throw new RuntimeException('Playlist auto completion column repair requires SQLite.');
        }

        $columns = $this->columns('playlists');
        if (!in_array('auto_completion_enabled', $columns, true)) {
            $this->execute("ALTER TABLE playlists ADD COLUMN auto_completion_enabled INTEGER NOT NULL DEFAULT 0 CHECK (auto_completion_enabled IN (0,1))");
        }
        if (!in_array('auto_completion_updated_at', $columns, true)) {
            $this->execute('ALTER TABLE playlists ADD COLUMN auto_completion_updated_at TEXT NULL');
        }
    }

    /** 回滚仅撤销本次字段修复，不触碰歌单行、导入证据、同步规则、补全任务或媒体文件。 */
    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'sqlite') {
            throw new RuntimeException('Playlist auto completion column repair requires SQLite.');
        }

        $columns = $this->columns('playlists');
        if (in_array('auto_completion_updated_at', $columns, true)) {
            $this->execute('ALTER TABLE playlists DROP COLUMN auto_completion_updated_at');
        }
        if (in_array('auto_completion_enabled', $columns, true)) {
            $this->execute('ALTER TABLE playlists DROP COLUMN auto_completion_enabled');
        }
    }

    /** @return list<string> 返回真实表列名，避免重复 ADD COLUMN 造成修复迁移不可重试。 */
    private function columns(string $table): array
    {
        $rows = $this->fetchAll('PRAGMA table_info("' . $table . '")');
        return array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['name'] ?? null) ? $row['name'] : null,
            $rows,
        )));
    }
}
