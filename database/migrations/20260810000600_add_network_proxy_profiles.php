<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Symfony\Component\Uid\Ulid;

/**
 * 增加可复用的命名网络代理，并让刮削渠道与网络音乐库显式引用（SCR-SOURCE-008、LIB-028）。
 *
 * 为什么存在：原 `network.proxy` 只能保存一条连接，无法让不同第三方渠道或网络库选择不同出口。
 * 本迁移建立独立 profile 表，并把旧对象转换为“默认代理”；旧 `use_proxy=1` 的渠道绑定该 profile，
 * 从而升级后不会意外改变其流量出口。音乐库默认保持直连，必须由管理员显式选择。
 *
 * 前置条件与不变量：迁移要求基线表存在，profile 名称在 SQLite `NOCASE` 规则下唯一；引用列允许 null，
 * null 唯一表示直连。密码密文原样搬迁，不在迁移中解密。SQLite 的 `ALTER TABLE ADD COLUMN REFERENCES`
 * 只增加可空列，不重写媒体数据；未来 MySQL 使用等价外键与不区分大小写唯一索引。
 *
 * 锁、失败与回滚：DDL 和回填在 Phinx 事务内持有短写锁，规模仅为固定九渠道和少量音乐库。旧 JSON
 * 缺失或损坏时失败并整体回滚，不能生成看似可用但实际直连的配置。回滚删除引用与 profile 表，旧
 * `network.proxy` 和 `use_proxy` 始终保留，因此可恢复旧版本语义；执行前仍应完成 SQLite 在线备份。
 */
final class AddNetworkProxyProfiles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE network_proxy_profiles (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    scheme TEXT NOT NULL CHECK (scheme IN ('http', 'https', 'socks5')),
    host TEXT NOT NULL,
    port INTEGER NOT NULL CHECK (port BETWEEN 1 AND 65535),
    username TEXT NOT NULL DEFAULT '',
    password_ciphertext TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((username = '' AND password_ciphertext IS NULL)
        OR (username <> '' AND password_ciphertext IS NOT NULL))
);
CREATE INDEX network_proxy_profiles_enabled_idx ON network_proxy_profiles(enabled, name);
ALTER TABLE music_sources ADD COLUMN proxy_profile_id TEXT NULL
    REFERENCES network_proxy_profiles(id) ON DELETE RESTRICT;
ALTER TABLE music_libraries ADD COLUMN proxy_profile_id TEXT NULL
    REFERENCES network_proxy_profiles(id) ON DELETE RESTRICT;
ALTER TABLE onedrive_device_authorizations ADD COLUMN proxy_profile_id TEXT NULL
    REFERENCES network_proxy_profiles(id) ON DELETE RESTRICT;
ALTER TABLE google_drive_authorizations ADD COLUMN proxy_profile_id TEXT NULL
    REFERENCES network_proxy_profiles(id) ON DELETE RESTRICT;
CREATE INDEX music_sources_proxy_profile_idx ON music_sources(proxy_profile_id);
CREATE INDEX music_libraries_proxy_profile_idx ON music_libraries(proxy_profile_id);
CREATE INDEX onedrive_authorizations_proxy_profile_idx ON onedrive_device_authorizations(proxy_profile_id);
CREATE INDEX google_drive_authorizations_proxy_profile_idx ON google_drive_authorizations(proxy_profile_id);
SQL);

        $row = $this->fetchRow("SELECT value_json, updated_by, updated_at FROM system_settings WHERE setting_key = 'network.proxy'");
        if (!is_array($row)) {
            throw new RuntimeException('Legacy network.proxy setting is missing.');
        }
        $value = json_decode((string) $row['value_json'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value)
            || !is_bool($value['enabled'] ?? null)
            || !is_string($value['scheme'] ?? null)
            || !in_array($value['scheme'], ['http', 'https', 'socks5'], true)
            || !is_string($value['host'] ?? null)
            || !is_int($value['port'] ?? null)
            || $value['port'] < 1 || $value['port'] > 65535
            || !is_string($value['username'] ?? null)
            || (!is_null($value['passwordCiphertext'] ?? null) && !is_string($value['passwordCiphertext']))) {
            throw new RuntimeException('Legacy network.proxy setting is invalid.');
        }
        $profileId = (string) new Ulid();
        $now = (string) ($row['updated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        $this->table('network_proxy_profiles')->insert([[
            'id' => $profileId,
            'name' => '默认代理',
            'enabled' => $value['enabled'] ? 1 : 0,
            'scheme' => $value['scheme'],
            'host' => $value['host'],
            'port' => $value['port'],
            'username' => $value['username'],
            'password_ciphertext' => $value['passwordCiphertext'],
            'version' => 1,
            'created_by' => $row['updated_by'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]])->saveData();
        $this->execute("UPDATE music_sources SET proxy_profile_id = '" . addslashes($profileId) . "' WHERE use_proxy = 1");
    }

    public function down(): void
    {
        $this->execute('DROP INDEX google_drive_authorizations_proxy_profile_idx');
        $this->execute('DROP INDEX onedrive_authorizations_proxy_profile_idx');
        $this->execute('DROP INDEX music_libraries_proxy_profile_idx');
        $this->execute('DROP INDEX music_sources_proxy_profile_idx');
        $this->execute('ALTER TABLE google_drive_authorizations DROP COLUMN proxy_profile_id');
        $this->execute('ALTER TABLE onedrive_device_authorizations DROP COLUMN proxy_profile_id');
        $this->execute('ALTER TABLE music_libraries DROP COLUMN proxy_profile_id');
        $this->execute('ALTER TABLE music_sources DROP COLUMN proxy_profile_id');
        $this->execute('DROP TABLE network_proxy_profiles');
    }
}
