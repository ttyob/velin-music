<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 增加按 Velin 用户隔离的资源插件授权（RESOURCE-PLUGIN-AUTH-001/002/003）。
 *
 * 第三方 auth state 整体使用用途隔离密文保存；表中只保留可展示名称、会员状态和可空到期时间，不能
 * 反查密码、Cookie 或 token。授权固定插件版本和 manifest 摘要，包升级后旧密文不会被新代码静默
 * 消费。写入使用 `(user_id, plugin_key)` 唯一键完成单行替换，失败由事务回滚；撤销删除该行后，播放
 * 与下载租约因消费时必须重新查询授权而立即失效。
 *
 * down 只删除授权密文，不删除插件包、搜索租约或已入库媒体。生产回滚前应先停止插件消费端点；未来
 * MySQL 迁移使用等价复合主键、外键和 CHECK 约束。
 */
final class CreateResourcePluginAuthorizations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE resource_plugin_authorizations (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    plugin_key TEXT NOT NULL CHECK (
        plugin_key GLOB '[a-z0-9]*'
        AND plugin_key NOT GLOB '*[^a-z0-9_-]*'
        AND length(plugin_key) BETWEEN 2 AND 48
    ),
    plugin_version TEXT NOT NULL CHECK (length(plugin_version) BETWEEN 1 AND 64),
    manifest_sha256 TEXT NOT NULL CHECK (length(manifest_sha256) = 64),
    auth_state_ciphertext TEXT NOT NULL,
    display_name TEXT NULL CHECK (display_name IS NULL OR length(display_name) BETWEEN 1 AND 160),
    premium INTEGER NOT NULL DEFAULT 0 CHECK (premium IN (0, 1)),
    expires_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, plugin_key)
);
CREATE INDEX idx_resource_plugin_authorizations_plugin
    ON resource_plugin_authorizations(plugin_key, plugin_version);
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX idx_resource_plugin_authorizations_plugin');
        $this->execute('DROP TABLE resource_plugin_authorizations');
    }
}
