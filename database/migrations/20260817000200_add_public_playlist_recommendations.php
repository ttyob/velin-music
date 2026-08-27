<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为系统歌单同步增加无需登录的网易云、QQ 和酷狗热门来源（PLAYLIST-REC-002）。
 *
 * 为什么存在：原始开发基线只允许 Last.fm，且第三方平台热门歌单不应要求管理员保存 Cookie 或登录状态。
 * 新来源通过受控 Go Helper 查询公开目录，数据库只保存 provider、Helper 摘要和脱敏匹配条目；source_key
 * 不包含平台原始 ID。迁移重建两个带 CHECK 的 SQLite 表以保留已有 Last.fm 规则和歌单，不修改歌曲、用户、
 * 播放统计或文件。MySQL 后续应以等价 ALTER/CHECK 数据迁移实现，不直接复用 SQLite 语句。
 *
 * 前置条件与失败行为：执行前必须完成 SQLite 备份并停止 Worker；迁移只允许 SQLite，遇到不支持的 source
 * 或 provider 不会静默删除，而是由 CHECK/复制失败回滚。重建期间临时关闭连接级外键检查，复制完成后立即
 * 恢复并用 `foreign_key_check` 验证；任何校验失败都抛错，不能留下半套 schema。回滚拒绝已经创建的公开
 * 来源记录，避免恢复约束时无声丢失业务数据。
 */
final class AddPublicPlaylistRecommendations extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'sqlite') {
            throw new RuntimeException('Public playlist recommendation migration requires SQLite.');
        }
        $this->rebuildPlaylists("'manual', 'lastfm', 'netease', 'qq', 'kugou'");
        $this->rebuildRules("'lastfm', 'netease', 'qq', 'kugou'");
        $this->execute("PRAGMA foreign_key_check");
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'sqlite') {
            throw new RuntimeException('Public playlist recommendation migration requires SQLite.');
        }
        if ((int) $this->fetchRow("SELECT COUNT(*) AS count FROM playlists WHERE source IN ('netease', 'qq', 'kugou')")['count'] > 0
            || (int) $this->fetchRow("SELECT COUNT(*) AS count FROM system_playlist_sync_rules WHERE provider IN ('netease', 'qq', 'kugou')")['count'] > 0) {
            throw new RuntimeException('Cannot roll back public playlist recommendations while public playlists exist.');
        }
        $this->rebuildRules("'lastfm'");
        $this->rebuildPlaylists("'manual', 'lastfm'");
        $this->execute("PRAGMA foreign_key_check");
    }

    private function rebuildPlaylists(string $sources): void
    {
        $this->execute('PRAGMA foreign_keys = OFF');
        $this->execute(<<<SQL
CREATE TABLE playlists_public_recommendations_new (
    id TEXT PRIMARY KEY NOT NULL,
    owner_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    description TEXT NULL,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'server')),
    song_count INTEGER NOT NULL DEFAULT 0 CHECK (song_count >= 0),
    duration_ms INTEGER NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual', 'smart')),
    scope TEXT NOT NULL DEFAULT 'user' CHECK (scope IN ('user', 'system')),
    source TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ($sources)),
    source_key TEXT NULL
)
SQL);
        $this->execute('INSERT INTO playlists_public_recommendations_new SELECT id, owner_user_id, name, description, visibility, song_count, duration_ms, version, created_at, updated_at, kind, scope, source, source_key FROM playlists');
        $this->execute('DROP TABLE playlists');
        $this->execute('ALTER TABLE playlists_public_recommendations_new RENAME TO playlists');
        $this->execute("CREATE UNIQUE INDEX uq_playlists_system_source ON playlists(scope, source, source_key) WHERE scope = 'system' AND source_key IS NOT NULL");
        $this->execute('CREATE INDEX idx_playlists_kind_owner ON playlists(kind, owner_user_id, updated_at, id)');
        $this->execute('CREATE INDEX idx_playlists_owner ON playlists(owner_user_id, updated_at, id)');
        $this->execute('CREATE INDEX idx_playlists_scope_updated ON playlists(scope, updated_at, id)');
        $this->execute('CREATE INDEX idx_playlists_visibility ON playlists(visibility, updated_at, id)');
        $this->execute('PRAGMA foreign_keys = ON');
    }

    private function rebuildRules(string $providers): void
    {
        $this->execute('PRAGMA foreign_keys = OFF');
        $this->execute(<<<SQL
CREATE TABLE system_playlist_sync_rules_public_recommendations_new (
    playlist_id TEXT PRIMARY KEY NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ($providers)),
    preset TEXT NOT NULL CHECK (preset IN ('global', 'chinese', 'rock', 'hot')),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    interval_seconds INTEGER NOT NULL DEFAULT 21600 CHECK (interval_seconds BETWEEN 3600 AND 604800),
    last_attempt_at TEXT NULL,
    last_success_at TEXT NULL,
    last_error_code TEXT NULL,
    next_sync_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        $this->execute('INSERT INTO system_playlist_sync_rules_public_recommendations_new SELECT playlist_id, provider, preset, enabled, interval_seconds, last_attempt_at, last_success_at, last_error_code, next_sync_at, version, updated_by, created_at, updated_at FROM system_playlist_sync_rules');
        $this->execute('DROP TABLE system_playlist_sync_rules');
        $this->execute('ALTER TABLE system_playlist_sync_rules_public_recommendations_new RENAME TO system_playlist_sync_rules');
        $this->execute('CREATE INDEX idx_system_playlist_sync_due ON system_playlist_sync_rules(provider, enabled, next_sync_at, playlist_id)');
        $this->execute('PRAGMA foreign_keys = ON');
    }
}
