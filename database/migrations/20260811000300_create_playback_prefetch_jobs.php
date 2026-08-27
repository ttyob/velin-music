<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加服务端下一首远程音频预缓存任务（PLAY-016、API-MEDIA-008）。
 *
 * 为什么存在：客户端自己的预加载可能被浏览器策略、系统后台限制或实现差异关闭，因此播放 started 事件
 * 只写一个短任务，由独立 Worker 在请求事务外下载服务端队列的下一首。每个账号/播放器最多一行，新播放
 * 会覆盖旧意图，避免快速切歌形成无界下载队列。
 *
 * 前置条件与不变量：任务只保存不透明账号、播放器和当前歌曲 ID，不保存下一首路径、远端 URL、凭据或
 * 音频字节。Worker 领取后必须读取最新服务端队列，重新构造活动账号并复验 play 能力、音乐库授权和媒体
 * 身份；队列当前项不匹配时短暂重试后删除。queued 不持有租约，running 必须同时持有 worker 和心跳。
 * SQLite 通过唯一键与条件更新串行领取；未来 MySQL 需使用等价唯一键/CAS 或 FOR UPDATE，网络下载仍不得
 * 位于事务内。
 *
 * 锁、耗时与回滚：迁移只创建空表和小索引，不读取远端或扫描媒体正文，预计只持有短 schema 写锁。回滚
 * 会删除可重建调度意图但不删除已经原子发布的 runtime 缓存；缓存随后由固定 LRU 策略清理。执行前仍应
 * 完成 SQLite 在线备份，DDL 失败由 Phinx 事务整体回滚。
 */
final class CreatePlaybackPrefetchJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE playback_prefetch_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    player_id TEXT NOT NULL CHECK (length(player_id) BETWEEN 16 AND 64),
    current_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 3),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 3 AND 96),
    requested_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, player_id),
    CHECK (
        (status = 'queued' AND worker_id IS NULL AND heartbeat_at IS NULL)
        OR
        (status = 'running' AND worker_id IS NOT NULL AND heartbeat_at IS NOT NULL)
    )
);
CREATE INDEX playback_prefetch_jobs_queue_idx
    ON playback_prefetch_jobs(status, next_attempt_at, requested_at, id);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX playback_prefetch_jobs_queue_idx');
        $this->execute('DROP TABLE playback_prefetch_jobs');
    }
}
