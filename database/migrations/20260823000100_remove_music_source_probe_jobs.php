<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 删除核心固定渠道连通性测试的持久任务表。
 *
 * 为什么存在：平台目录、登录、搜索与连通性均已迁移到受信资源插件，核心不再启动固定渠道 Worker，
 * 继续保留任务表会制造一个没有生产者和消费者的伪功能边界。该表只保存可重建的脱敏诊断状态，不保存
 * 音乐来源配置、插件配置、账号授权、歌单、媒体或刮削结果，因此删除不会改变业务数据。
 *
 * 前置条件与不变量：应用代码和 Worker 必须先停止创建、领取任务；迁移仅删除已知索引及其所属表，
 * 不触碰为插件迁移兼容而暂留的 music_sources。SQLite 的 DROP TABLE 在 Phinx 迁移事务中执行，失败时
 * 整体回滚；执行前仍应按部署规范完成在线备份。
 *
 * 回滚边界：旧诊断行删除后无法恢复，且恢复空表会重新暴露已退役的核心能力，因此迁移明确不可逆。
 * 需要回退应用版本时必须恢复迁移前数据库备份，不能通过 down() 伪造无历史数据的兼容状态。
 */
final class RemoveMusicSourceProbeJobs extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DROP INDEX IF EXISTS music_source_probe_jobs_queue_idx');
        $this->execute('DROP TABLE IF EXISTS music_source_probe_jobs');
    }

    public function down(): void
    {
        throw new RuntimeException('Music source probe diagnostics cannot be restored after removal.');
    }
}
