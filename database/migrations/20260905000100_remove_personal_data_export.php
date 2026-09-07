<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 移除已退役的个人数据导出任务表（PERS-006）。
 *
 * 产品不再提供个人数据导出 API 或后台消费者，因此保留任务记录只会形成无法访问、无法清理的隐私产物
 * 元数据。升级时仅删除该功能独占的 SQLite 表；其三个索引会随表自动删除，不触碰用户资料、歌单、收藏、
 * 播放历史和其他共享业务事实。
 *
 * 前置条件：必须先停止旧版本 Worker，再执行本迁移，避免旧消费者在 DROP TABLE 前后继续领取任务。
 * 失败行为：SQLite DDL 由 Phinx 事务保护，失败会回滚而保持旧表。历史导出文件不会由数据库迁移扫描或
 * 删除，以免在 schema 升级时对宿主机 runtime 执行未经验证的文件操作；新版本已无接口可访问这些产物。
 * 本迁移不可逆，恢复功能必须通过新的完整设计与迁移重新建立表和状态机，不能猜测性复用旧任务数据。
 */
final class RemovePersonalDataExport extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('personal_data_export_jobs')) {
            $this->execute('DROP TABLE personal_data_export_jobs');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('个人数据导出功能已退役，不能恢复已删除的任务数据。');
    }
}
