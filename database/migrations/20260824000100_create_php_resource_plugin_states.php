<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为完整 PHP 插件增加独立于安装和数据库版本的启用状态。
 *
 * 状态表不能依赖 `php_resource_plugin_migrations` 外键，因为协议允许没有数据库生命周期的纯 API/Worker
 * 插件。历史插件没有状态行时按启用、版本 1 解释；第一次停用才插入版本 2，避免迁移替所有现有包制造
 * 不必要数据。状态切换只暂停新页面、钩子和 Worker 领取，不删除插件配置、任务、凭据、业务表、工作区
 * 或已发布媒体。卸载事务会删除对应状态行，重新安装同 key 时不会继承已经卸载包的停用事实。
 *
 * SQLite 通过主键和单调 version 支持短事务 CAS；未来 MySQL 使用等价主键、布尔约束和版本条件更新。
 * down 只移除开关事实，所有仍安装的插件会恢复为默认启用，不触碰插件业务数据，因而该回滚必须在明确
 * 接受插件重新运行的维护窗口执行。
 */
final class CreatePhpResourcePluginStates extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE php_resource_plugin_states (
    plugin_key TEXT PRIMARY KEY NOT NULL CHECK (
        plugin_key GLOB '[a-z0-9]*'
        AND plugin_key NOT GLOB '*[^a-z0-9_-]*'
        AND length(plugin_key) BETWEEN 2 AND 48
    ),
    enabled INTEGER NOT NULL CHECK (enabled IN (0,1)),
    version INTEGER NOT NULL CHECK (version >= 2),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE php_resource_plugin_states');
    }
}
