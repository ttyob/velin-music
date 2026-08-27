<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为用户/系统歌单增加后台专属的自动补全开关及逐条耐久任务。
 *
 * 开关与任务故意独立于 `playlist_import_entries`：导入记录是来源事实，补全任务则记录后台管理员是否
 * 明确授权后台搜索/下载、当前候选租约和最多三次尝试。任务只保存脱敏标题、艺人和专辑，不保存 URL、
 * Cookie 或平台原始响应；关闭开关不会删除历史结果，也不会取消已经提交给插件的下载任务。SQLite
 * 通过外键级联清理歌单删除产生的任务，插件下载表和媒体文件不受影响。
 */
final class CreatePlaylistAutoCompletion extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE playlists ADD COLUMN auto_completion_enabled INTEGER NOT NULL DEFAULT 0 CHECK (auto_completion_enabled IN (0,1))");
        $this->execute("ALTER TABLE playlists ADD COLUMN auto_completion_updated_at TEXT NULL");
        $this->execute(<<<'SQL'
CREATE TABLE playlist_auto_completion_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    playlist_id TEXT NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    actor_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    library_id TEXT NULL REFERENCES music_libraries(id) ON DELETE SET NULL,
    source_title TEXT NOT NULL CHECK (length(source_title) BETWEEN 1 AND 500),
    source_artists_json TEXT NULL CHECK (source_artists_json IS NULL OR length(source_artists_json) BETWEEN 2 AND 4000),
    source_album TEXT NULL CHECK (source_album IS NULL OR length(source_album) BETWEEN 1 AND 500),
    status TEXT NOT NULL CHECK (status IN ('queued','searching','downloading','importing','succeeded','failed','skipped')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 3),
    max_attempts INTEGER NOT NULL DEFAULT 3 CHECK (max_attempts = 3),
    candidate_index INTEGER NOT NULL DEFAULT 0 CHECK (candidate_index BETWEEN 0 AND 3),
    candidates_json TEXT NULL CHECK (candidates_json IS NULL OR length(candidates_json) BETWEEN 2 AND 65536),
    plugin_key TEXT NULL,
    download_job_id TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 1 AND 80),
    error_detail TEXT NULL CHECK (error_detail IS NULL OR length(error_detail) BETWEEN 1 AND 500),
    worker_id TEXT NULL,
    next_attempt_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (playlist_id, position)
)
SQL);
        $this->execute('CREATE INDEX idx_playlist_auto_completion_queue ON playlist_auto_completion_jobs(status, next_attempt_at, created_at)');
        $this->execute('CREATE INDEX idx_playlist_auto_completion_playlist ON playlist_auto_completion_jobs(playlist_id, status, position)');
    }

    /** 回滚仅移除任务和配置列，不触碰插件任务、已发布媒体或歌单原有条目。 */
    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS idx_playlist_auto_completion_playlist');
        $this->execute('DROP INDEX IF EXISTS idx_playlist_auto_completion_queue');
        $this->execute('DROP TABLE IF EXISTS playlist_auto_completion_jobs');
        $this->execute('ALTER TABLE playlists DROP COLUMN auto_completion_updated_at');
        $this->execute('ALTER TABLE playlists DROP COLUMN auto_completion_enabled');
    }
}
