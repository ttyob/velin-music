<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加部署者安装的资源插件配置与短期搜索租约（RESOURCE-PLUGIN-001/SEARCH-002）。
 *
 * 插件可执行文件和 manifest 仍属于服务器部署事实，不写入业务数据库；配置表只保存管理员启停决定、
 * 版本锁和最后一次通过校验的 manifest 摘要。搜索租约把第三方资源引用保存为用途隔离密文，并绑定
 * actor、插件版本、manifest 摘要和明确 capability，浏览器只能持有随机 ULID。迁移不扫描插件目录、
 * 不启动进程或访问第三方，因此 SQLite schema 写锁保持短暂；失败由 Phinx 事务整体回滚。
 *
 * down 会删除未消费搜索租约和插件启停配置，但不会删除服务器插件包、第三方账号或媒体文件。生产回滚
 * 前应先停止插件搜索，避免旧应用继续创建不存在表的租约；未来 MySQL 使用等价 CHECK 与复合索引。
 */
final class CreateResourcePlugins extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE resource_plugins (
    plugin_key TEXT PRIMARY KEY NOT NULL CHECK (
        plugin_key GLOB '[a-z0-9]*'
        AND plugin_key NOT GLOB '*[^a-z0-9_-]*'
        AND length(plugin_key) BETWEEN 2 AND 48
    ),
    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    manifest_sha256 TEXT NOT NULL CHECK (length(manifest_sha256) = 64),
    plugin_version TEXT NOT NULL CHECK (length(plugin_version) BETWEEN 1 AND 64),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE resource_plugin_result_leases (
    id TEXT PRIMARY KEY NOT NULL,
    actor_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_key TEXT NOT NULL CHECK (length(plugin_key) BETWEEN 2 AND 48),
    plugin_version TEXT NOT NULL CHECK (length(plugin_version) BETWEEN 1 AND 64),
    manifest_sha256 TEXT NOT NULL CHECK (length(manifest_sha256) = 64),
    capabilities_json TEXT NOT NULL CHECK (length(capabilities_json) BETWEEN 2 AND 256),
    title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 300),
    artist_text TEXT NOT NULL CHECK (length(artist_text) BETWEEN 1 AND 500),
    context_ciphertext TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_resource_plugin_leases_actor_expiry
    ON resource_plugin_result_leases(actor_id, expires_at);
CREATE INDEX idx_resource_plugin_leases_plugin_expiry
    ON resource_plugin_result_leases(plugin_key, plugin_version, expires_at);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX idx_resource_plugin_leases_plugin_expiry');
        $this->execute('DROP INDEX idx_resource_plugin_leases_actor_expiry');
        $this->execute('DROP TABLE resource_plugin_result_leases');
        $this->execute('DROP TABLE resource_plugins');
    }
}
