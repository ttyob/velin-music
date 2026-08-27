<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use app\infrastructure\Audit\AuditLogger;
use app\application\Notification\NotificationPublisher;
use Illuminate\Database\QueryException;
use PDO;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * 管理单目录音乐库登记、管理范围与读取授权替换。
 *
 * 登记只对唯一媒体根和固定刮削缓存执行有界元数据检查，不枚举媒体，也不在 HTTP Worker 启动扫描。
 * 真实路径重叠在持有 SQLite `BEGIN IMMEDIATE` 写保留时复验，防止并发请求各自看到空闲目录后登记
 * 互相包含的根。旧 inbox/目录对只参与升级期冲突保护，不能由新请求创建（SCR-LIBRARY-001）。
 */
final class LibraryManagementService
{
    /** Stable seed identity; the path fallback preserves the restriction for upgraded old installs. */
    private const DEFAULT_LIBRARY_ID = '01KYM200000000000000000001';

    public function __construct(
        private readonly LibraryPathInspector $paths = new LibraryPathInspector(),
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly NotificationPublisher $notifications = new NotificationPublisher(),
        private readonly WebDavClientFactory $webDavClients = new WebDavClientFactory(),
        private readonly WebDavCredentialCipher $webDavCipher = new WebDavCredentialCipher(),
        private readonly OneDriveClientFactory $oneDriveClients = new OneDriveClientFactory(),
        private readonly OneDriveDeviceAuthorizationService $oneDriveAuthorizations = new OneDriveDeviceAuthorizationService(),
        private readonly GoogleDriveClientFactory $googleDriveClients = new GoogleDriveClientFactory(),
        private readonly GoogleDriveAuthorizationService $googleDriveAuthorizations = new GoogleDriveAuthorizationService(),
    ) {
    }

    /**
     * Lists only libraries the actor may manage; super administrators see every library.
     *
     * @param array<string, mixed> $actor Current user snapshot from AuthorizationService.
     * @return list<array<string, mixed>> Sanitized management rows including authorized paths.
     */
    public function listLibraries(array $actor): array
    {
        $query = Db::table('music_libraries as libraries')
            ->leftJoin('webdav_library_connections as webdav', 'webdav.library_id', '=', 'libraries.id')
            ->leftJoin('onedrive_library_connections as onedrive', 'onedrive.library_id', '=', 'libraries.id')
            ->leftJoin('google_drive_library_connections as google_drive', 'google_drive.library_id', '=', 'libraries.id')
            ->leftJoin('network_proxy_profiles as proxy', 'proxy.id', '=', 'libraries.proxy_profile_id');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('libraries.name')->get([
            'libraries.id', 'libraries.name', 'libraries.inbox_path', 'libraries.resolved_inbox_path',
            'libraries.root_path', 'libraries.resolved_root_path', 'libraries.scrape_storage_mode',
            'libraries.source_type', 'libraries.remote_metadata_mode', 'libraries.proxy_profile_id',
            'proxy.name as proxy_name', 'proxy.enabled as proxy_enabled', 'webdav.base_url as webdav_base_url',
            'webdav.remote_root_path as webdav_remote_root_path', 'webdav.username as webdav_username',
            'webdav.verify_tls as webdav_verify_tls', 'webdav.last_verified_at as webdav_last_verified_at',
            'webdav.last_error_code as webdav_last_error_code',
            'onedrive.tenant_id as onedrive_tenant_id', 'onedrive.client_id as onedrive_client_id',
            'onedrive.remote_root_path as onedrive_remote_root_path',
            'onedrive.authorization_status as onedrive_authorization_status',
            'onedrive.last_verified_at as onedrive_last_verified_at',
            'onedrive.last_error_code as onedrive_last_error_code',
            'google_drive.client_id as google_drive_client_id',
            'google_drive.drive_id as google_drive_drive_id',
            'google_drive.remote_root_path as google_drive_remote_root_path',
            'google_drive.authorization_status as google_drive_authorization_status',
            'google_drive.last_verified_at as google_drive_last_verified_at',
            'google_drive.last_error_code as google_drive_last_error_code',
            'libraries.status', 'libraries.symlink_policy', 'libraries.default_locale',
            'libraries.scan_mode', 'libraries.scan_status', 'libraries.artist_count',
            'libraries.album_count', 'libraries.song_count', 'libraries.last_scanned_at',
            'libraries.version', 'libraries.created_at',
        ])->all();

        $libraryIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $grantCounts = $this->grantCounts($libraryIds);

        return array_map(
            fn (stdClass $row): array => $this->mapLibrary($row, $grantCounts[(string) $row->id] ?? 0),
            $rows,
        );
    }

