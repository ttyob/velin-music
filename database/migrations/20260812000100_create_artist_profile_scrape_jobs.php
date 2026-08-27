<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加艺人级完整资料投影和异步刮削任务（SCR-ARTIST-001）。
 *
 * 为什么存在：歌曲 Provider 只能可靠确定歌曲署名，艺人简介、地区、活动时间和外部身份需要在艺人
 * 实体稳定后额外查询。任务以 artist_id 唯一去重，避免同一艺人的每首歌曲重复访问公共服务；资料投影
 * 与音频标签字段状态分离，扫描或歌曲重新刮削不会误清空已经确认的艺人资料。
 *
 * 前置条件与不变量：任务和资料只引用稳定艺人 ID，不保存查询 URL、HTTP 正文或路径。queued/running
 * 使用条件更新租约；所有网络 I/O 必须位于事务外。成功资料至少具有经过 UUID 校验的 MusicBrainz ID，
 * tags_json/source_json 只能保存有界规范 JSON。失败只影响艺人资料，不回滚歌曲元数据、歌词或封面。
 *
 * 锁、回滚与未来迁移：迁移只创建空表与索引，持有短 SQLite schema 写锁；回滚删除可重建资料和任务，
 * 不删除艺人、歌曲或媒体文件。未来 MySQL 使用相同唯一键与 CAS 领取语义，网络阶段仍不得进入事务。
 */
final class CreateArtistProfileScrapeJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE artist_profiles (
    artist_id TEXT PRIMARY KEY NOT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    musicbrainz_artist_id TEXT NOT NULL CHECK (length(musicbrainz_artist_id) = 36),
    wikidata_id TEXT NULL CHECK (
        wikidata_id IS NULL OR (
            length(wikidata_id) BETWEEN 2 AND 17
            AND substr(wikidata_id, 1, 1) = 'Q'
            AND substr(wikidata_id, 2, 1) BETWEEN '1' AND '9'
            AND substr(wikidata_id, 2) NOT GLOB '*[^0-9]*'
        )
    ),
    canonical_name TEXT NULL CHECK (canonical_name IS NULL OR length(canonical_name) BETWEEN 1 AND 255),
    artist_type TEXT NULL CHECK (artist_type IS NULL OR length(artist_type) BETWEEN 1 AND 80),
    gender TEXT NULL CHECK (gender IS NULL OR length(gender) BETWEEN 1 AND 80),
    country_code TEXT NULL CHECK (country_code IS NULL OR length(country_code) BETWEEN 2 AND 8),
    area_name TEXT NULL CHECK (area_name IS NULL OR length(area_name) BETWEEN 1 AND 160),
    begin_date TEXT NULL CHECK (begin_date IS NULL OR length(begin_date) BETWEEN 4 AND 10),
    end_date TEXT NULL CHECK (end_date IS NULL OR length(end_date) BETWEEN 4 AND 10),
    disambiguation TEXT NULL CHECK (disambiguation IS NULL OR length(disambiguation) BETWEEN 1 AND 500),
    biography TEXT NULL CHECK (biography IS NULL OR length(biography) BETWEEN 1 AND 12000),
    official_url TEXT NULL CHECK (official_url IS NULL OR length(official_url) BETWEEN 8 AND 1000),
    wikipedia_url TEXT NULL CHECK (wikipedia_url IS NULL OR length(wikipedia_url) BETWEEN 8 AND 1000),
    tags_json TEXT NOT NULL CHECK (length(tags_json) BETWEEN 2 AND 4096),
    sources_json TEXT NOT NULL CHECK (length(sources_json) BETWEEN 2 AND 2048),
    refreshed_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX artist_profiles_mbid_idx ON artist_profiles(musicbrainz_artist_id);

CREATE TABLE artist_profile_scrape_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'failed')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 4),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 3 AND 96),
    requested_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (artist_id),
    CHECK (
        (status IN ('queued', 'failed') AND worker_id IS NULL AND heartbeat_at IS NULL)
        OR
        (status = 'running' AND worker_id IS NOT NULL AND heartbeat_at IS NOT NULL)
    )
);
CREATE INDEX artist_profile_scrape_jobs_queue_idx
    ON artist_profile_scrape_jobs(status, next_attempt_at, requested_at, id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX artist_profile_scrape_jobs_queue_idx');
        $this->execute('DROP TABLE artist_profile_scrape_jobs');
        $this->execute('DROP INDEX artist_profiles_mbid_idx');
        $this->execute('DROP TABLE artist_profiles');
    }
}
