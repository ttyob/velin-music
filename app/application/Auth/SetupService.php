<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\application\Library\DefaultLibraryService;
use app\application\Library\LibraryPathInspector;
use app\application\Storage\StorageLayout;
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
        private readonly LibraryPathInspector $libraryPaths = new LibraryPathInspector(),
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
     * 由已登录超级管理员完成首次默认音乐库配置。
     *
     * 前置条件是首个管理员已经存在且当前没有有效默认库；路径在写事务前后均解析并检查缓存、下载隔离。
     * 独立缓存只要求媒体可读，相邻模式因歌词和封面会发布到媒体旁而要求目录可写。随后库记录、管理员
     * manage 授权、默认库指针和审计在同一短事务提交。任一步失败都会回滚，不创建目录、不移动媒体，
     * 也不会把普通账号升级为管理员；扫描模式只保存策略，不在请求内启动扫描。
     *
     * @param array<string,mixed> $actor 当前认证主体
     * @return string 动态生成的音乐库 ULID
     */
    public function configureDefaultLibrary(SetupLibraryInput $input, array $actor, string $requestId): string
    {
        if (($actor['isSuperAdmin'] ?? false) !== true || !is_string($actor['id'] ?? null)) {
            throw new \RuntimeException('Only a super administrator may configure the default library.');
        }
        $resolvedRoot = $this->resolveSetupLibraryRoot($input->rootPath, $input->scrapeStorageMode);
        $libraryId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            if ((new DefaultLibraryService())->id() !== null) {
                throw new SetupAlreadyCompleted('Default library is already configured.');
            }
            $lockedResolvedRoot = $this->resolveSetupLibraryRoot($input->rootPath, $input->scrapeStorageMode);
            if ($lockedResolvedRoot !== $resolvedRoot) {
                throw new \RuntimeException('音乐库目录在确认期间发生变化。');
            }
            $this->assertSetupLibraryAvailable($pdo, $resolvedRoot);
            Db::table('music_libraries')->insert([
                'id' => $libraryId,
                'name' => '默认音乐库',
                'inbox_path' => null,
                'resolved_inbox_path' => null,
                'root_path' => $input->rootPath,
                'resolved_root_path' => $resolvedRoot,
                'scrape_storage_mode' => $input->scrapeStorageMode,
                'source_type' => 'local',
                'remote_metadata_mode' => 'filename_only',
                'proxy_profile_id' => null,
                'status' => 'active',
                'symlink_policy' => 'ignore',
                'default_locale' => 'zh-CN',
                'scan_mode' => $input->scanMode,
                'scan_status' => 'never_scanned',
                'artist_count' => 0,
                'album_count' => 0,
                'song_count' => 0,
                'last_scanned_at' => null,
                'version' => 1,
                'created_by' => (string) $actor['id'],
                'updated_by' => (string) $actor['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::table('library_user_grants')->insert([
                'library_id' => $libraryId,
                'user_id' => (string) $actor['id'],
                'access_level' => 'manage',
                'granted_by' => (string) $actor['id'],
                'granted_at' => $now,
            ]);
            Db::table('system_settings')->insert([
                'setting_key' => DefaultLibraryService::SETTING_KEY,
                'value_json' => json_encode($libraryId, JSON_THROW_ON_ERROR),
                'version' => 1,
                'updated_by' => (string) $actor['id'],
                'updated_at' => $now,
            ]);
            $this->insertLibrarySetupAudit($pdo, (string) $actor['id'], $libraryId, $requestId, $now);
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) $pdo->exec('ROLLBACK');
            throw $throwable;
        }
        return $libraryId;
    }

    /**
     * 解析首次库目录并实施所选派生资源策略的最小权限。
     *
     * `managed_cache` 的歌词和封面写入固定 `/data/cache/scrape`，媒体根只需可读；`adjacent` 会在歌曲
     * 旁原子发布文件，因此必须可写。该方法不创建目录或探测媒体内容，路径缺失、权限不足或与缓存、
     * 插件下载根双向包含时抛错，由外层事务回滚数据库写入。
     */
    private function resolveSetupLibraryRoot(string $rootPath, string $scrapeStorageMode): string
    {
        $resolved = $this->libraryPaths->resolveMediaDirectory(
            $rootPath,
            '音乐库目录',
            $scrapeStorageMode === 'adjacent',
        );
        $cache = realpath(StorageLayout::SCRAPE_CACHE_ROOT);
        if ($cache !== false && $this->libraryPaths->overlaps($resolved, $cache)) {
            throw new \RuntimeException('音乐库目录不能与刮削缓存目录重叠。');
        }
        $downloads = realpath(StorageLayout::DOWNLOAD_ROOT);
        $downloads = $downloads === false ? StorageLayout::DOWNLOAD_ROOT : $downloads;
        if ($this->libraryPaths->overlaps($resolved, rtrim($downloads, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('音乐库目录不能与插件下载目录重叠。');
        }
        return $resolved;
    }

    private function assertSetupLibraryAvailable(PDO $pdo, string $resolvedRoot): void
    {
        $statement = $pdo->query("SELECT resolved_root_path, resolved_inbox_path FROM music_libraries WHERE source_type = 'local'");
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ([(string) ($row['resolved_root_path'] ?? ''), (string) ($row['resolved_inbox_path'] ?? '')] as $path) {
                if ($path !== '' && $this->libraryPaths->overlaps($resolvedRoot, $path)) {
                    throw new \RuntimeException('音乐库目录与已有配置重叠。');
                }
            }
        }
    }

    private function insertLibrarySetupAudit(PDO $pdo, string $userId, string $libraryId, string $requestId, string $now): void
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO audit_logs (id, actor_user_id, action, object_type, object_id, result, request_id, metadata_json, created_at)
VALUES (:id, :actor, 'system.library_setup', 'music_library', :object_id, 'success', :request_id, '{}', :created_at)
SQL);
        $statement->execute([
            'id' => (string) new Ulid(), 'actor' => $userId, 'object_id' => $libraryId,
            'request_id' => $requestId, 'created_at' => $now,
        ]);
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
