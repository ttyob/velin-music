<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加按账号与本地自然日保存的稀疏听歌时长事实（PERS-007、API-PLAY-007）。
 *
 * 表只接受正毫秒数，因此没有收听的日期不会产生 0 行；`local_date` 冻结事件写入时按用户时区判定的
 * 日界线，使用户后来修改时区不会悄悄改写既有统计。播放事件在自身 `BEGIN IMMEDIATE` 短事务内
 * 累加本表，重复 eventId 会在写入前返回，失败则会话、事件、计次和日统计一起回滚。查询只读取日事实，
 * 周、月、年在应用层动态聚合，不保存可漂移的重复汇总。
 *
 * SQLite 使用复合主键保证同一账号同一天只有一行，写事务由调用方串行化；未来 MySQL 应使用等价的
 * DATE、BIGINT UNSIGNED 与复合主键。迁移不回填旧事件，因为历史事件只保留累计值，无法无歧义恢复
 * 每次增量和跨日归属。down 会永久删除已累计时长，但不影响播放历史、播放次数、用户或媒体文件，生产
 * 回滚前必须先备份数据库。
 */
final class CreateDailyListeningTimes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE user_daily_listening_times (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    local_date TEXT NOT NULL CHECK (
        length(local_date) = 10
        AND local_date GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
    ),
    listened_ms INTEGER NOT NULL CHECK (listened_ms > 0),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, local_date)
);
CREATE INDEX idx_user_daily_listening_times_date
    ON user_daily_listening_times(local_date, user_id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX idx_user_daily_listening_times_date');
        $this->execute('DROP TABLE user_daily_listening_times');
    }
}
