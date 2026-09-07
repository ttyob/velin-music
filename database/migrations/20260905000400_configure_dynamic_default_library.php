<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

/**
 * 将默认音乐库从固定种子身份迁移为系统设置指针。
 *
 * 全新数据库删除基线中空的默认库，安装流程随后要求已登录超级管理员选择真实目录；已有用户且仍
 * 使用旧固定库时只保留该库并写入指针，不触碰媒体、库存或授权。无法唯一判断旧默认库时保持未配置，
 * 让管理员通过首次设置页面显式选择，迁移绝不猜测路径。该迁移不执行文件操作且不可逆。
 */
final class ConfigureDynamicDefaultLibrary extends AbstractMigration
{
    public function up(): void
    {
        $seedId = '01KYM200000000000000000001';
        $hasUsers = (int) $this->fetchRow('SELECT COUNT(*) AS count FROM users')['count'] > 0;
        $seed = $this->fetchRow("SELECT id FROM music_libraries WHERE id = '{$seedId}'");

        if (!$hasUsers && $seed !== false) {
            $bound = (int) $this->fetchRow("SELECT COUNT(*) AS count FROM media_songs WHERE library_id = '{$seedId}'")['count'];
            $bound += (int) $this->fetchRow("SELECT COUNT(*) AS count FROM library_file_inventory WHERE library_id = '{$seedId}'")['count'];
            if ($bound === 0) {
                $this->execute("DELETE FROM music_libraries WHERE id = '{$seedId}'");
            }
            return;
        }

        if ($this->fetchRow("SELECT setting_key FROM system_settings WHERE setting_key = 'default_library_id'") !== false) {
            return;
        }

        $candidate = $this->fetchRow(<<<'SQL'
SELECT id FROM music_libraries
WHERE status = 'active' AND source_type = 'local'
  AND (id = '01KYM200000000000000000001' OR resolved_root_path = '/storage/music')
ORDER BY CASE WHEN id = '01KYM200000000000000000001' THEN 0 ELSE 1 END
SQL);
        if ($candidate !== false) {
            $id = (string) $candidate['id'];
            $this->execute("INSERT INTO system_settings (setting_key, value_json, version, updated_by, updated_at) VALUES ('default_library_id', '\"{$id}\"', 1, NULL, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))");
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('动态默认音乐库迁移不可逆。');
    }
}
