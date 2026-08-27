<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 记录任务中心已经清理的任务投影。
 *
 * 某些终态任务仍被媒体索引、标签快照或其他业务事实以 RESTRICT 外键引用，不能物理删除源任务而
 * 不破坏业务数据。本表只保存任务中心的隐藏事实，不承载任务状态、媒体数据或权限；唯一键保证同一
 * 来源任务的清理幂等。回滚仅删除隐藏标记，源任务和业务事实不会被触碰，部署者应在回滚前确认任务
 * 中心是否需要恢复这些历史记录。
 */
final class CreateJobCenterClearedJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE job_center_cleared_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    job_type TEXT NOT NULL CHECK (length(job_type) BETWEEN 1 AND 64),
    job_id TEXT NOT NULL CHECK (length(job_id) BETWEEN 1 AND 64),
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    request_id TEXT NOT NULL CHECK (length(request_id) BETWEEN 1 AND 128),
    reason TEXT NOT NULL CHECK (reason IN ('deleted', 'retained_by_business_fact')),
    cleared_at TEXT NOT NULL,
    UNIQUE (job_type, job_id)
)
SQL);
        $this->execute(
            'CREATE INDEX idx_job_center_cleared_jobs_type ON job_center_cleared_jobs(job_type, cleared_at DESC)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE job_center_cleared_jobs');
    }
}
