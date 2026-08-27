<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加管理员数据源连通性测试的持久任务（SCR-SOURCE-009）。
 *
 * 为什么存在：数据源测试会访问第三方网络，不能在 Webman 请求进程中同步执行；持久任务让按钮立即
 * 返回，并由独立单消费者执行。每位管理员与每个固定渠道最多保留一行，重复点击正在运行的测试只读取
 * 同一状态，完成后再次点击原地重置，既避免请求放大，也把诊断存储限制在九行/管理员以内。
 *
 * 前置条件与不变量：source_key 只能属于固定九渠道；proxy_profile_id 和 source_version 是点击时的
 * 配置快照，允许代理随后被修改或删除，此时 Worker 必须失败关闭而不能回退直连。queued/running 不得
 * 携带结果，available/unavailable 必须是终态；表中不保存固定测试关键词、第三方响应、URL、凭据或
 * 候选正文。SQLite 使用短事务条件更新领取；未来 MySQL 应使用等价唯一键与 CAS，网络 I/O 仍在事务外。
 *
 * 锁、耗时与回滚：迁移只创建空表和小索引，不扫描媒体数据，预计只持有短 schema 写锁。回滚会删除
 * 可重建的测试诊断，不影响渠道配置、代理、歌曲或刮削任务；执行前仍应完成 SQLite 在线备份，失败由
 * Phinx 事务整体回滚。
 */
final class CreateMusicSourceProbeJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE music_source_probe_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    source_key TEXT NOT NULL CHECK (source_key IN (
        'netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music', 'musicbrainz', 'lrclib'
    )),
    source_version INTEGER NOT NULL CHECK (source_version > 0),
    proxy_profile_id TEXT NULL,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'available', 'unavailable')),
    outcome TEXT NULL CHECK (outcome IS NULL OR outcome IN ('matched', 'low_confidence', 'empty', 'failed', 'not_queried')),
    candidate_count INTEGER NULL CHECK (candidate_count IS NULL OR candidate_count BETWEEN 0 AND 10),
    latency_ms INTEGER NULL CHECK (latency_ms IS NULL OR latency_ms BETWEEN 0 AND 30000),
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 3 AND 96),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 2),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    requested_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (requested_by, source_key),
    CHECK (
        (status = 'queued' AND outcome IS NULL AND candidate_count IS NULL AND latency_ms IS NULL
            AND error_code IS NULL AND worker_id IS NULL AND heartbeat_at IS NULL AND finished_at IS NULL)
        OR
        (status = 'running' AND outcome IS NULL AND candidate_count IS NULL AND latency_ms IS NULL
            AND error_code IS NULL AND worker_id IS NOT NULL AND heartbeat_at IS NOT NULL AND finished_at IS NULL)
        OR
        (status IN ('available', 'unavailable') AND outcome IS NOT NULL AND candidate_count IS NOT NULL
            AND worker_id IS NULL AND heartbeat_at IS NULL AND finished_at IS NOT NULL)
    )
);
CREATE INDEX music_source_probe_jobs_queue_idx
    ON music_source_probe_jobs(status, requested_at, id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX music_source_probe_jobs_queue_idx');
        $this->execute('DROP TABLE music_source_probe_jobs');
    }
}
