<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为歌曲完成到实体资料任务之间建立事务性意图表。
 *
 * 意图只保存歌曲稳定 ID，不保存路径、插件响应或 URL；唯一 song_id 保证同一歌曲重复完成不会放大
 * 艺人/专辑入队。Worker 分发成功后删除，失败退避重试。
 */
final class CreateMetadataEntityScrapeIntents extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE metadata_entity_scrape_intents (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL UNIQUE REFERENCES media_songs(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX metadata_entity_scrape_intents_queue_idx
    ON metadata_entity_scrape_intents(status, next_attempt_at, created_at);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX metadata_entity_scrape_intents_queue_idx');
        $this->execute('DROP TABLE metadata_entity_scrape_intents');
    }
}
