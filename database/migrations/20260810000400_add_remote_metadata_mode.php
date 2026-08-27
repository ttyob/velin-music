<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为网络音乐库增加源元数据读取模式（LIB-015B）。
 *
 * 为什么存在：WebDAV 与 OneDrive 的首次扫描若逐文件执行 FFprobe Range，会对远端服务形成较密集的
 * 正文请求。管理员需要以库为边界选择完全不读音频正文的 `filename_only`，并将其作为新旧网络库的
 * 安全默认值；需要内嵌标签和准确技术参数时才显式切换为 `range_probe`。
 *
 * 前置条件与不变量：只修改 `music_libraries`，值固定为 `filename_only|range_probe`。本地库保留默认
 * 值但业务层不展示、不接受该字段，避免为本地扫描建立第二套语义。SQLite 添加带常量默认值的非空列
 * 不重写现有媒体正文；已有网络库立即得到 `filename_only`，下一次扫描按新模式签名安全收敛。
 *
 * 锁、失败与回滚：迁移需要短暂 schema 写锁，预计与音乐库行数无关；执行时不得并发启动 Worker。
 * Phinx 事务失败会回滚 DDL。回滚仅删除配置列，不修改已经建立的目录元数据，也不会恢复任何远端请求。
 * 备份要求沿用部署级 SQLite 在线备份。未来 MySQL 使用等价 VARCHAR/CHECK 或枚举约束表达。
 */
final class AddRemoteMetadataMode extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE music_libraries
ADD COLUMN remote_metadata_mode TEXT NOT NULL DEFAULT 'filename_only'
CHECK (remote_metadata_mode IN ('filename_only', 'range_probe'))
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE music_libraries DROP COLUMN remote_metadata_mode');
    }
}
