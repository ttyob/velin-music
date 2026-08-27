<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为歌曲、专辑和艺术家偏好增加按用户隔离的 1..5 分评分（SUBSONIC-RATING-001）。
 *
 * 为什么重建：旧表用 `CHECK (is_favorite = 1)` 强制每行都是收藏，直接加列无法表达“仅评分”状态。
 * 三张表因此在同一迁移事务中复制到新结构，旧收藏值和时间原样保留；新结构仍是稀疏表，只有收藏或
 * 评分至少一项存在时才能保留行，并分别约束状态与时间戳成对出现。任一步失败由 SQLite 事务回滚，
 * 不会留下半数表已升级；外键继续级联用户与媒体删除，索引按个人状态重建。
 *
 * 方言边界：这里使用 SQLite 的建表、复制、删除、重命名流程，未来 MySQL 迁移应以在线 DDL 增列并
 * 添加等价 CHECK/触发器，而不能复用本 SQL。down 会保留全部收藏并删除仅评分行；所有个人评分不可逆
 * 丢失，因此生产回滚前必须确认接受评分数据损失或先备份数据库，媒体文件与共享元数据不受影响。
 */
final class AddPersonalMediaRatings extends AbstractMigration
{
    public function up(): void
    {
        foreach ($this->definitions() as $definition) {
            $this->rebuildForRatings(...$definition);
        }
    }

    public function down(): void
    {
        foreach ($this->definitions() as $definition) {
            $this->rebuildForFavorites(...$definition);
        }
    }

    /**
     * 把一张旧收藏表复制为可同时保存收藏与评分的稀疏表。
     *
     * 表名、媒体列和外键目标只来自本类固定映射，不接受运行时输入；调用者处于 Phinx 迁移事务中，
     * 复制保留所有旧行，随后删除旧表并原子改名。失败时事务恢复原 schema 与数据。
     */
    private function rebuildForRatings(string $table, string $mediaColumn, string $mediaTable, string $index): void
    {
        $next = $table . '_rating_next';
        $this->execute(sprintf(<<<'SQL'
CREATE TABLE "%s" (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    %s TEXT NOT NULL REFERENCES %s(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    rating INTEGER NULL CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
    rated_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, %s),
    CHECK ((is_favorite = 1 AND favorited_at IS NOT NULL) OR (is_favorite = 0 AND favorited_at IS NULL)),
    CHECK ((rating IS NOT NULL AND rated_at IS NOT NULL) OR (rating IS NULL AND rated_at IS NULL)),
    CHECK (is_favorite = 1 OR rating IS NOT NULL)
)
SQL, $next, $mediaColumn, $mediaTable, $mediaColumn));
        $this->execute(sprintf(
            'INSERT INTO "%s" (user_id, %s, is_favorite, favorited_at, rating, rated_at, updated_at)'
            . ' SELECT user_id, %s, is_favorite, favorited_at, NULL, NULL, updated_at FROM "%s"',
            $next,
            $mediaColumn,
            $mediaColumn,
            $table,
        ));
        $this->execute(sprintf('DROP TABLE "%s"', $table));
        $this->execute(sprintf('ALTER TABLE "%s" RENAME TO "%s"', $next, $table));
        $this->execute(sprintf(
            'CREATE INDEX "%s" ON "%s"(user_id, is_favorite, favorited_at, %s)',
            $index,
            $table,
            $mediaColumn,
        ));
    }

    /**
     * 回滚为旧收藏专用结构，仅复制 `is_favorite = 1` 的行。
     *
     * 这是有意的数据降级：评分列和仅评分行被删除，但收藏及原始收藏时间保持不变。操作仍在迁移事务
     * 内完成，外键和索引恢复旧终态；失败不会提交部分降级结果。
     */
    private function rebuildForFavorites(string $table, string $mediaColumn, string $mediaTable, string $index): void
    {
        $previous = $table . '_favorite_previous';
        $this->execute(sprintf(<<<'SQL'
CREATE TABLE "%s" (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    %s TEXT NOT NULL REFERENCES %s(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, %s),
    CHECK (is_favorite = 1),
    CHECK ((is_favorite = 1 AND favorited_at IS NOT NULL) OR (is_favorite = 0 AND favorited_at IS NULL))
)
SQL, $previous, $mediaColumn, $mediaTable, $mediaColumn));
        $this->execute(sprintf(
            'INSERT INTO "%s" (user_id, %s, is_favorite, favorited_at, updated_at)'
            . ' SELECT user_id, %s, is_favorite, favorited_at, updated_at FROM "%s" WHERE is_favorite = 1',
            $previous,
            $mediaColumn,
            $mediaColumn,
            $table,
        ));
        $this->execute(sprintf('DROP TABLE "%s"', $table));
        $this->execute(sprintf('ALTER TABLE "%s" RENAME TO "%s"', $previous, $table));
        $this->execute(sprintf(
            'CREATE INDEX "%s" ON "%s"(user_id, is_favorite, favorited_at, %s)',
            $index,
            $table,
            $mediaColumn,
        ));
    }

    /** @return list<array{string,string,string,string}> 固定表映射，防止动态 SQL 结构来自外部输入。 */
    private function definitions(): array
    {
        return [
            ['user_song_preferences', 'song_id', 'media_songs', 'idx_user_song_preferences_favorite'],
            ['user_album_preferences', 'album_id', 'media_albums', 'idx_user_album_preferences_favorite'],
            ['user_artist_preferences', 'artist_id', 'media_artists', 'idx_user_artist_preferences_favorite'],
        ];
    }
}
