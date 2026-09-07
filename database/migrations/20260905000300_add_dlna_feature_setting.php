<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 增加全站 DLNA 功能开关，默认关闭以减少无用 helper 常驻内存。
 *
 * 只新增一行版本化系统设置，不修改既有业务表。管理员通过后台 CAS 接口启用后，DLNA 请求按需调用
 * helper；回滚不删除管理员已经选择的功能状态，避免把配置恢复成不透明的旧行为。
 */
final class AddDlnaFeatureSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("INSERT OR IGNORE INTO system_settings (setting_key, value_json, version, updated_by, updated_at)\n"
            . "VALUES ('feature.dlna', '{\"enabled\":false}', 1, NULL, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('DLNA 功能设置迁移不可逆。');
    }
}
