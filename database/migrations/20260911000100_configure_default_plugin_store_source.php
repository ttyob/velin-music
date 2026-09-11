<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 为从未配置过的插件应用商店启用官方公开索引。
 *
 * 基线会创建版本 1、`updated_by=NULL` 的空源占位行；本迁移只更新这一精确初始状态，并把地址直接保存为
 * 已规范化的 GitHub Raw `main/index.json`，使升级后页面无需管理员再次保存即可读取公开商店。管理员曾
 * 自定义或主动清空的记录具有更高版本或更新者，不会被覆盖。更新不访问网络、不安装插件，也不修改任何
 * 已安装包；SQLite 写入由 Phinx 事务保护，锁冲突时整体失败，不会留下半个 JSON 或错误版本。
 *
 * 回滚故意不可逆：无法区分迁移写入后仍未使用的默认值与管理员随后明确保留的同一地址。恢复应使用升级
 * 前的数据库备份；未来 MySQL 迁移须保留相同的精确旧值条件和版本递增语义。
 */
final class ConfigureDefaultPluginStoreSource extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
UPDATE system_settings
SET value_json = '{"sourceUrl":"https://raw.githubusercontent.com/ttyob/velin-music-plugins/main/index.json"}',
    version = version + 1,
    updated_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
WHERE setting_key = 'resource.plugin_store'
  AND value_json = '{"sourceUrl":""}'
  AND version = 1
  AND updated_by IS NULL
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('默认插件商店源迁移不可逆。');
    }
}
