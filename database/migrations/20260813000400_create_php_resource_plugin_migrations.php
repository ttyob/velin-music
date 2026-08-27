<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 建立 PHP 资源插件数据库迁移账本。
 *
 * 核心表只记录已安装插件 key 与其数据库合同版本，不保存插件配置、凭据或业务任务。具体插件在独立
 * installDatabase 事务中创建自己的表和初始数据，再与账本行原子提交；版本高于当前代码时加载器失败
 * 关闭，防止降级误读。SQLite 使用单行主键保证同一插件只有一个当前版本，未来 MySQL 使用等价主键。
 *
 * down 只删除账本，不调用未知插件卸载器，也不删除插件业务表；生产降级前必须先逐个执行显式插件卸载，
 * 否则孤立业务表会保留以避免静默数据丢失。
 */
final class CreatePhpResourcePluginMigrations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE php_resource_plugin_migrations (
    plugin_key TEXT PRIMARY KEY NOT NULL CHECK (
        plugin_key GLOB '[a-z0-9]*'
        AND plugin_key NOT GLOB '*[^a-z0-9_-]*'
        AND length(plugin_key) BETWEEN 2 AND 48
    ),
    database_version INTEGER NOT NULL CHECK (database_version > 0),
    installed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE php_resource_plugin_migrations');
    }
}
