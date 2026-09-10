<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 增加全站 AirPlay companion 开关，默认关闭，避免未使用时常驻 OwnTone、Avahi 与 D-Bus。
 *
 * 迁移只向既有 `system_settings` 写入一个固定 JSON 对象，不改变表结构或其它业务数据。SQLite 的
 * `INSERT OR IGNORE` 让新库和已提前写入同名设置的开发库都可幂等升级；写入由 Phinx 事务和现有
 * busy timeout 约束，锁竞争时整体失败，不会留下半行状态。管理员后续通过版本 CAS 修改开关，运行
 * 进程根据已提交状态收敛，启动失败不会回滚管理员选择，也不会把进程路径写入数据库。
 *
 * 回滚故意不可逆：删除该行会丢失管理员明确选择，并使新代码把缺行视为数据故障。上线前应按既有
 * SQLite 在线备份流程保存数据库；恢复时回滚整个备份而不是单独删除设置。未来 MySQL 迁移需把
 * `INSERT OR IGNORE` 等价替换为唯一键冲突忽略语法，JSON 字节内容和版本语义保持不变。
 */
final class AddAirplayFeatureSetting extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("INSERT OR IGNORE INTO system_settings (setting_key, value_json, version, updated_by, updated_at)\n"
            . "VALUES ('feature.airplay', '{\"enabled\":false}', 1, NULL, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))");
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('AirPlay 功能设置迁移不可逆。');
    }
}
