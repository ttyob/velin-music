<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加重复歌曲的可回滚逻辑合并与旧 ID 重定向（ADMIN-META-011A）。
 *
 * 本迁移只建立目录关系，不扫描、移动或删除媒体文件。操作表保存管理员确认时的成员摘要、可回滚关系
 * 快照及执行后摘要；重定向表保存同 inode 或同字节 SHA-256 的强证据。读取层只有在来源和目标当前库存
 * 仍满足该证据时才隐藏来源并解析旧 ID，文件身份或哈希变化会自动使重定向失效并重新显示来源。
 *
 * SQLite DDL 在 Phinx 事务中持有短 schema 写锁，表初始为空，不回填现有候选。未来 MySQL 使用等价
 * CHECK、外键和唯一索引；不得把文件读取放入迁移。down 只删除逻辑合并记录，媒体、歌曲和个人数据不会
 * 自动回滚，因此生产降级前必须先通过应用层撤销所有 applied 操作并完成数据库备份。
 */
final class CreateSongDuplicateMerges extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE song_duplicate_merge_operations (
    id TEXT PRIMARY KEY NOT NULL,
    group_id TEXT NOT NULL CHECK (length(group_id) = 24),
    evidence_kind TEXT NOT NULL CHECK (evidence_kind IN ('inode', 'byte_hash')),
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    target_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    member_set_sha256 TEXT NOT NULL CHECK (length(member_set_sha256) = 64),
    snapshot_json TEXT NOT NULL CHECK (length(snapshot_json) BETWEEN 2 AND 16777216),
    postcondition_sha256 TEXT NOT NULL CHECK (length(postcondition_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('applied', 'rolled_back')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    rolled_back_at TEXT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX song_duplicate_merge_operations_scope_idx
    ON song_duplicate_merge_operations(library_id, status, created_at, id);

CREATE TABLE song_duplicate_redirects (
    source_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    target_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    operation_id TEXT NOT NULL REFERENCES song_duplicate_merge_operations(id) ON DELETE RESTRICT,
    evidence_kind TEXT NOT NULL CHECK (evidence_kind IN ('inode', 'byte_hash')),
    evidence_key_a TEXT NOT NULL CHECK (length(evidence_key_a) BETWEEN 1 AND 128),
    evidence_key_b TEXT NULL CHECK (evidence_key_b IS NULL OR length(evidence_key_b) BETWEEN 1 AND 128),
    status TEXT NOT NULL CHECK (status IN ('active', 'reverted')),
    created_at TEXT NOT NULL,
    reverted_at TEXT NULL,
    PRIMARY KEY (operation_id, source_song_id),
    CHECK (source_song_id <> target_song_id)
);
CREATE UNIQUE INDEX song_duplicate_redirects_active_source_idx
    ON song_duplicate_redirects(source_song_id) WHERE status = 'active';
CREATE INDEX song_duplicate_redirects_target_idx
    ON song_duplicate_redirects(target_song_id, status);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX song_duplicate_redirects_target_idx');
        $this->execute('DROP INDEX song_duplicate_redirects_active_source_idx');
        $this->execute('DROP TABLE song_duplicate_redirects');
        $this->execute('DROP INDEX song_duplicate_merge_operations_scope_idx');
        $this->execute('DROP TABLE song_duplicate_merge_operations');
    }
}
