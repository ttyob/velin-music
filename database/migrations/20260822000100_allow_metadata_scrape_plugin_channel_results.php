<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 允许元数据插件作为唯一歌曲刮削渠道写入任务快照。
 *
 * 基线建立时渠道键仍是旧核心平台列表；插件化后核心只接收 `metadata-scrape` 一个最终结果，但旧
 * SQLite CHECK 约束会在保存插件结果时拒绝该键，导致正常的插件未匹配/不可用被误报成内部失败。
 * SQLite 不能直接修改 CHECK，因此在独占迁移事务内重建同一张表，完整复制已有渠道结果、外键和唯一
 * 约束。迁移不读取第三方数据、不改变任务状态或媒体文件；失败由 Phinx 回滚，原表和索引保持不变。
 * 回滚前必须没有插件渠道行，避免把有效历史结果静默删除；这是不可逆数据边界，调用方应先清理对应
 * 任务记录或恢复备份。未来 MySQL 迁移应使用等价的约束变更，不复用本 SQLite DDL。
 */
final class AllowMetadataScrapePluginChannelResults extends AbstractMigration
{
    private const TABLE = 'metadata_sync_scrape_channel_results';
    private const INDEX = 'idx_metadata_sync_scrape_channels_target';

    public function up(): void
    {
        $this->assertSqlite();
        $this->rebuild([
            'netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music',
            'musicbrainz', 'lrclib', 'metadata-scrape',
        ]);
    }

    /** 仅在没有插件渠道结果时回滚，避免破坏已经展示过的任务审计事实。 */
    public function down(): void
    {
        $this->assertSqlite();
        $count = (int) $this->fetchRow(
            "SELECT COUNT(*) AS count FROM " . self::TABLE . " WHERE channel_key = 'metadata-scrape'",
        )['count'];
        if ($count > 0) {
            throw new RuntimeException('Cannot rollback metadata plugin channel constraint while plugin results exist.');
        }
        $this->rebuild([
            'netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music',
            'musicbrainz', 'lrclib',
        ]);
    }

    /** @param list<string> $channelKeys */
    private function rebuild(array $channelKeys): void
    {
        $allowed = implode(', ', array_map(
            static fn (string $key): string => "'" . str_replace("'", "''", $key) . "'",
            $channelKeys,
        ));
        $table = self::TABLE;
        $legacy = self::TABLE . '_legacy';
        $this->execute('DROP INDEX IF EXISTS ' . self::INDEX);
        $this->execute('ALTER TABLE ' . self::TABLE . ' RENAME TO ' . $legacy);
        $this->execute(<<<SQL
CREATE TABLE {$table} (
    id TEXT PRIMARY KEY NOT NULL,
    target_id TEXT NOT NULL REFERENCES metadata_sync_scrape_targets(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 8),
    channel_key TEXT NOT NULL CHECK (channel_key IN ({$allowed})),
    display_name TEXT NOT NULL CHECK (length(display_name) BETWEEN 1 AND 50),
    status TEXT NOT NULL CHECK (status IN ('matched', 'unmatched', 'unavailable')),
    candidate_json TEXT NULL CHECK (candidate_json IS NULL OR length(candidate_json) BETWEEN 2 AND 16384),
    diagnostics_json TEXT NULL CHECK (diagnostics_json IS NULL OR length(diagnostics_json) BETWEEN 2 AND 16384),
    created_at TEXT NOT NULL,
    has_lyrics INTEGER NOT NULL DEFAULT 0 CHECK (has_lyrics IN (0, 1)),
    has_artwork INTEGER NOT NULL DEFAULT 0 CHECK (has_artwork IN (0, 1)),
    UNIQUE (target_id, channel_key),
    UNIQUE (target_id, position)
)
SQL);
        $this->execute(
            'INSERT INTO ' . self::TABLE . ' (id, target_id, position, channel_key, display_name, status, candidate_json, diagnostics_json, created_at, has_lyrics, has_artwork) '
            . 'SELECT id, target_id, position, channel_key, display_name, status, candidate_json, diagnostics_json, created_at, has_lyrics, has_artwork FROM ' . $legacy,
        );
        $this->execute('DROP TABLE ' . $legacy);
        $this->execute('CREATE INDEX ' . self::INDEX . ' ON ' . self::TABLE . '(target_id, position)');
    }

    private function assertSqlite(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'sqlite') {
            throw new RuntimeException('Metadata plugin channel constraint migration requires SQLite.');
        }
    }
}
