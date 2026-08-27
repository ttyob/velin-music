<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\application\Subsonic\SubsonicCredentialCipher;
use JsonException;
use PDO;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * Performs the one-time Velin Music initialization transaction (SYS-001, WEB-FLOW-001).
 *
 * The users table is the authoritative setup latch: once any user exists, both read and write
 * setup endpoints behave as unavailable. BEGIN IMMEDIATE acquires SQLite's reserved write lock
 * before checking that latch, preventing concurrent workers from both observing an empty table.
 * No network, filesystem, or password work occurs while the lock is held.
 */
final class SetupService
{
    public function __construct(
        private readonly SubsonicCredentialCipher $subsonicCredentials = new SubsonicCredentialCipher(),
    ) {
    }

    /** Returns true when the setup entry point must remain permanently unavailable. */
    public function isCompleted(): bool
    {
        return Db::table('users')->exists();
    }

    /**
     * Atomically creates the first super administrator, preferences, site settings, and audit row.
     *
     * @param string $requestId Server-generated identifier shared with the API response.
     * @return string New user ULID.
     * @throws SetupAlreadyCompleted When any user already exists at lock acquisition time.
     * @throws Throwable Database or encoding failures after rollback.
     */
    public function initialize(SetupInput $input, string $requestId): string
    {
        $passwordHash = password_hash($input->password, PASSWORD_ARGON2ID);
        if (!is_string($passwordHash)) {
            throw new \RuntimeException('Argon2id password hashing failed.');
        }
        // Secretbox encryption occurs before BEGIN IMMEDIATE so random/key failures never extend
        // the SQLite write lock or leave an initialized account without compatibility state.
        $subsonicCiphertext = $this->subsonicCredentials->encrypt($input->password);

        $connection = Db::connection();
        $pdo = $connection->getPdo();
        $userId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // PDO does not mark transactions opened by raw BEGIN IMMEDIATE as inTransaction().
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count !== 0) {
                throw new SetupAlreadyCompleted('Setup is already complete.');
            }

            $this->insertUser($pdo, $userId, $input, $passwordHash, $subsonicCiphertext, $now);
            $this->insertPreferences($pdo, $userId, $input, $now);
            $this->insertSetting($pdo, 'site_name', $input->siteName, $userId, $now);
            $this->insertSetting($pdo, 'initialized_at', $now, $userId, $now);
            $this->updateBasicSetting($pdo, $input, $userId, $now);
            $this->insertSetupAudit($pdo, $userId, $requestId, $now);
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return $userId;
    }

    /**
     * 在初始化事务内把站点表单同步到迁移预置的基础设置对象。
     *
     * 前置条件是 `20260806000400` 已成功迁移并且 `site.basic` 恰好存在一行。更新与首位管理员、个人
     * 偏好和初始化审计共享同一个 `BEGIN IMMEDIATE` 事务；缺行时抛错并回滚全部初始化，不能留下一个
     * 已创建账号但后台基础设置不可用的半初始化实例。该步骤不访问网络或文件系统。
     */
    private function updateBasicSetting(PDO $pdo, SetupInput $input, string $userId, string $now): void
    {
        $statement = $pdo->prepare(<<<'SQL'
UPDATE system_settings
SET value_json = :value_json, version = version + 1, updated_by = :updated_by, updated_at = :updated_at
WHERE setting_key = 'site.basic'
SQL);
        $statement->execute([
            'value_json' => json_encode([
                'siteName' => $input->siteName,
                'defaultLocale' => $input->locale,
                'defaultTimezone' => $input->timezone,
                'defaultPageSize' => 50,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Basic system settings migration is missing.');
        }
    }

    /** Inserts the sole initial identity while the setup write lock is held. */
    private function insertUser(
        PDO $pdo,
        string $userId,
        SetupInput $input,
        string $passwordHash,
        string $subsonicCiphertext,
        string $now,
    ): void {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO users (
    id, username, display_name, email, password_hash, status, is_super_admin,
    locale, timezone, permission_version, subsonic_secret_ciphertext,
    created_at, updated_at, deleted_at
) VALUES (
    :id, :username, :display_name, :email, :password_hash, 'active', 1,
    :locale, :timezone, 1, :subsonic_secret_ciphertext, :created_at, :updated_at, NULL
)
SQL);
        $statement->execute([
            'id' => $userId,
            'username' => $input->username,
            'display_name' => $input->displayName,
            'email' => $input->email,
            'password_hash' => $passwordHash,
            'locale' => $input->locale,
            'timezone' => $input->timezone,
            'subsonic_secret_ciphertext' => $subsonicCiphertext,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Creates the preference row in the same transaction so /me never sees a partial user. */
    private function insertPreferences(PDO $pdo, string $userId, SetupInput $input, string $now): void
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO user_preferences (
    user_id, theme_id, locale, timezone, version, updated_at
) VALUES (:user_id, NULL, :locale, :timezone, 1, :updated_at)
SQL);
        $statement->execute([
            'user_id' => $userId,
            'locale' => $input->locale,
            'timezone' => $input->timezone,
            'updated_at' => $now,
        ]);
    }

    /** @throws JsonException When a programmer supplies a non-encodable setting value. */
    private function insertSetting(
        PDO $pdo,
        string $key,
        string $value,
        string $userId,
        string $now,
    ): void {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO system_settings (setting_key, value_json, version, updated_by, updated_at)
VALUES (:setting_key, :value_json, 1, :updated_by, :updated_at)
SQL);
        $statement->execute([
            'setting_key' => $key,
            'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);
    }

    /** Records successful initialization atomically without retaining submitted form values. */
    private function insertSetupAudit(PDO $pdo, string $userId, string $requestId, string $now): void
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO audit_logs (
    id, actor_user_id, action, object_type, object_id, result,
    request_id, metadata_json, created_at
) VALUES (
    :id, :actor_user_id, 'system.setup', 'user', :object_id, 'success',
    :request_id, '{}', :created_at
)
SQL);
        $statement->execute([
            'id' => (string) new Ulid(),
            'actor_user_id' => $userId,
            'object_id' => $userId,
            'request_id' => $requestId,
            'created_at' => $now,
        ]);
    }
}
