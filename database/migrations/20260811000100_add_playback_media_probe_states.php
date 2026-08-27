<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为播放期间的缺失媒体技术信息补全增加跨 Worker 租约和失败冷却（LIB-015C、API-MEDIA-007）。
 *
 * 为什么存在：`filename_only` 网络库会有意把时长等技术事实保存为未知，首次实际读取媒体时需要一次
 * 受控 FFprobe；没有持久状态时，并发播放、下载或 DLNA 请求会重复访问同一远端对象并放大风控风险。
 * 本表只保存歌曲、库存身份摘要、稳定错误码和调度时间，不保存路径、URL、凭据或媒体正文。
 *
 * 前置条件与不变量：歌曲与库存必须来自同一条现有目录关系，应用层仍会用歌曲 ID、库存 ID、
 * `duration_ms=0` 和完整库存身份执行 CAS。`running` 必须持有租约，`failed` 必须持有下一次尝试时间；
 * 成功补全即删除状态。SQLite 使用主键冲突后的条件更新原子领取租约；未来 MySQL 迁移需改为等价的
 * 唯一键条件更新或 `SELECT ... FOR UPDATE`，不能把网络 I/O 放进事务。
 *
 * 锁、耗时与回滚：DDL 只创建一张空表和一个小索引，不扫描媒体表，预计只持有短 schema 写锁。
 * 回滚只删除可重建的调度状态，不回滚已经补全的技术字段；因此回滚后播放仍可用，但失去跨 Worker
 * 去重。执行前仍应完成 SQLite 在线备份，迁移失败由 Phinx 事务整体回滚。
 */
final class AddPlaybackMediaProbeStates extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE playback_media_probe_states (
    song_id TEXT PRIMARY KEY NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE CASCADE,
    inventory_identity_sha256 TEXT NOT NULL CHECK (length(inventory_identity_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('running', 'failed')),
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count BETWEEN 0 AND 10),
    next_attempt_at TEXT NULL,
    lease_owner TEXT NULL,
    lease_expires_at TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 3 AND 96),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (status = 'running' AND lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL AND next_attempt_at IS NULL)
        OR
        (status = 'failed' AND lease_owner IS NULL AND lease_expires_at IS NULL AND next_attempt_at IS NOT NULL)
    )
);
CREATE INDEX playback_media_probe_retry_idx
    ON playback_media_probe_states(status, next_attempt_at, lease_expires_at);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX playback_media_probe_retry_idx');
        $this->execute('DROP TABLE playback_media_probe_states');
    }
}