    /**
     * 登记一个不重叠的媒体库根，并授予创建者管理范围。
     *
     * 媒体根在事务前和即将插入时各解析一次，第二次可发现等待 SQLite 期间发生的挂载或软链接替换。
     * `managed_cache` 只要求库可读，`adjacent` 要求库可写；两者都必须位于 `/media`，且不能与固定缓存、
     * 现有库根或升级期旧 inbox/watch 路径重叠。方法不读取媒体内容，失败回滚数据库且无文件副作用。
     *
     * @param array<string, mixed> $actor Authorized manage_library principal.
     * @return array<string, mixed> Created management projection.
     * @throws LibraryPathInvalid Filesystem preconditions fail.
     * @throws LibraryConflict Name or resolved path overlaps existing configuration.
     */
    public function createLibrary(
        LibraryCreateInput $input,
        array $actor,
        string $requestId,
    ): array {
        $libraryId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $remoteCiphertext = null;
        $oneDriveGrant = null;
        $googleDriveGrant = null;
        $proxy = $input->proxyProfileId === null ? null
            : (new NetworkProxyProfileService())->connection($input->proxyProfileId);
        if ($input->sourceType === 'webdav') {
            $this->assertWebDavInputComplete($input);
            $this->assertRemoteAvailable((string) $input->webDavBaseUrl, $input->rootPath, '');
            $this->webDavClients->forConfiguration(
                (string) $input->webDavBaseUrl,
                $input->rootPath,
                (string) $input->webDavUsername,
                (string) $input->webDavPassword,
                $input->webDavVerifyTls,
                $proxy,
            )->assertConnection();
            $remoteCiphertext = $this->webDavCipher->encrypt((string) $input->webDavPassword);
            $preflightRoot = 'webdav://' . $libraryId . '/';
        } elseif ($input->sourceType === 'onedrive') {
            $this->assertOneDriveInputComplete($input);
            $oneDriveGrant = $this->oneDriveAuthorizations->completedGrant(
                (string) $input->oneDriveAuthorizationId,
                $actor,
            );
            if ($oneDriveGrant->tenantId !== $input->oneDriveTenantId
                || $oneDriveGrant->clientId !== $input->oneDriveClientId
                || $oneDriveGrant->proxyProfileId !== $input->proxyProfileId) {
                sodium_memzero($oneDriveGrant->refreshToken);
                throw new LibraryValidationFailed([
                    'oneDriveAuthorizationId' => ['授权使用的租户或客户端 ID 与表单不一致。'],
                ]);
            }
            $this->assertOneDriveAvailable(
                $oneDriveGrant->driveId,
                $input->rootPath,
                '',
            );
            try {
                $this->oneDriveClients->forConfiguration(
                    $oneDriveGrant->tenantId,
                    $oneDriveGrant->clientId,
                    $oneDriveGrant->refreshToken,
                    $oneDriveGrant->driveId,
                    $input->rootPath,
                    proxy: $proxy,
                )->assertConnection();
            } finally {
                sodium_memzero($oneDriveGrant->refreshToken);
            }
            $remoteCiphertext = $oneDriveGrant->refreshTokenCiphertext;
            $preflightRoot = 'onedrive://' . $libraryId . '/';
        } elseif ($input->sourceType === 'google_drive') {
            $this->assertGoogleDriveInputComplete($input);
            $googleDriveGrant = $this->googleDriveAuthorizations->completedGrant(
                (string) $input->googleDriveAuthorizationId,
                $actor,
            );
            if ($googleDriveGrant->proxyProfileId !== $input->proxyProfileId) {
                sodium_memzero($googleDriveGrant->clientSecret);
                sodium_memzero($googleDriveGrant->refreshToken);
                throw new LibraryValidationFailed([
                    'googleDriveAuthorizationId' => ['授权使用的代理与表单选择不一致，请重新授权。'],
                ]);
            }
            $this->assertGoogleDriveAvailable(
                $googleDriveGrant->accountId,
                $input->googleDriveId,
                $input->rootPath,
                '',
            );
            try {
                $this->googleDriveClients->forConfiguration(
                    $googleDriveGrant->clientId,
                    $googleDriveGrant->clientSecret,
                    $googleDriveGrant->refreshToken,
                    $input->googleDriveId,
                    $input->rootPath,
                    proxy: $proxy,
                )->assertConnection();
            } finally {
                sodium_memzero($googleDriveGrant->clientSecret);
                sodium_memzero($googleDriveGrant->refreshToken);
            }
            $preflightRoot = 'google_drive://' . $libraryId . '/';
        } else {
            $preflightRoot = $this->resolveLibraryRoot($input->rootPath, $input->scrapeStorageMode);
            $this->assertCacheSeparated($preflightRoot);
        }
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;

        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $resolvedRoot = $input->sourceType !== 'local'
                ? $input->sourceType . '://' . $libraryId . '/'
                : $this->resolveLibraryRoot($input->rootPath, $input->scrapeStorageMode);
            if ($input->sourceType === 'webdav') {
                $this->assertRemoteAvailable((string) $input->webDavBaseUrl, $input->rootPath, '');
            } elseif ($input->sourceType === 'onedrive') {
                $this->assertOneDriveAvailable(
                    (string) $oneDriveGrant?->driveId,
                    $input->rootPath,
                    '',
                );
            } elseif ($input->sourceType === 'google_drive') {
                $this->assertGoogleDriveAvailable(
                    (string) $googleDriveGrant?->accountId,
                    $input->googleDriveId,
                    $input->rootPath,
                    '',
                );
            } else {
                $this->assertCacheSeparated($resolvedRoot);
                $this->assertPathAvailable($resolvedRoot, '');
            }

            Db::table('music_libraries')->insert([
                'id' => $libraryId,
                'name' => $input->name,
                'inbox_path' => null,
                'resolved_inbox_path' => null,
                'root_path' => $input->rootPath,
                'resolved_root_path' => $resolvedRoot,
                'scrape_storage_mode' => $input->scrapeStorageMode,
                'source_type' => $input->sourceType,
                'remote_metadata_mode' => $input->remoteMetadataMode,
                'proxy_profile_id' => $input->proxyProfileId,
                'status' => 'active',
                'symlink_policy' => $input->symlinkPolicy,
                'default_locale' => $input->defaultLocale,
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
            if ($input->sourceType === 'webdav') {
                Db::table('webdav_library_connections')->insert([
                    'library_id' => $libraryId,
                    'base_url' => (string) $input->webDavBaseUrl,
                    'remote_root_path' => $input->rootPath,
                    'username' => (string) $input->webDavUsername,
                    'password_ciphertext' => (string) $remoteCiphertext,
                    'verify_tls' => $input->webDavVerifyTls ? 1 : 0,
                    'last_verified_at' => $now,
                    'last_error_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($input->sourceType === 'onedrive') {
                Db::table('onedrive_library_connections')->insert([
                    'library_id' => $libraryId,
                    'tenant_id' => (string) $input->oneDriveTenantId,
                    'client_id' => (string) $input->oneDriveClientId,
                    'legacy_client_secret_ciphertext' => null,
                    'legacy_user_principal_name' => null,
                    'refresh_token_ciphertext' => (string) $remoteCiphertext,
                    'account_id' => (string) $oneDriveGrant?->accountId,
                    'drive_id' => (string) $oneDriveGrant?->driveId,
                    'remote_root_path' => $input->rootPath,
                    'authorization_status' => 'authorized',
                    'last_verified_at' => $now,
                    'last_error_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->oneDriveAuthorizations->consume(
                    (string) $input->oneDriveAuthorizationId,
                    $actor,
                );
            } elseif ($input->sourceType === 'google_drive') {
                Db::table('google_drive_library_connections')->insert([
                    'library_id' => $libraryId,
                    'client_id' => (string) $googleDriveGrant?->clientId,
                    'client_secret_ciphertext' => (string) $googleDriveGrant?->clientSecretCiphertext,
                    'refresh_token_ciphertext' => (string) $googleDriveGrant?->refreshTokenCiphertext,
                    'account_id' => (string) $googleDriveGrant?->accountId,
                    'drive_id' => $input->googleDriveId,
                    'remote_root_path' => $input->rootPath,
                    'authorization_status' => 'authorized',
                    'last_verified_at' => $now,
                    'last_error_code' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->googleDriveAuthorizations->consume(
                    (string) $input->googleDriveAuthorizationId,
                    $actor,
                );
            }
            Db::table('library_user_grants')->insert([
                'library_id' => $libraryId,
                'user_id' => (string) $actor['id'],
                'access_level' => 'manage',
                'granted_by' => (string) $actor['id'],
                'granted_at' => $now,
            ]);
            $this->auditLogger->record(
                (string) $actor['id'],
                'library.create',
                'music_library',
                $libraryId,
                'success',
                $requestId,
                [
                    'symlinkPolicy' => $input->symlinkPolicy,
                    'scanMode' => $input->scanMode,
                    'scrapeStorageMode' => $input->scrapeStorageMode,
                    'sourceType' => $input->sourceType,
                    'remoteMetadataMode' => $input->sourceType === 'local' ? null : $input->remoteMetadataMode,
                    'useProxy' => $input->proxyProfileId !== null,
                ],
            );
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            if ($throwable instanceof QueryException
                && str_contains(strtolower($throwable->getMessage()), 'unique')) {
                throw new LibraryConflict('音乐库名称或目录已经存在。', previous: $throwable);
            }
            throw $throwable;
        }

        return $this->findManagedLibrary($libraryId, $actor);
    }

    /**
     * Updates one library under optimistic locking and global path-overlap protection.
     *
     * 默认库只接受刮削资源、符号链接和扫描策略，身份、路径及语言保持固定。自定义库采用完整替换；
     * 已有媒体或升级期路径事实后拒绝直接换根，因为重定位需要独立迁移方案。`adjacent` 切换还会复验
     * 当前根可写。文件系统解析在 `BEGIN IMMEDIATE` 前后各执行一次，事务本身不执行文件写入。
     *
     * @param array<string, mixed> $payload Untrusted parsed request body.
     * @param array<string, mixed> $actor Authorized manage_library principal.
     * @return array<string, mixed> Updated protected administration projection.
     */
    public function updateLibrary(
        string $libraryId,
        array $payload,
        array $actor,
        string $requestId,
    ): array {
        $current = $this->findManagedLibrary($libraryId, $actor);
        $validation = (new LibraryValidator())->validateUpdate(
            $payload,
            (bool) $current['isDefault'],
            (string) $current['sourceType'],
        );
        if (!$validation->isValid() || $validation->input === null) {
            throw new LibraryValidationFailed($validation->errors);
        }
        $input = $validation->input;
        if ($input->sourceType !== (string) $current['sourceType']) {
            throw new LibraryConflict('音乐库来源类型不能直接转换，请新建音乐库。');
        }
        if ($input->defaultOnly) {
            $resolvedRoot = $this->resolveLibraryRoot((string) $current['rootPath'], $input->scrapeStorageMode);
            $this->assertCacheSeparated($resolvedRoot);
            Db::transaction(function () use ($actor, $input, $libraryId, $requestId): void {
                $changed = Db::table('music_libraries')->where('id', $libraryId)
                    ->where('version', $input->expectedVersion)->update([
                        'scan_mode' => $input->scanMode,
                        'scrape_storage_mode' => $input->scrapeStorageMode,
                        'symlink_policy' => $input->symlinkPolicy,
                        'version' => Db::raw('version + 1'),
                        'updated_by' => (string) $actor['id'],
                        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                if ($changed !== 1) {
                    throw new LibraryConflict('音乐库已变化，请刷新后重试。');
                }
                $this->auditLogger->record((string) $actor['id'], 'library.update.default', 'music_library',
                    $libraryId, 'success', $requestId, [
                        'scanMode' => $input->scanMode,
                        'scrapeStorageMode' => $input->scrapeStorageMode,
                        'symlinkPolicy' => $input->symlinkPolicy,
                    ]);
            });

            return $this->findManagedLibrary($libraryId, $actor);
        }

        if ($input->sourceType === 'webdav') {
            return $this->updateWebDavLibrary($libraryId, $input, $current, $actor, $requestId);
        }
        if ($input->sourceType === 'onedrive') {
            return $this->updateOneDriveLibrary($libraryId, $input, $current, $actor, $requestId);
        }
        if ($input->sourceType === 'google_drive') {
            return $this->updateGoogleDriveLibrary($libraryId, $input, $current, $actor, $requestId);
        }

        $preflightRoot = $this->resolveLibraryRoot((string) $input->rootPath, $input->scrapeStorageMode);
        $this->assertCacheSeparated($preflightRoot);
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $resolvedRoot = $this->resolveLibraryRoot((string) $input->rootPath, $input->scrapeStorageMode);
            $this->assertCacheSeparated($resolvedRoot);
            $this->assertPathAvailable($resolvedRoot, $libraryId);
            $pathChanged = $resolvedRoot !== (string) $current['resolvedRootPath'];
            if ($pathChanged && $this->libraryHasPathBoundFacts($libraryId)) {
                throw new LibraryConflict('音乐库已有媒体记录，不能直接修改目录。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('music_libraries')->where('id', $libraryId)
                ->where('version', $input->expectedVersion)->update([
                    'name' => $input->name,
                    'root_path' => $input->rootPath,
                    'resolved_root_path' => $resolvedRoot,
                    'scrape_storage_mode' => $input->scrapeStorageMode,
                    'symlink_policy' => $input->symlinkPolicy,
                    'default_locale' => $input->defaultLocale,
                    'scan_mode' => $input->scanMode,
                    'version' => Db::raw('version + 1'),
                    'updated_by' => (string) $actor['id'],
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) {
                throw new LibraryConflict('音乐库已变化，请刷新后重试。');
            }
            $this->auditLogger->record((string) $actor['id'], 'library.update', 'music_library',
                $libraryId, 'success', $requestId, [
                    'pathChanged' => $pathChanged,
                    'scanMode' => $input->scanMode,
                    'scrapeStorageMode' => $input->scrapeStorageMode,
                ]);
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            if ($throwable instanceof QueryException
                && str_contains(strtolower($throwable->getMessage()), 'unique')) {
                throw new LibraryConflict('音乐库名称或目录已经存在。', previous: $throwable);
            }
            throw $throwable;
        }

        return $this->findManagedLibrary($libraryId, $actor);
    }

    /**
     * Removes an empty custom library configuration without deleting any media file.
     *
     * Default configuration is immutable. Media/inventory, scrape bindings, import observations and
     * active scans are checked before a versioned delete; historical completed scans may cascade as
     * derived diagnostics. The transaction changes database rows only and never opens either path.
     */
    public function deleteLibrary(
        string $libraryId,
        int $expectedVersion,
        array $actor,
        string $requestId,
    ): void {
        if ($expectedVersion < 1) {
            throw new LibraryValidationFailed(['expectedVersion' => ['音乐库版本无效，请刷新后重试。']]);
        }
        $library = $this->findManagedLibrary($libraryId, $actor);
        if ((bool) $library['isDefault']) {
            throw new LibraryConflict('默认音乐库不能删除。');
        }
        if ($this->libraryHasPathBoundFacts($libraryId)
            || Db::table('library_scan_jobs')->where('library_id', $libraryId)
                ->whereIn('status', ['queued', 'running', 'cancel_requested'])->exists()) {
            throw new LibraryConflict('音乐库仍有媒体记录或活动扫描任务，不能删除。');
        }
        Db::transaction(function () use ($actor, $expectedVersion, $libraryId, $requestId): void {
            $this->auditLogger->record((string) $actor['id'], 'library.delete', 'music_library',
                $libraryId, 'success', $requestId, ['filesystemChanged' => false]);
            $deleted = Db::table('music_libraries')->where('id', $libraryId)
                ->where('version', $expectedVersion)->delete();
            if ($deleted !== 1) {
                throw new LibraryConflict('音乐库已变化，请刷新后重试。');
            }
        });
    }

    /**
     * Returns minimal account candidates and their current library grant.
     *
     * @return array{library: array<string, mixed>, users: list<array<string, mixed>>}
     */
    public function grantOptions(string $libraryId, array $actor): array
    {
        $library = $this->findManagedLibrary($libraryId, $actor);
        /** @var list<stdClass> $rows */
        $rows = Db::table('users')
            ->leftJoin('library_user_grants as grants', function ($join) use ($libraryId): void {
                $join->on('grants.user_id', '=', 'users.id')
                    ->where('grants.library_id', '=', $libraryId);
            })
            ->whereNull('users.deleted_at')
            ->orderByDesc('users.is_super_admin')
            ->orderBy('users.username')
            ->get([
                'users.id', 'users.username', 'users.display_name', 'users.status',
                'users.is_super_admin', 'grants.access_level',
            ])->all();

        return [
            'library' => $library,
            'users' => array_map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'username' => (string) $row->username,
                'displayName' => (string) $row->display_name,
                'status' => (string) $row->status,
                'isSuperAdmin' => (int) $row->is_super_admin === 1,
                'accessLevel' => $row->access_level === null ? null : (string) $row->access_level,
            ], $rows),
        ];
    }

    /**
     * Replaces read-level grants while preserving every manage grant.
     *
     * Selected IDs must identify active, non-deleted accounts. The full replacement is idempotent;
     * affected users receive a permission-version increment in the same transaction. Super-admin
     * implicit access is not stored as a read row and cannot be removed through this operation.
     *
     * @param list<string> $selectedUserIds
     * @return array{library: array<string, mixed>, users: list<array<string, mixed>>}
     */
    public function replaceReadGrants(
        string $libraryId,
        array $selectedUserIds,
        array $actor,
        string $requestId,
    ): array {
        $library = $this->findManagedLibrary($libraryId, $actor);
        $selectedUserIds = array_values(array_unique($selectedUserIds));
        if (count($selectedUserIds) > 100) {
            throw new LibraryConflict('单次授权用户数量超过限制。');
        }
        foreach ($selectedUserIds as $userId) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
                throw new LibraryConflict('授权用户标识无效。');
            }
        }

        $eligibleIds = $selectedUserIds === [] ? [] : Db::table('users')
            ->whereIn('id', $selectedUserIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        if (count($eligibleIds) !== count($selectedUserIds)) {
            throw new LibraryConflict('一个或多个授权用户已不可用。');
        }

        $currentReadIds = Db::table('library_user_grants')
            ->where('library_id', $libraryId)
            ->where('access_level', 'read')
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $managerIds = Db::table('library_user_grants')
            ->where('library_id', $libraryId)
            ->where('access_level', 'manage')
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $effectiveSelectedIds = array_values(array_diff($selectedUserIds, $managerIds));
        sort($effectiveSelectedIds);
        sort($currentReadIds);
        $affectedIds = array_values(array_unique(array_merge(
            array_diff($currentReadIds, $effectiveSelectedIds),
            array_diff($effectiveSelectedIds, $currentReadIds),
        )));

        if ($affectedIds !== []) {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::transaction(function () use (
                $actor,
                $affectedIds,
                $effectiveSelectedIds,
                $libraryId,
                $library,
                $now,
                $requestId,
            ): void {
                Db::table('library_user_grants')
                    ->where('library_id', $libraryId)
                    ->where('access_level', 'read')
                    ->delete();
                foreach ($effectiveSelectedIds as $userId) {
                    Db::table('library_user_grants')->insert([
                        'library_id' => $libraryId,
                        'user_id' => $userId,
                        'access_level' => 'read',
                        'granted_by' => (string) $actor['id'],
                        'granted_at' => $now,
                    ]);
                }
                Db::table('users')->whereIn('id', $affectedIds)->update([
                    'permission_version' => Db::raw('permission_version + 1'),
                    'updated_at' => $now,
                ]);
                Db::table('music_libraries')->where('id', $libraryId)->update([
                    'version' => Db::raw('version + 1'),
                    'updated_by' => (string) $actor['id'],
                    'updated_at' => $now,
                ]);
                $this->auditLogger->record(
                    (string) $actor['id'],
                    'library.grants.replace',
                    'music_library',
                    $libraryId,
                    'success',
                    $requestId,
                    ['readGrantCount' => count($effectiveSelectedIds), 'affectedUserCount' => count($affectedIds)],
                );
                foreach ($affectedIds as $userId) {
                    $access = in_array($userId, $effectiveSelectedIds, true) ? 'read' : 'none';
                    $this->notifications->publishPermissionChange(
                        $userId,
                        'library_access',
                        $access,
                        $requestId . ':library_access:' . $libraryId . ':' . $userId,
                        $now,
                        (string) $library['name'],
                    );
                }
            });
        }

        return $this->grantOptions($libraryId, $actor);
    }

    /** @return array<string, mixed> */
    private function findManagedLibrary(string $libraryId, array $actor): array
    {
        $query = Db::table('music_libraries as libraries')
            ->leftJoin('webdav_library_connections as webdav', 'webdav.library_id', '=', 'libraries.id')
            ->leftJoin('onedrive_library_connections as onedrive', 'onedrive.library_id', '=', 'libraries.id')
            ->leftJoin('google_drive_library_connections as google_drive', 'google_drive.library_id', '=', 'libraries.id')
            ->leftJoin('network_proxy_profiles as proxy', 'proxy.id', '=', 'libraries.proxy_profile_id')
            ->where('libraries.id', $libraryId);
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as actor_grant', function ($join) use ($actor): void {
                $join->on('actor_grant.library_id', '=', 'libraries.id')
                    ->where('actor_grant.user_id', '=', (string) $actor['id'])
                    ->where('actor_grant.access_level', '=', 'manage');
            });
        }
        /** @var stdClass|null $row */
        $row = $query->first([
            'libraries.id', 'libraries.name', 'libraries.inbox_path', 'libraries.resolved_inbox_path',
            'libraries.root_path', 'libraries.resolved_root_path', 'libraries.scrape_storage_mode',
            'libraries.source_type', 'libraries.remote_metadata_mode', 'libraries.proxy_profile_id',
            'proxy.name as proxy_name', 'proxy.enabled as proxy_enabled', 'webdav.base_url as webdav_base_url',
            'webdav.remote_root_path as webdav_remote_root_path', 'webdav.username as webdav_username',
            'webdav.verify_tls as webdav_verify_tls', 'webdav.last_verified_at as webdav_last_verified_at',
            'webdav.last_error_code as webdav_last_error_code',
            'onedrive.tenant_id as onedrive_tenant_id', 'onedrive.client_id as onedrive_client_id',
            'onedrive.remote_root_path as onedrive_remote_root_path',
            'onedrive.authorization_status as onedrive_authorization_status',
            'onedrive.last_verified_at as onedrive_last_verified_at',
            'onedrive.last_error_code as onedrive_last_error_code',
            'google_drive.client_id as google_drive_client_id',
            'google_drive.drive_id as google_drive_drive_id',
            'google_drive.remote_root_path as google_drive_remote_root_path',
            'google_drive.authorization_status as google_drive_authorization_status',
            'google_drive.last_verified_at as google_drive_last_verified_at',
            'google_drive.last_error_code as google_drive_last_error_code',
            'libraries.status', 'libraries.symlink_policy', 'libraries.default_locale',
            'libraries.scan_mode', 'libraries.scan_status', 'libraries.artist_count',
            'libraries.album_count', 'libraries.song_count', 'libraries.last_scanned_at',
            'libraries.version', 'libraries.created_at',
        ]);
        if (!$row instanceof stdClass) {
            throw new LibraryNotFound('音乐库不存在。');
        }
        $grantCount = $this->grantCounts([$libraryId])[$libraryId] ?? 0;

        return $this->mapLibrary($row, $grantCount);
    }

    /** @param list<string> $libraryIds @return array<string, int> */
    private function grantCounts(array $libraryIds): array
    {
        if ($libraryIds === []) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('library_user_grants')
            ->whereIn('library_id', $libraryIds)
            ->groupBy('library_id')
            ->get(['library_id', Db::raw('COUNT(*) AS grant_count')])
            ->all();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->library_id] = (int) $row->grant_count;
        }

        return $counts;
    }

    /**
     * 解析音乐库根并应用所选派生资源模式的最小权限。
     *
     * 独立缓存模式只读取媒体；相邻模式会创建歌词，因此必须要求库根可写。真实路径与权限在事务外和
     * 写保留内重复检查，任一次失败都拒绝配置且不创建目录。
     */
    private function resolveLibraryRoot(string $path, string $storageMode): string
    {
        return $this->paths->resolveMediaDirectory(
            $path,
            '音乐库目录',
            $storageMode === 'adjacent',
        );
    }

    /** 固定缓存根不能等于或包含媒体库；缺失缓存由部署/启动预检报告，而不是 HTTP 请求创建。 */
    private function assertCacheSeparated(string $resolvedRoot): void
    {
        $configured = (string) (getenv('VELIN_SCRAPE_CACHE_PATH') ?: '/media/cache/scrape');
        $cache = realpath($configured);
        if ($cache !== false && $this->paths->overlaps($resolvedRoot, $cache)) {
            throw new LibraryConflict('音乐库目录不能与刮削缓存目录相同或互相包含。');
        }
    }

    /** 确认单个规范媒体根不与其他库或升级期旧目录重叠。 */
    private function assertPathAvailable(string $resolvedRoot, string $exceptLibraryId): void
    {
        /** @var list<stdClass> $libraries */
        $libraries = Db::table('music_libraries')->where('id', '<>', $exceptLibraryId)
            ->where('source_type', 'local')
            ->get(['resolved_inbox_path', 'resolved_root_path'])->all();
        foreach ($libraries as $library) {
            foreach ([(string) ($library->resolved_inbox_path ?? ''), (string) $library->resolved_root_path] as $path) {
                if ($path !== '' && $this->paths->overlaps($resolvedRoot, $path)) {
                    throw new LibraryConflict('音乐库目录与已有媒体或升级兼容目录重叠。');
                }
            }
        }
    }

    /**
     * Detects facts whose path or foreign-key identity prevents a direct library rebase/removal.
     *
     * The checks are intentionally conservative. A future relocation workflow must produce an
     * immutable plan and migrate these references; CRUD must never reinterpret existing media under
     * a newly supplied root.
     */
    private function libraryHasPathBoundFacts(string $libraryId): bool
    {
        return Db::table('media_songs')->where('library_id', $libraryId)->exists()
            || Db::table('library_file_inventory')->where('library_id', $libraryId)->exists();
    }

    /**
     * 更新 WebDAV 连接并保留未提交的新密码；受控写入只由资源插件发布器使用。
     *
     * 网络测试和加密均在 SQLite 写事务之前完成；失败不会改变旧连接。已有库存后禁止修改基址或远端
     * 根，因为虚拟路径与对象身份需要独立迁移。用户名、密码和 TLS 策略可原地轮换，提交使用库版本
     * CAS，连接表与通用库事实同一事务更新。
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function updateWebDavLibrary(
        string $libraryId,
        LibraryUpdateInput $input,
        array $current,
        array $actor,
        string $requestId,
    ): array {
        /** @var stdClass|null $connection */
        $connection = Db::table('webdav_library_connections')->where('library_id', $libraryId)->first([
            'base_url', 'remote_root_path', 'password_ciphertext',
        ]);
        if (!$connection instanceof stdClass || $input->webDavBaseUrl === null || $input->webDavUsername === null) {
            throw new LibraryConflict('WebDAV 连接配置不存在或已损坏。');
        }
        $identityChanged = (string) $connection->base_url !== $input->webDavBaseUrl
            || (string) $connection->remote_root_path !== (string) $input->rootPath;
        if ($identityChanged && $this->libraryHasPathBoundFacts($libraryId)) {
            throw new LibraryConflict('WebDAV 音乐库已有媒体，不能直接修改地址或远端根。');
        }
        $this->assertRemoteAvailable($input->webDavBaseUrl, (string) $input->rootPath, $libraryId);
        $password = $input->webDavPassword ?? $this->webDavCipher->decrypt((string) $connection->password_ciphertext);
        $proxy = $input->proxyProfileId === null ? null
            : (new NetworkProxyProfileService())->connection($input->proxyProfileId);
        try {
            $this->webDavClients->forConfiguration(
                $input->webDavBaseUrl,
                (string) $input->rootPath,
                $input->webDavUsername,
                $password,
                $input->webDavVerifyTls,
                $proxy,
            )->assertConnection();
        } finally {
            sodium_memzero($password);
        }
        $ciphertext = $input->webDavPassword === null
            ? (string) $connection->password_ciphertext
            : $this->webDavCipher->encrypt($input->webDavPassword);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $ciphertext, $identityChanged, $input, $libraryId, $now, $requestId): void {
            $changed = Db::table('music_libraries')->where('id', $libraryId)
                ->where('version', $input->expectedVersion)->update([
                    'name' => $input->name,
                    'root_path' => $input->rootPath,
                    'scrape_storage_mode' => 'managed_cache',
                    'remote_metadata_mode' => $input->remoteMetadataMode,
                    'proxy_profile_id' => $input->proxyProfileId,
                    'symlink_policy' => 'ignore',
                    'default_locale' => $input->defaultLocale,
                    'scan_mode' => $input->scanMode,
                    'version' => Db::raw('version + 1'),
                    'updated_by' => (string) $actor['id'],
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new LibraryConflict('音乐库已变化，请刷新后重试。');
            Db::table('webdav_library_connections')->where('library_id', $libraryId)->update([
                'base_url' => $input->webDavBaseUrl,
                'remote_root_path' => $input->rootPath,
                'username' => $input->webDavUsername,
                'password_ciphertext' => $ciphertext,
                'verify_tls' => $input->webDavVerifyTls ? 1 : 0,
                'last_verified_at' => $now,
                'last_error_code' => null,
                'updated_at' => $now,
            ]);
            $this->auditLogger->record((string) $actor['id'], 'library.update', 'music_library',
                $libraryId, 'success', $requestId, [
                    'sourceType' => 'webdav', 'remoteIdentityChanged' => $identityChanged,
                    'remoteMetadataMode' => $input->remoteMetadataMode,
                    'credentialsReplaced' => $input->webDavPassword !== null,
                ]);
        });
        return $this->findManagedLibrary($libraryId, $actor);
    }

    /**
     * 更新 OneDrive 企业版连接，并以库版本 CAS 原子保存验证后的事实。
     *
     * OAuth/Graph 验证和 refresh token 解密都在 SQLite 事务外完成；失败时旧连接完全保留。已有库存后，
     * 租户、应用 ID、授权账号、stable drive ID 或盘内根均不能原地改变，因为它们共同决定远端对象身份。
     * 已授权连接可省略授权 ID 继续使用现有 grant；重新授权会在成功事务内原子消费会话并替换密文。
     * 成功不修改远端文件，也不自动触发扫描。
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function updateOneDriveLibrary(
        string $libraryId,
        LibraryUpdateInput $input,
        array $current,
        array $actor,
        string $requestId,
    ): array {
        /** @var stdClass|null $connection */
        $connection = Db::table('onedrive_library_connections')->where('library_id', $libraryId)->first([
            'tenant_id', 'client_id', 'refresh_token_ciphertext', 'account_id', 'drive_id',
            'remote_root_path', 'authorization_status',
        ]);
        if (!$connection instanceof stdClass || $input->oneDriveTenantId === null
            || $input->oneDriveClientId === null) {
            throw new LibraryConflict('OneDrive 连接配置不存在或已损坏。');
        }
        $grant = null;
        $replacementCiphertext = null;
        $accountId = (string) ($connection->account_id ?? '');
        $driveId = (string) ($connection->drive_id ?? '');
        if ($input->oneDriveAuthorizationId !== null) {
            $grant = $this->oneDriveAuthorizations->completedGrant($input->oneDriveAuthorizationId, $actor);
            if ($grant->tenantId !== $input->oneDriveTenantId || $grant->clientId !== $input->oneDriveClientId
                || $grant->proxyProfileId !== $input->proxyProfileId) {
                sodium_memzero($grant->refreshToken);
                throw new LibraryValidationFailed([
                    'oneDriveAuthorizationId' => ['授权使用的租户或客户端 ID 与表单不一致。'],
                ]);
            }
            $accountId = $grant->accountId;
            $driveId = $grant->driveId;
            $replacementCiphertext = $grant->refreshTokenCiphertext;
        } elseif (($current['proxyProfileId'] ?? null) !== $input->proxyProfileId) {
            throw new LibraryValidationFailed([
                'oneDriveAuthorizationId' => ['切换 OneDrive 代理后必须重新完成 Microsoft 授权。'],
            ]);
        } elseif ((string) $connection->authorization_status !== 'authorized') {
            throw new LibraryValidationFailed([
                'oneDriveAuthorizationId' => ['该旧 OneDrive 连接需要重新完成 Microsoft 授权。'],
            ]);
        } elseif ((string) $connection->tenant_id !== $input->oneDriveTenantId
            || (string) $connection->client_id !== $input->oneDriveClientId) {
            throw new LibraryValidationFailed([
                'oneDriveAuthorizationId' => ['修改租户或客户端 ID 前必须重新完成 Microsoft 授权。'],
            ]);
        }
        $identityChanged = (string) $connection->tenant_id !== $input->oneDriveTenantId
            || (string) $connection->client_id !== $input->oneDriveClientId
            || (string) ($connection->account_id ?? '') !== $accountId
            || (string) ($connection->drive_id ?? '') !== $driveId
            || (string) $connection->remote_root_path !== (string) $input->rootPath;
        if ($identityChanged && $this->libraryHasPathBoundFacts($libraryId)) {
            if ($grant instanceof OneDriveAuthorizationGrant) sodium_memzero($grant->refreshToken);
            throw new LibraryConflict('OneDrive 音乐库已有媒体，不能直接修改租户、应用、授权账号、drive 或盘内根。');
        }
        $this->assertOneDriveAvailable(
            $driveId,
            (string) $input->rootPath,
            $libraryId,
        );
        if ($grant instanceof OneDriveAuthorizationGrant) {
            $proxy = $input->proxyProfileId === null ? null
                : (new NetworkProxyProfileService())->connection($input->proxyProfileId);
            try {
                $this->oneDriveClients->forConfiguration(
                    $grant->tenantId,
                    $grant->clientId,
                    $grant->refreshToken,
                    $grant->driveId,
                    (string) $input->rootPath,
                    proxy: $proxy,
                )->assertConnection();
            } finally {
                sodium_memzero($grant->refreshToken);
            }
        } else {
            $this->oneDriveClients->forLibrary($libraryId)->assertConnection();
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use (
            $accountId,
            $actor,
            $driveId,
            $grant,
            $identityChanged,
            $input,
            $libraryId,
            $now,
            $replacementCiphertext,
            $requestId,
        ): void {
            $changed = Db::table('music_libraries')->where('id', $libraryId)
                ->where('version', $input->expectedVersion)->update([
                    'name' => $input->name,
                    'root_path' => $input->rootPath,
                    'scrape_storage_mode' => 'managed_cache',
                    'remote_metadata_mode' => $input->remoteMetadataMode,
                    'proxy_profile_id' => $input->proxyProfileId,
                    'symlink_policy' => 'ignore',
                    'default_locale' => $input->defaultLocale,
                    'scan_mode' => $input->scanMode,
                    'version' => Db::raw('version + 1'),
                    'updated_by' => (string) $actor['id'],
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new LibraryConflict('音乐库已变化，请刷新后重试。');
            $connectionUpdate = [
                'tenant_id' => $input->oneDriveTenantId,
                'client_id' => $input->oneDriveClientId,
                'account_id' => $accountId,
                'drive_id' => $driveId,
                'remote_root_path' => $input->rootPath,
                'last_verified_at' => $now,
                'last_error_code' => null,
                'updated_at' => $now,
            ];
            if ($grant instanceof OneDriveAuthorizationGrant && is_string($replacementCiphertext)) {
                $connectionUpdate += [
                    'legacy_client_secret_ciphertext' => null,
                    'legacy_user_principal_name' => null,
                    'refresh_token_ciphertext' => $replacementCiphertext,
                    'authorization_status' => 'authorized',
                ];
            }
            Db::table('onedrive_library_connections')->where('library_id', $libraryId)->update($connectionUpdate);
            if ($grant instanceof OneDriveAuthorizationGrant) {
                $this->oneDriveAuthorizations->consume($grant->authorizationId, $actor);
            }
            $this->auditLogger->record((string) $actor['id'], 'library.update', 'music_library',
                $libraryId, 'success', $requestId, [
                    'sourceType' => 'onedrive', 'remoteIdentityChanged' => $identityChanged,
                    'remoteMetadataMode' => $input->remoteMetadataMode,
                    'credentialsReplaced' => $grant instanceof OneDriveAuthorizationGrant,
                ]);
        });
        return $this->findManagedLibrary($libraryId, $actor);
    }

    /**
     * 更新 Google Drive 连接，并以库版本 CAS 原子消费可选的新 OAuth 读写 grant。
     *
     * OAuth/Drive 验证在 SQLite 事务外完成。未提交授权 ID时只能保留完全相同的 client、账号、drive 与
     * 根；修改 drive 或根也要求重新授权，以便使用新 grant 对新对象树做真实预检。已有库存后任何远端
     * 身份变化均拒绝，防止相同相对路径被重新解释。事务只保存密文、通用库策略和脱敏审计，不访问
     * Google、不触发扫描，也不修改远端文件；CAS 或消费失败会回滚旧连接。
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    private function updateGoogleDriveLibrary(
        string $libraryId,
        LibraryUpdateInput $input,
        array $current,
        array $actor,
        string $requestId,
    ): array {
        /** @var stdClass|null $connection */
        $connection = Db::table('google_drive_library_connections')->where('library_id', $libraryId)->first([
            'client_id', 'client_secret_ciphertext', 'refresh_token_ciphertext', 'account_id', 'drive_id',
            'remote_root_path', 'authorization_status',
        ]);
        if (!$connection instanceof stdClass) {
            throw new LibraryConflict('Google Drive 连接配置不存在或已损坏。');
        }
        $grant = null;
        $clientId = (string) $connection->client_id;
        $accountId = (string) ($connection->account_id ?? '');
        if ($input->googleDriveAuthorizationId !== null) {
            $grant = $this->googleDriveAuthorizations->completedGrant(
                $input->googleDriveAuthorizationId,
                $actor,
            );
            if ($grant->proxyProfileId !== $input->proxyProfileId) {
                sodium_memzero($grant->clientSecret);
                sodium_memzero($grant->refreshToken);
                throw new LibraryValidationFailed([
                    'googleDriveAuthorizationId' => ['授权使用的代理与表单选择不一致，请重新授权。'],
                ]);
            }
            $clientId = $grant->clientId;
            $accountId = $grant->accountId;
        } elseif (($current['proxyProfileId'] ?? null) !== $input->proxyProfileId) {
            throw new LibraryValidationFailed([
                'googleDriveAuthorizationId' => ['切换 Google Drive 代理后必须重新完成 Google 授权。'],
            ]);
        } elseif ((string) $connection->authorization_status !== 'authorized') {
            throw new LibraryValidationFailed([
                'googleDriveAuthorizationId' => ['该 Google Drive 连接需要重新授权。'],
            ]);
        }
        $identityChanged = (string) $connection->client_id !== $clientId
            || (string) ($connection->account_id ?? '') !== $accountId
            || (string) $connection->drive_id !== $input->googleDriveId
            || (string) $connection->remote_root_path !== (string) $input->rootPath;
        if ($identityChanged && !$grant instanceof GoogleDriveAuthorizationGrant) {
            throw new LibraryValidationFailed([
                'googleDriveAuthorizationId' => ['修改 Google 账号、盘或根路径前必须重新授权。'],
            ]);
        }
        if ($identityChanged && $this->libraryHasPathBoundFacts($libraryId)) {
            if ($grant instanceof GoogleDriveAuthorizationGrant) {
                sodium_memzero($grant->clientSecret);
                sodium_memzero($grant->refreshToken);
            }
            throw new LibraryConflict('Google Drive 音乐库已有媒体，不能直接修改账号、盘或根路径。');
        }
        $this->assertGoogleDriveAvailable($accountId, $input->googleDriveId, (string) $input->rootPath, $libraryId);
        if ($grant instanceof GoogleDriveAuthorizationGrant) {
            $proxy = $input->proxyProfileId === null ? null
                : (new NetworkProxyProfileService())->connection($input->proxyProfileId);
            try {
                $this->googleDriveClients->forConfiguration(
                    $grant->clientId,
                    $grant->clientSecret,
                    $grant->refreshToken,
                    $input->googleDriveId,
                    (string) $input->rootPath,
                    proxy: $proxy,
                )->assertConnection();
            } finally {
                sodium_memzero($grant->clientSecret);
                sodium_memzero($grant->refreshToken);
            }
        } else {
            $this->googleDriveClients->forLibrary($libraryId)->assertConnection();
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use (
            $accountId,
            $actor,
            $clientId,
            $connection,
            $grant,
            $identityChanged,
            $input,
            $libraryId,
            $now,
            $requestId,
        ): void {
            $changed = Db::table('music_libraries')->where('id', $libraryId)
                ->where('version', $input->expectedVersion)->update([
                    'name' => $input->name,
                    'root_path' => $input->rootPath,
                    'scrape_storage_mode' => 'managed_cache',
                    'remote_metadata_mode' => $input->remoteMetadataMode,
                    'proxy_profile_id' => $input->proxyProfileId,
                    'symlink_policy' => 'ignore',
                    'default_locale' => $input->defaultLocale,
                    'scan_mode' => $input->scanMode,
                    'version' => Db::raw('version + 1'),
                    'updated_by' => (string) $actor['id'],
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new LibraryConflict('音乐库已变化，请刷新后重试。');
            $connectionUpdate = [
                'client_id' => $clientId,
                'account_id' => $accountId,
                'drive_id' => $input->googleDriveId,
                'remote_root_path' => $input->rootPath,
                'last_verified_at' => $now,
                'last_error_code' => null,
                'updated_at' => $now,
            ];
            if ($grant instanceof GoogleDriveAuthorizationGrant) {
                $connectionUpdate += [
                    'client_secret_ciphertext' => $grant->clientSecretCiphertext,
                    'refresh_token_ciphertext' => $grant->refreshTokenCiphertext,
                    'authorization_status' => 'authorized',
                ];
            }
            Db::table('google_drive_library_connections')->where('library_id', $libraryId)->update($connectionUpdate);
            if ($grant instanceof GoogleDriveAuthorizationGrant) {
                $this->googleDriveAuthorizations->consume($grant->authorizationId, $actor);
            }
            $this->auditLogger->record((string) $actor['id'], 'library.update', 'music_library',
                $libraryId, 'success', $requestId, [
                    'sourceType' => 'google_drive',
                    'remoteIdentityChanged' => $identityChanged,
                    'remoteMetadataMode' => $input->remoteMetadataMode,
                    'credentialsReplaced' => $grant instanceof GoogleDriveAuthorizationGrant,
                ]);
        });
        return $this->findManagedLibrary($libraryId, $actor);
    }

    /** WebDAV DTO 必须完整；此保护避免未来内部调用绕过 HTTP 校验后持久化空连接。 */
    private function assertWebDavInputComplete(LibraryCreateInput $input): void
    {
        if ($input->webDavBaseUrl === null || $input->webDavUsername === null || $input->webDavPassword === null) {
            throw new LibraryValidationFailed(['request' => ['WebDAV 连接配置不完整。']]);
        }
    }

    /** OneDrive DTO 必须含应用身份和一次性授权 ID，防止内部调用持久化不可认证连接。 */
    private function assertOneDriveInputComplete(LibraryCreateInput $input): void
    {
        if ($input->oneDriveTenantId === null || $input->oneDriveClientId === null
            || $input->oneDriveAuthorizationId === null) {
            throw new LibraryValidationFailed(['request' => ['OneDrive 企业版连接配置不完整。']]);
        }
    }

    /** Google Drive 创建必须携带绑定 actor 的一次性授权 ID；client secret 不进入通用 DTO。 */
    private function assertGoogleDriveInputComplete(LibraryCreateInput $input): void
    {
        if ($input->googleDriveAuthorizationId === null) {
            throw new LibraryValidationFailed(['request' => ['Google Drive 连接配置不完整。']]);
        }
    }

    /** 相同规范基址和远端根只能登记一次，避免重复索引同一网络对象树。 */
    private function assertRemoteAvailable(string $baseUrl, string $remoteRoot, string $exceptLibraryId): void
    {
        $query = Db::table('webdav_library_connections')->where('base_url', $baseUrl)
            ->where('remote_root_path', $remoteRoot);
        if ($exceptLibraryId !== '') $query->where('library_id', '<>', $exceptLibraryId);
        if ($query->exists()) throw new LibraryConflict('该 WebDAV 地址和远端根已经配置为音乐库。');
    }

    /** 同一稳定 drive 的盘内根只能登记一次，避免账号别名变化造成同一对象树重复索引。 */
    private function assertOneDriveAvailable(
        string $driveId,
        string $remoteRoot,
        string $exceptLibraryId,
    ): void {
        $query = Db::table('onedrive_library_connections')->where('drive_id', $driveId)
            ->where('remote_root_path', $remoteRoot);
        if ($exceptLibraryId !== '') $query->where('library_id', '<>', $exceptLibraryId);
        if ($query->exists()) throw new LibraryConflict('该 OneDrive drive 和盘内根已经配置为音乐库。');
    }

    /** 同一 Google 账号、drive 与根只能登记一次；账号稳定 ID不进入错误或投影。 */
    private function assertGoogleDriveAvailable(
        string $accountId,
        string $driveId,
        string $remoteRoot,
        string $exceptLibraryId,
    ): void {
        $query = Db::table('google_drive_library_connections')->where('account_id', $accountId)
            ->where('drive_id', $driveId)->where('remote_root_path', $remoteRoot);
        if ($exceptLibraryId !== '') $query->where('library_id', '<>', $exceptLibraryId);
        if ($query->exists()) throw new LibraryConflict('该 Google Drive 盘和根路径已经配置为音乐库。');
    }

    /** @return array<string, mixed> */
    private function mapLibrary(stdClass $row, int $grantCount): array
    {
        $sourceType = (string) ($row->source_type ?? 'local');
        $isDefault = (string) $row->id === self::DEFAULT_LIBRARY_ID
            || (string) $row->root_path === '/media/library';
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'rootPath' => (string) $row->root_path,
            'resolvedRootPath' => $sourceType !== 'local' ? (string) $row->root_path : (string) $row->resolved_root_path,
            'sourceType' => $sourceType,
            'remoteMetadataMode' => $sourceType === 'local'
                ? null
                : (string) ($row->remote_metadata_mode ?? 'filename_only'),
            'useProxy' => $sourceType !== 'local' && $row->proxy_profile_id !== null,
            'proxyProfileId' => $sourceType === 'local' || $row->proxy_profile_id === null
                ? null : (string) $row->proxy_profile_id,
            'proxy' => $sourceType === 'local' || $row->proxy_profile_id === null ? null : [
                'id' => (string) $row->proxy_profile_id,
                'name' => (string) $row->proxy_name,
                'enabled' => (int) $row->proxy_enabled === 1,
            ],
            'webDav' => $sourceType === 'webdav' ? [
                'baseUrl' => (string) $row->webdav_base_url,
                'rootPath' => (string) $row->webdav_remote_root_path,
                'username' => (string) $row->webdav_username,
                'verifyTls' => (int) $row->webdav_verify_tls === 1,
                'credentialConfigured' => true,
                'lastVerifiedAt' => $row->webdav_last_verified_at === null ? null : (string) $row->webdav_last_verified_at,
                'lastErrorCode' => $row->webdav_last_error_code === null ? null : (string) $row->webdav_last_error_code,
            ] : null,
            'oneDrive' => $sourceType === 'onedrive' ? [
                'tenantId' => (string) $row->onedrive_tenant_id,
                'clientId' => (string) $row->onedrive_client_id,
                'rootPath' => (string) $row->onedrive_remote_root_path,
                'authorizationStatus' => (string) $row->onedrive_authorization_status,
                'credentialConfigured' => (string) $row->onedrive_authorization_status === 'authorized',
                'lastVerifiedAt' => $row->onedrive_last_verified_at === null ? null : (string) $row->onedrive_last_verified_at,
                'lastErrorCode' => $row->onedrive_last_error_code === null ? null : (string) $row->onedrive_last_error_code,
            ] : null,
            'googleDrive' => $sourceType === 'google_drive' ? [
                'clientId' => (string) $row->google_drive_client_id,
                'driveId' => (string) $row->google_drive_drive_id,
                'rootPath' => (string) $row->google_drive_remote_root_path,
                'authorizationStatus' => (string) $row->google_drive_authorization_status,
                'credentialConfigured' => (string) $row->google_drive_authorization_status === 'authorized',
                'lastVerifiedAt' => $row->google_drive_last_verified_at === null
                    ? null : (string) $row->google_drive_last_verified_at,
                'lastErrorCode' => $row->google_drive_last_error_code === null
                    ? null : (string) $row->google_drive_last_error_code,
            ] : null,
            'scrapeStorageMode' => (string) $row->scrape_storage_mode,
            'status' => (string) $row->status,
            'symlinkPolicy' => (string) $row->symlink_policy,
            'defaultLocale' => (string) $row->default_locale,
            'scanMode' => (string) $row->scan_mode,
            'scanStatus' => (string) $row->scan_status,
            'artistCount' => (int) $row->artist_count,
            'albumCount' => (int) $row->album_count,
            'songCount' => (int) $row->song_count,
            'lastScannedAt' => $row->last_scanned_at === null ? null : (string) $row->last_scanned_at,
            'grantCount' => $grantCount,
            'version' => (int) $row->version,
            'createdAt' => (string) $row->created_at,
            'isDefault' => $isDefault,
        ];
    }
}
