<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加专辑级异步刮削任务（SCR-ALBUM-001）。
 *
 * 为什么存在：专辑是共享实体，不能让每首歌曲的刮削事务重复写入专辑字段或重复访问第三方。任务以
 * album_id 唯一去重，歌曲成功后只登记任务；专辑字段由实体状态仓储按 manual > raw > scraped 物化。
 * 专辑封面继续由独立图片任务负责，失败不回滚歌曲元数据、歌词或歌曲封面。
 *
 * 前置条件与不变量：任务只引用稳定专辑 ID，不保存路径、URL、候选列表或第三方原始响应。queued/running
 * 通过 worker_id 和 heartbeat 条件领取；网络调用位于事务外。失败使用有界重试和冷却，重复入队必须收敛到
 * 同一行。回滚只删除本任务表，不删除专辑、歌曲或媒体资源；未来 MySQL 使用同样的唯一键和 CAS 语义。
 */
final class CreateAlbumScrapeJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE album_scrape_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    album_id TEXT NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'failed')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 4),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 3 AND 96),
    requested_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (album_id),
    CHECK (
        (status IN ('queued', 'succeeded', 'failed') AND worker_id IS NULL AND heartbeat_at IS NULL)
        OR
        (status = 'running' AND worker_id IS NOT NULL AND heartbeat_at IS NOT NULL)
    )
);
CREATE INDEX album_scrape_jobs_queue_idx
    ON album_scrape_jobs(status, next_attempt_at, requested_at, id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX album_scrape_jobs_queue_idx');
        $this->execute('DROP TABLE album_scrape_jobs');
    }
}
