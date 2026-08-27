<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为只读 Google Drive 音乐库增加 OAuth 会话、连接事实和来源枚举（LIB-024 至 LIB-027）。
 *
 * 为什么存在：SQLite 没有 ALTER CHECK。本迁移在 Phinx 已持有的事务中按 SQLite 官方 schema 修改流程，
 * 精确替换 `sqlite_schema` 内唯一的来源枚举片段并递增 schema version；不重建父表，因此不会触发任何
 * 子表级联。执行前必须停止 Webman；替换前后严格比较完整 CREATE SQL，任何偏差会失败并整体回滚。
 *
 * Google 授权会话绑定发起 actor、随机 state 摘要和到期时间。PKCE verifier、client secret、refresh
 * token 只保存用途隔离密文；完成授权后 verifier 被清除，消费后授权行的全部秘密被清除。连接表只允许
 * `authorized|reauthorization_required`，以账号、盘和根路径唯一约束避免同一远端根重复登记。
 *
 * 本迁移不访问 Google、不移动媒体，也不改写现有音乐库。回滚仅在不存在 Google Drive 音乐库时允许，
 * 否则失败关闭，避免静默删除授权或库存。未来 MySQL 迁移应使用原生 ALTER CHECK/ENUM 与事务化表创建，
 * 不能直接修改系统 schema 表。
 */
final class AddGoogleDriveMusicLibraries extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->sqlite();
        $this->extendSourceType($pdo, true);

        $pdo->exec(<<<'SQL'
CREATE TABLE google_drive_authorizations (
    id TEXT PRIMARY KEY NOT NULL,
    actor_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL,
    client_secret_ciphertext TEXT NULL,
    pkce_verifier_ciphertext TEXT NULL,
    state_digest TEXT NOT NULL UNIQUE CHECK (length(state_digest) = 64),
    redirect_uri TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'authorized', 'denied', 'expired', 'consumed')),
    expires_at TEXT NOT NULL,
    refresh_token_ciphertext TEXT NULL,
    account_id TEXT NULL,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((status = 'pending' AND client_secret_ciphertext IS NOT NULL
            AND pkce_verifier_ciphertext IS NOT NULL AND refresh_token_ciphertext IS NULL)
        OR (status = 'authorized' AND client_secret_ciphertext IS NOT NULL
            AND pkce_verifier_ciphertext IS NULL AND refresh_token_ciphertext IS NOT NULL
            AND account_id IS NOT NULL)
        OR (status IN ('denied', 'expired', 'consumed') AND client_secret_ciphertext IS NULL
            AND pkce_verifier_ciphertext IS NULL AND refresh_token_ciphertext IS NULL))
);
CREATE INDEX google_drive_authorizations_actor_created_idx
    ON google_drive_authorizations(actor_user_id, created_at);
CREATE INDEX google_drive_authorizations_expiry_idx
    ON google_drive_authorizations(status, expires_at);

CREATE TABLE google_drive_library_connections (
    library_id TEXT PRIMARY KEY NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL,
    client_secret_ciphertext TEXT NULL,
    refresh_token_ciphertext TEXT NULL,
    account_id TEXT NULL,
    drive_id TEXT NOT NULL,
    remote_root_path TEXT NOT NULL,
    authorization_status TEXT NOT NULL CHECK (authorization_status IN ('authorized', 'reauthorization_required')),
    last_verified_at TEXT NULL,
    last_error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((authorization_status = 'authorized' AND client_secret_ciphertext IS NOT NULL
            AND refresh_token_ciphertext IS NOT NULL AND account_id IS NOT NULL)
        OR authorization_status = 'reauthorization_required'),
    UNIQUE (account_id, drive_id, remote_root_path)
);
SQL);
        $this->assertForeignKeys($pdo);
    }

    public function down(): void
    {
        $pdo = $this->sqlite();
        $count = (int) $pdo->query("SELECT COUNT(*) FROM music_libraries WHERE source_type = 'google_drive'")
            ->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Remove Google Drive libraries before rolling back this migration.');
        }
        $pdo->exec('DROP TABLE google_drive_library_connections');
        $pdo->exec('DROP TABLE google_drive_authorizations');
        $this->extendSourceType($pdo, false);
        $this->assertForeignKeys($pdo);
    }

    /**
     * 精确修改唯一 CHECK 片段并刷新 SQLite schema cache。
     *
     * `writable_schema` 只在一次 UPDATE 周围开启并在 finally 中关闭。完整 SQL 必须只出现一次旧片段且不
     * 包含目标片段；更新必须只命中 `music_libraries` 一行，随后重新读取逐字比较。Phinx 外层事务负责
     * 原子回滚，方法不提交、不关闭外键，也不触碰任何表数据或索引。
     */
    private function extendSourceType(PDO $pdo, bool $enableGoogleDrive): void
    {
        $withoutGoogle = "source_type TEXT NOT NULL DEFAULT 'local' CHECK (source_type IN ('local', 'webdav', 'onedrive'))";
        $withGoogle = "source_type TEXT NOT NULL DEFAULT 'local' CHECK (source_type IN ('local', 'webdav', 'onedrive', 'google_drive'))";
        $from = $enableGoogleDrive ? $withoutGoogle : $withGoogle;
        $to = $enableGoogleDrive ? $withGoogle : $withoutGoogle;
        $sql = $pdo->query("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'music_libraries'")
            ->fetchColumn();
        if (!is_string($sql) || substr_count($sql, $from) !== 1 || str_contains($sql, $to)) {
            throw new RuntimeException('music_libraries source_type CHECK does not match the expected schema.');
        }
        $replacement = str_replace($from, $to, $sql);
        try {
            $pdo->exec('PRAGMA writable_schema = ON');
            $statement = $pdo->prepare(
                "UPDATE sqlite_schema SET sql = :sql WHERE type = 'table' AND name = 'music_libraries'",
            );
            $statement->execute(['sql' => $replacement]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('music_libraries schema update did not affect one row.');
            }
        } finally {
            $pdo->exec('PRAGMA writable_schema = OFF');
        }
        $version = (int) $pdo->query('PRAGMA schema_version')->fetchColumn();
        $pdo->exec('PRAGMA schema_version = ' . ($version + 1));
        $actual = $pdo->query("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = 'music_libraries'")
            ->fetchColumn();
        if (!is_string($actual) || !hash_equals($replacement, $actual)) {
            throw new RuntimeException('music_libraries source_type CHECK update could not be verified.');
        }
    }

    /** 所有子表必须继续指向重建后的同名父表；发现孤儿即拒绝完成迁移。 */
    private function assertForeignKeys(PDO $pdo): void
    {
        $violation = $pdo->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC);
        if ($violation !== false) throw new RuntimeException('Google Drive migration contains a foreign-key violation.');
    }

    private function sqlite(): PDO
    {
        $pdo = $this->getAdapter()->getConnection();
        if (!$pdo instanceof PDO || (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Google Drive migration only supports SQLite.');
        }
        return $pdo;
    }
}
