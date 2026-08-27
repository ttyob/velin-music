<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 删除已经废弃的外部进程资源插件配置、授权与短期播放租约。
 *
 * Velin Music 已收敛为一种管理员上传的受信 PHP 插件：每个插件自行携带页面、API、Worker 和数据库
 * 生命周期，因此旧 `resource_plugins` 目录配置、按用户授权密文与播放租约不再有消费者。up 按索引、
 * 子状态、主配置顺序删除，仅影响尚未正式交付的旧二进制插件模型；`php_resource_plugin_migrations`、
 * Jackett 自有表、插件包、媒体与审计均不属于本迁移范围。SQLite 的 DDL 由 Phinx 事务保护，失败时整体
 * 回滚；生产执行前仍应完成在线备份，因为授权密文和未消费租约在成功提交后只能通过 down 恢复空结构，
 * 无法恢复原行。未来 MySQL 迁移应使用等价的显式索引和外键删除顺序。
 */
final class RemoveExternalProcessResourcePlugins extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
DROP INDEX IF EXISTS idx_resource_plugin_authorizations_plugin;
DROP TABLE IF EXISTS resource_plugin_authorizations;
DROP INDEX IF EXISTS idx_resource_plugin_leases_plugin_expiry;
DROP INDEX IF EXISTS idx_resource_plugin_leases_actor_expiry;
DROP TABLE IF EXISTS resource_plugin_result_leases;
DROP TABLE IF EXISTS resource_plugins;
SQL);
    }

    /**
     * 仅恢复旧版本应用可启动所需的空 schema。
     *
     * down 不可能重建已经删除的配置、授权密文或租约，部署者回滚后必须重新配置旧插件。该限制是刻意
     * 的：迁移不能伪造用户授权或让旧播放引用指向新 PHP 插件。所有新 PHP 插件状态保持不变。
     */
    public function down(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE resource_plugins (
    plugin_key TEXT PRIMARY KEY NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    manifest_sha256 TEXT NOT NULL,
    plugin_version TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE resource_plugin_result_leases (
    id TEXT PRIMARY KEY NOT NULL,
    actor_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_key TEXT NOT NULL,
    plugin_version TEXT NOT NULL,
    manifest_sha256 TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    title TEXT NOT NULL,
    artist_text TEXT NOT NULL,
    context_ciphertext TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_resource_plugin_leases_actor_expiry ON resource_plugin_result_leases(actor_id, expires_at);
CREATE INDEX idx_resource_plugin_leases_plugin_expiry ON resource_plugin_result_leases(plugin_key, plugin_version, expires_at);
CREATE TABLE resource_plugin_authorizations (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_key TEXT NOT NULL,
    plugin_version TEXT NOT NULL,
    manifest_sha256 TEXT NOT NULL,
    auth_state_ciphertext TEXT NOT NULL,
    display_name TEXT NULL,
    premium INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, plugin_key)
);
CREATE INDEX idx_resource_plugin_authorizations_plugin ON resource_plugin_authorizations(plugin_key, plugin_version);
SQL);
    }
}
