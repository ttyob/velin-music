<?php

declare(strict_types=1);

namespace app\application\User;

use app\application\Auth\AuthorizationDenied;
use app\application\Subsonic\SubsonicCredentialCipher;
use app\application\Notification\NotificationPublisher;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * Owns global account administration while preserving super-admin and session boundaries.
 *
 * This service does not assign music libraries; a created user has global capabilities from its
 * role but no media scope until the library-grant slice is implemented. Password hashing occurs
 * before write transactions. Account, preferences, role assignment, permission version, session
 * revocation, and audit changes commit atomically where they describe one management command.
 */
final class UserManagementService
{
    public function __construct(
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly SubsonicCredentialCipher $subsonicCredentials = new SubsonicCredentialCipher(),
        private readonly NotificationPublisher $notifications = new NotificationPublisher(),
        private readonly AccountDeactivationService $deactivation = new AccountDeactivationService(),
    ) {
    }

    /**
     * Returns a bounded management list and the assignable role vocabulary.
     *
     * Search and status values are parameter-bound. The result contains no password, Session ID,
     * user-agent digest, private activity, or physical-path data. Pagination will move to cursors
     * before the configured account scale exceeds this bounded first-release list.
     *
     * @param array{status?:?string,q?:string,role?:?string,capability?:?string,libraryId?:?string,lastLoginFrom?:?string,lastLoginTo?:?string} $filters
     * @return array<string,mixed>
     */
    public function listUsers(array $filters, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $query = Db::table('users')->whereNull('deleted_at');
        $status = $filters['status'] ?? null;
        if (in_array($status, ['active', 'disabled'], true)) {
            $query->where('status', $status);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $boundedSearch = function_exists('mb_substr')
                ? mb_substr($search, 0, 100, 'UTF-8')
                : substr($search, 0, 100);
            $query->where(static function ($nested) use ($boundedSearch): void {
                $nested->where('username', 'like', '%' . $boundedSearch . '%')
                    ->orWhere('display_name', 'like', '%' . $boundedSearch . '%')
                    ->orWhere('email', 'like', '%' . $boundedSearch . '%');
            });
        }

        // 角色、能力和音乐库条件使用 EXISTS，避免多对多 JOIN 把一个用户扩张成多行并扭曲 total。
        $role = $this->optionalToken($filters['role'] ?? null, 64);
        if ($role === 'super_admin') {
            $query->where('is_super_admin', 1);
        } elseif ($role !== null) {
            $query->whereExists(static function ($subquery) use ($role): void {
                $subquery->selectRaw('1')->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->whereColumn('user_roles.user_id', 'users.id')
                    ->where('roles.role_key', $role);
            });
        }
        $capability = $this->optionalToken($filters['capability'] ?? null, 64);
        if ($capability !== null) {
            $query->where(static function ($nested) use ($capability): void {
                $nested->where('is_super_admin', 1)->orWhereExists(static function ($subquery) use ($capability): void {
                    $subquery->selectRaw('1')->from('user_roles')
                        ->join('role_capabilities', 'role_capabilities.role_id', '=', 'user_roles.role_id')
                        ->whereColumn('user_roles.user_id', 'users.id')
                        ->where('role_capabilities.capability_key', $capability);
                })->orWhereExists(static function ($subquery) use ($capability): void {
                    $subquery->selectRaw('1')->from('user_capabilities')
                        ->whereColumn('user_capabilities.user_id', 'users.id')
                        ->where('user_capabilities.capability_key', $capability);
                });
            });
        }
        $libraryId = $this->optionalUlid($filters['libraryId'] ?? null);
        if ($libraryId !== null) {
            $query->where(static function ($nested) use ($libraryId): void {
                $nested->where('is_super_admin', 1)->orWhereExists(static function ($subquery) use ($libraryId): void {
                    $subquery->selectRaw('1')->from('library_user_grants')
                        ->whereColumn('library_user_grants.user_id', 'users.id')
                        ->where('library_user_grants.library_id', $libraryId);
                });
            });
        }
        $from = $this->optionalDate($filters['lastLoginFrom'] ?? null, false);
        $to = $this->optionalDate($filters['lastLoginTo'] ?? null, true);
        if ($from !== null) $query->where('last_login_at', '>=', $from);
        if ($to !== null) $query->where('last_login_at', '<=', $to);
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderByDesc('is_super_admin')
            ->orderBy('username')
            ->limit($limit)
            ->get([
                'id', 'username', 'display_name', 'email', 'status', 'is_super_admin',
                'locale', 'timezone', 'permission_version', 'account_expires_at',
                'last_login_at', 'created_at', 'updated_at',
                Db::raw('(SELECT version FROM user_preferences WHERE user_preferences.user_id = users.id) '
                    . 'AS profile_version'),
            ])->all();

        $userIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $rolesByUser = $this->rolesByUser($userIds);
        $sessionsByUser = $this->activeLoginSessionsByUser($userIds);
        $capabilities = $this->capabilityVocabulary();
        $libraries = $this->libraryVocabulary();
        $capabilitiesByUser = $this->capabilitiesByUser($userIds);
        $directCapabilitiesByUser = $this->directCapabilitiesByUser($userIds);
        $librariesByUser = $this->librariesByUser($userIds);
        $users = array_map(
            fn (stdClass $row): array => $this->mapUser(
                $row,
                $rolesByUser[(string) $row->id] ?? [],
                $sessionsByUser[(string) $row->id] ?? 0,
                (int) $row->is_super_admin === 1
                    ? array_column($capabilities, 'key')
                    : ($capabilitiesByUser[(string) $row->id] ?? []),
                (int) $row->is_super_admin === 1
                    ? []
                    : ($directCapabilitiesByUser[(string) $row->id] ?? []),
                (int) $row->is_super_admin === 1
                    ? array_map(static fn (array $library): array => $library + ['accessLevel' => 'manage'], $libraries)
                    : ($librariesByUser[(string) $row->id] ?? []),
            ),
            $rows,
        );

        /** @var list<stdClass> $roleRows */
        $roleRows = Db::table('roles')
            ->where('is_system', 1)
            ->orderBy('role_key')
            ->get(['role_key', 'name', 'description'])
            ->all();
        $roles = array_map(static fn (stdClass $role): array => [
            'key' => (string) $role->role_key,
            'name' => (string) $role->name,
            'description' => (string) $role->description,
        ], $roleRows);

        return [
            'users' => $users,
            'roles' => $roles,
            'capabilities' => $capabilities,
            'libraries' => $libraries,
            'total' => $total,
            'limit' => $limit,
            'filters' => [
                'status' => in_array($status, ['active', 'disabled'], true) ? $status : null,
                'q' => $search,
                'role' => $role,
                'capability' => $capability,
                'libraryId' => $libraryId,
                'lastLoginFrom' => $filters['lastLoginFrom'] ?? null,
                'lastLoginTo' => $filters['lastLoginTo'] ?? null,
            ],
        ];
    }

    /**
     * Creates one non-super-admin local account, one built-in base role, and optional direct capabilities.
     *
     * @param string $actorUserId Authenticated manager recorded in assignment and audit rows.
     * @return array<string, mixed> Sanitized created-user projection.
     * @throws UserConflict Username already exists or role became unavailable.
     */
    public function createUser(UserCreateInput $input, string $actorUserId, string $requestId): array
    {
        $passwordHash = password_hash($input->password, PASSWORD_ARGON2ID);
        if (!is_string($passwordHash)) {
            throw new \RuntimeException('Argon2id password hashing failed.');
        }
        // Provision protocol compatibility before the transaction so encryption/key failures do
        // not create a partially usable identity or occupy SQLite's single writer.
        $subsonicCiphertext = $this->subsonicCredentials->encrypt($input->password);

        /** @var stdClass|null $role */
        $role = Db::table('roles')
            ->where('role_key', $input->roleKey)
            ->where('is_system', 1)
            ->first(['id', 'role_key', 'name']);
        if (!$role instanceof stdClass) {
            throw new UserConflict('所选角色已不可用。');
        }

        $userId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use (
                $actorUserId,
                $input,
                $now,
                $passwordHash,
                $requestId,
                $role,
                $subsonicCiphertext,
                $userId,
            ): void {
                Db::table('users')->insert([
                    'id' => $userId,
                    'username' => $input->username,
                    'display_name' => $input->displayName,
                    'email' => $input->email,
                    'password_hash' => $passwordHash,
                    'subsonic_secret_ciphertext' => $subsonicCiphertext,
                    'status' => 'active',
                    'is_super_admin' => 0,
                    'locale' => $input->locale,
                    'timezone' => $input->timezone,
                    'permission_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'account_expires_at' => $input->accountExpiresAt,
                    'last_login_at' => null,
                ]);
                Db::table('user_preferences')->insert([
                    'user_id' => $userId,
                    'theme_id' => null,
                    'locale' => $input->locale,
                    'timezone' => $input->timezone,
                    'version' => 1,
                    'updated_at' => $now,
                ]);
                Db::table('user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => (string) $role->id,
                    'assigned_by' => $actorUserId,
                    'assigned_at' => $now,
                ]);
                foreach ($input->directCapabilities as $capability) {
                    Db::table('user_capabilities')->insert([
                        'user_id' => $userId,
                        'capability_key' => $capability,
                        'assigned_by' => $actorUserId,
                        'assigned_at' => $now,
                    ]);
                }
                $this->auditLogger->record(
                    $actorUserId,
                    'user.create',
                    'user',
                    $userId,
                    'success',
                    $requestId,
                    [
                        'role' => (string) $role->role_key,
                        'directCapabilities' => $input->directCapabilities,
                    ],
                );
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new UserConflict('该用户名已存在。', previous: $exception);
            }
            throw $exception;
        }

        return $this->findUser($userId);
    }

    /**
     * Enables or disables a target account and invalidates sessions on disable.
     *
     * Managers cannot disable themselves. Non-super managers cannot alter a super administrator,
     * and the final active super administrator can never be disabled. Permission version changes
     * in the same transaction so cached authorization snapshots become stale immediately.
     *
     * @param array<string, mixed> $actor Sanitized actor from AuthorizationService.
     * @return array<string, mixed> Updated target projection.
     * @throws UserNotFound Target is absent or soft-deleted.
     * @throws UserConflict Self-disable, final-super-admin, or invalid state transition.
     * @throws AuthorizationDenied Non-super manager targets a super administrator.
     */
    public function changeStatus(
        string $targetUserId,
        string $status,
        array $actor,
        string $requestId,
    ): array {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new UserConflict('不支持的账户状态。');
        }
        if (($actor['id'] ?? null) === $targetUserId && $status === 'disabled') {
            throw new UserConflict('不能停用当前登录账户。');
        }

        /** @var stdClass|null $target */
        $target = Db::table('users')
            ->where('id', $targetUserId)
            ->whereNull('deleted_at')
            ->first(['id', 'status', 'is_super_admin']);
        if (!$target instanceof stdClass) {
            throw new UserNotFound('用户不存在。');
        }
        $targetIsSuper = (int) $target->is_super_admin === 1;
        if ($targetIsSuper && !($actor['isSuperAdmin'] ?? false)) {
            throw new AuthorizationDenied('Only a super administrator may manage another super administrator.');
        }
        if ($targetIsSuper && $status === 'disabled') {
            $activeSuperAdmins = Db::table('users')
                ->where('is_super_admin', 1)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();
            if ($activeSuperAdmins <= 1) {
                throw new UserConflict('不能停用最后一个有效的超级管理员。');
            }
        }
        if ((string) $target->status === $status) {
            return $this->findUser($targetUserId);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $now, $requestId, $status, $target, $targetUserId): void {
            Db::table('users')->where('id', $targetUserId)->update([
                'status' => $status,
                'permission_version' => Db::raw('permission_version + 1'),
                'updated_at' => $now,
            ]);
            $revocation = null;
            if ($status === 'disabled') {
                // 认证撤销和上传取消事实必须与状态同时提交；文件清理由 Upload Worker 在事务外完成。
                $revocation = $this->deactivation->deactivateInTransaction([$targetUserId], $now);
            }
            $this->auditLogger->record(
                (string) $actor['id'],
                'user.status.change',
                'user',
                $targetUserId,
                'success',
                $requestId,
                [
                    'from' => (string) $target->status,
                    'to' => $status,
                    'sessionsRevoked' => $revocation['sessionsRevoked'] ?? 0,
                    'tokensRevoked' => $revocation['tokensRevoked'] ?? 0,
                    'uploadsCancelled' => $revocation['uploadsCancelled'] ?? 0,
                    'uploadsCancelRequested' => $revocation['uploadsCancelRequested'] ?? 0,
                ],
            );
            $this->notifications->publishPermissionChange(
                $targetUserId,
                'account_status',
                $status,
                $requestId . ':account_status:' . $targetUserId,
                $now,
            );
        });

        return $this->findUser($targetUserId);
    }

    /**
     * 以乐观版本把普通账号替换为一个可委派内置基础角色。
     *
     * 目标必须是非超级管理员，操作者不能修改自己的角色；roleKey 仅允许 administrator、listener，
     * 因此该命令不能构造超级管理员或引用未来未知系统角色。角色关联、permission_version、审计和受影响
     * 用户通知在同一短事务提交，条件更新失败时全部回滚。授权解析每次读取权威关联，所以成功后 Web、
     * CLI、个人令牌和 DLNA 票据的下一次请求立即看到新权限；命令不停止 Renderer，也不修改音乐库授权。
     *
     * @param array<string,mixed> $actor 已由 Controller 实时验证 manage_users 的 Session actor
     * @return array<string,mixed> 不含密码、Session 标识和物理路径的最新用户投影
     */
    public function changeRole(
        string $targetUserId,
        string $roleKey,
        int $expectedPermissionVersion,
        array $actor,
        string $requestId,
    ): array {
        if (!in_array($roleKey, ['administrator', 'listener'], true)
            || $expectedPermissionVersion < 1) {
            throw new UserConflict('角色变更请求无效。');
        }
        if (($actor['id'] ?? null) === $targetUserId) {
            throw new UserConflict('不能修改当前登录账户的角色。');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use (
            $actor, $expectedPermissionVersion, $now, $requestId, $roleKey, $targetUserId,
        ): void {
            /** @var stdClass|null $target */
            $target = Db::table('users')->where('id', $targetUserId)->whereNull('deleted_at')
                ->first(['id', 'is_super_admin', 'permission_version']);
            if (!$target instanceof stdClass) throw new UserNotFound('用户不存在。');
            if ((int) $target->is_super_admin === 1) {
                throw new AuthorizationDenied('Super administrator roles cannot be replaced.');
            }
            if ((int) $target->permission_version !== $expectedPermissionVersion) {
                throw new UserConflict('用户权限已变化，请刷新后重试。');
            }
            /** @var stdClass|null $role */
            $role = Db::table('roles')->where('role_key', $roleKey)->where('is_system', 1)
                ->first(['id', 'role_key']);
            if (!$role instanceof stdClass) throw new UserConflict('所选角色已不可用。');

            $previousRoles = Db::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $targetUserId)->orderBy('roles.role_key')
                ->pluck('roles.role_key')->map(static fn (mixed $value): string => (string) $value)->all();
            if ($previousRoles === [$roleKey]) return;

            Db::table('user_roles')->where('user_id', $targetUserId)->delete();
            Db::table('user_roles')->insert([
                'user_id' => $targetUserId,
                'role_id' => (string) $role->id,
                'assigned_by' => (string) $actor['id'],
                'assigned_at' => $now,
            ]);
            $updated = Db::table('users')->where('id', $targetUserId)
                ->where('permission_version', $expectedPermissionVersion)->update([
                    'permission_version' => Db::raw('permission_version + 1'),
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) throw new UserConflict('用户权限已变化，请刷新后重试。');

            $this->auditLogger->record((string) $actor['id'], 'user.role.change', 'user', $targetUserId,
                'success', $requestId, ['from' => $previousRoles, 'to' => $roleKey]);
            $this->notifications->publishPermissionChange(
                $targetUserId,
                'role',
                $roleKey,
                $requestId . ':role:' . $targetUserId,
                $now,
            );
        });

        return $this->findUser($targetUserId);
    }

    /**
     * 原子保存后台编辑弹窗中的账号资料、基础角色和用户直授能力。
     *
     * 用户名和认证秘密不可修改；状态、库授权及限额继续使用独立命令。目标账号的 permission_version
     * 与 user_preferences.version 都必须匹配最近列表快照，以同时发现管理员角色修改和用户自行修改资料。普通管理
     * 员不能编辑超级管理员，当前操作者不能改变自己的角色或给自己新增直授能力。users、user_preferences、
     * 角色/直授关联、审计和权限通知在同一短事务提交；冲突全部回滚且客户端必须刷新，不会自动重放陈旧表单。
     *
     * @param array<string,mixed> $actor 已实时验证 manage_users 的 Session actor
     * @return array<string,mixed> 最新脱敏用户投影
     */
    public function updateUser(
        string $targetUserId,
        UserUpdateInput $input,
        array $actor,
        string $requestId,
    ): array {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $targetUserId) !== 1) {
            throw new UserNotFound('用户不存在。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $input, $now, $requestId, $targetUserId): void {
            /** @var stdClass|null $target */
            $target = Db::table('users')->join('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
                ->where('users.id', $targetUserId)->whereNull('users.deleted_at')->first([
                    'users.id', 'users.display_name', 'users.email', 'users.locale', 'users.timezone',
                    'users.account_expires_at', 'users.is_super_admin', 'users.permission_version',
                    'preferences.version as profile_version',
                ]);
            if (!$target instanceof stdClass) throw new UserNotFound('用户不存在。');
            $targetIsSuper = (int) $target->is_super_admin === 1;
            if ($targetIsSuper && !($actor['isSuperAdmin'] ?? false)) {
                throw new AuthorizationDenied('Only a super administrator may manage another super administrator.');
            }
            if ((int) $target->permission_version !== $input->expectedPermissionVersion
                || (int) $target->profile_version !== $input->expectedProfileVersion) {
                throw new UserConflict('用户资料或权限已经变化，请刷新后重试。');
            }

            $previousRoles = Db::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $targetUserId)->orderBy('roles.role_key')
                ->pluck('roles.role_key')->map(static fn (mixed $value): string => (string) $value)->all();
            $previousDirectCapabilities = Db::table('user_capabilities')->where('user_id', $targetUserId)
                ->orderBy('capability_key')->pluck('capability_key')
                ->map(static fn (mixed $value): string => (string) $value)->all();
            $roleChanged = false;
            $directCapabilitiesChanged = $previousDirectCapabilities !== $input->directCapabilities;
            $role = null;
            if ($targetIsSuper) {
                if ($input->roleKey !== null) throw new UserConflict('超级管理员角色不能通过普通编辑修改。');
                if ($input->directCapabilities !== []) throw new UserConflict('超级管理员不需要单独绑定用户权限。');
            } else {
                if ($input->roleKey === null) throw new UserConflict('普通账号必须选择一个角色。');
                $roleChanged = $previousRoles !== [$input->roleKey];
                if (($roleChanged || $directCapabilitiesChanged) && ($actor['id'] ?? null) === $targetUserId) {
                    throw new UserConflict('不能修改当前登录账户的角色。');
                }
                /** @var stdClass|null $role */
                $role = Db::table('roles')->where('role_key', $input->roleKey)->where('is_system', 1)
                    ->first(['id', 'role_key']);
                if (!$role instanceof stdClass) throw new UserConflict('所选角色已不可用。');
            }

            $profileChanged = (string) $target->display_name !== $input->displayName
                || ($target->email === null ? null : (string) $target->email) !== $input->email
                || (string) $target->locale !== $input->locale
                || (string) $target->timezone !== $input->timezone
                || ($target->account_expires_at === null ? null : (string) $target->account_expires_at)
                    !== $input->accountExpiresAt;
            if (!$profileChanged && !$roleChanged && !$directCapabilitiesChanged) return;

            if ($roleChanged && $role instanceof stdClass) {
                Db::table('user_roles')->where('user_id', $targetUserId)->delete();
                Db::table('user_roles')->insert([
                    'user_id' => $targetUserId, 'role_id' => (string) $role->id,
                    'assigned_by' => (string) $actor['id'], 'assigned_at' => $now,
                ]);
            }
            if ($directCapabilitiesChanged) {
                Db::table('user_capabilities')->where('user_id', $targetUserId)->delete();
                foreach ($input->directCapabilities as $capability) {
                    Db::table('user_capabilities')->insert([
                        'user_id' => $targetUserId,
                        'capability_key' => $capability,
                        'assigned_by' => (string) $actor['id'],
                        'assigned_at' => $now,
                    ]);
                }
            }
            $updated = Db::table('users')->where('id', $targetUserId)
                ->where('permission_version', $input->expectedPermissionVersion)->update([
                    'display_name' => $input->displayName, 'email' => $input->email,
                    'locale' => $input->locale, 'timezone' => $input->timezone,
                    'account_expires_at' => $input->accountExpiresAt,
                    'permission_version' => Db::raw('permission_version + 1'), 'updated_at' => $now,
                ]);
            if ($updated !== 1) throw new UserConflict('用户资料或权限已经变化，请刷新后重试。');
            $preferenceUpdated = Db::table('user_preferences')->where('user_id', $targetUserId)
                ->where('version', $input->expectedProfileVersion)->update([
                'locale' => $input->locale, 'timezone' => $input->timezone,
                'version' => Db::raw('version + 1'), 'updated_at' => $now,
            ]);
            if ($preferenceUpdated !== 1) throw new UserConflict('用户偏好资料不存在。');

            $this->auditLogger->record((string) $actor['id'], 'user.profile.update', 'user', $targetUserId,
                'success', $requestId, [
                    'displayNameChanged' => (string) $target->display_name !== $input->displayName,
                    'emailChanged' => ($target->email === null ? null : (string) $target->email) !== $input->email,
                    'localeChanged' => (string) $target->locale !== $input->locale,
                    'timezoneChanged' => (string) $target->timezone !== $input->timezone,
                    'expiryChanged' => ($target->account_expires_at === null ? null
                        : (string) $target->account_expires_at) !== $input->accountExpiresAt,
                    'roleChanged' => $roleChanged,
                    'directCapabilitiesChanged' => $directCapabilitiesChanged,
                    'directCapabilities' => $input->directCapabilities,
                ]);
            if ($roleChanged && $input->roleKey !== null) {
                $this->notifications->publishPermissionChange(
                    $targetUserId, 'role', $input->roleKey,
                    $requestId . ':role:' . $targetUserId, $now,
                );
            }
            if ($directCapabilitiesChanged) {
                $this->notifications->publishPermissionChange(
                    $targetUserId,
                    'direct_capabilities',
                    implode(',', $input->directCapabilities),
                    $requestId . ':direct_capabilities:' . $targetUserId,
                    $now,
                );
            }
        });

        return $this->findUser($targetUserId);
    }

    /**
     * 返回一个账号的脱敏管理投影；非超级管理员不能借详情端点查看超级管理员。
     *
     * 详情只补充有界的账号运维事实：Web 登录会话时间、活动播放设备数量、个人连接、账号变更和安全
     * 事件。密码、Subsonic 密文、令牌摘要、Session 标识/摘要、设备指纹、歌曲和审计 metadata 永不
     * 进入投影；播放曲目与历史仍必须走独立 view_play_privacy 能力端点。
     */
    public function detailUser(string $userId, array $actor): array
    {
        /** @var stdClass|null $target */
        $target = Db::table('users')->where('id', $userId)->whereNull('deleted_at')
            ->first(['is_super_admin']);
        if (!$target instanceof stdClass) throw new UserNotFound('用户不存在。');
        if ((int) $target->is_super_admin === 1 && !($actor['isSuperAdmin'] ?? false)) {
            throw new AuthorizationDenied('Only super administrators may view a super administrator.');
        }
        $detail = $this->findUser($userId);

        // 各分区使用独立、固定上限查询，避免一个长期使用账号把管理响应扩张成无界审计导出。
        $detail['loginSessions'] = $this->sessionSummaries($userId);
        $detail['players'] = $this->playerSummaries($userId);
        $detail['externalConnections'] = $this->externalConnectionSummaries($userId);
        $detail['accountChanges'] = $this->accountChangeSummaries($userId);
        $detail['securityEvents'] = $this->securityEventSummaries($userId);
        $detail['assignableRoles'] = $this->assignableRoles();

        return $detail;
    }

    /** @return list<array{key:string,name:string}> 返回允许管理端替换的固定内置角色，不暴露数据库角色 ID。 */
    private function assignableRoles(): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('roles')->where('is_system', 1)
            ->whereIn('role_key', ['administrator', 'listener'])
            ->orderBy('role_key')->get(['role_key', 'name'])->all();
        return array_map(static fn (stdClass $role): array => [
            'key' => (string) $role->role_key,
            'name' => (string) $role->name,
        ], $rows);
    }

    /**
     * @return list<array{status:string,createdAt:string,lastSeenAt:string,expiresAt:string,revokedAt:?string}>
     * 返回最近 Web 登录会话的时间和状态，不返回主键、Cookie 摘要、UA 摘要或撤销内部原因。
     */
    private function sessionSummaries(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('auth_sessions')->where('user_id', $userId)
            ->orderByDesc('created_at')->limit(20)
            ->get(['created_at', 'last_seen_at', 'expires_at', 'revoked_at'])->all();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return array_map(static function (stdClass $row) use ($now): array {
            $revokedAt = $row->revoked_at === null ? null : (string) $row->revoked_at;
            $expiresAt = (string) $row->expires_at;
            return [
                'status' => $revokedAt !== null ? 'revoked' : ($expiresAt <= $now ? 'expired' : 'active'),
                'createdAt' => (string) $row->created_at,
                'lastSeenAt' => (string) $row->last_seen_at,
                'expiresAt' => $expiresAt,
                'revokedAt' => $revokedAt,
            ];
        }, $rows);
    }

    /**
     * @return list<array{position:int,acquiredAt:string,heartbeatAt:string,expiresAt:string}>
     * 播放器只来自未过期租约；位置编号按本次响应稳定排序，不暴露 player_id 或 song_id。
     */
    private function playerSummaries(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('playback_leases')->where('user_id', $userId)
            ->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->orderByDesc('heartbeat_at')->orderBy('id')->limit(32)
            ->get(['acquired_at', 'heartbeat_at', 'expires_at'])->all();

        return array_map(static fn (stdClass $row, int $index): array => [
            'position' => $index + 1,
            'acquiredAt' => (string) $row->acquired_at,
            'heartbeatAt' => (string) $row->heartbeat_at,
            'expiresAt' => (string) $row->expires_at,
        ], $rows, array_keys($rows));
    }

    /**
     * @return array{subsonic:array{configured:bool},personalTokens:list<array<string,mixed>>}
     * 个人令牌只返回名称、范围和时间事实；外部播放记录连接已退役，管理员详情不再读取第三方凭据或状态。
     */
    private function externalConnectionSummaries(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('personal_access_tokens')->where('user_id', $userId)
            ->orderByDesc('created_at')->limit(50)
            ->get(['id', 'name', 'scopes_json', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'])->all();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $tokens = array_map(static function (stdClass $row) use ($now): array {
            $decoded = json_decode((string) $row->scopes_json, true);
            $scopes = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
            $revokedAt = $row->revoked_at === null ? null : (string) $row->revoked_at;
            $expiresAt = $row->expires_at === null ? null : (string) $row->expires_at;
            return [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'scopes' => $scopes,
                'status' => $revokedAt !== null ? 'revoked' : ($expiresAt !== null && $expiresAt <= $now ? 'expired' : 'active'),
                'expiresAt' => $expiresAt,
                'lastUsedAt' => $row->last_used_at === null ? null : (string) $row->last_used_at,
                'revokedAt' => $revokedAt,
                'createdAt' => (string) $row->created_at,
            ];
        }, $rows);

        // exists 只判断兼容凭据是否就绪，不把可解密密文装载到 PHP 变量或响应序列化路径。
        $subsonicConfigured = Db::table('users')->where('id', $userId)
            ->whereNotNull('subsonic_secret_ciphertext')->exists();
        return [
            'subsonic' => ['configured' => $subsonicConfigured],
            'personalTokens' => $tokens,
        ];
    }

    /** @return list<array{action:string,result:string,createdAt:string}> */
    private function accountChangeSummaries(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('audit_logs')->where('object_type', 'user')->where('object_id', $userId)
            ->where('action', 'like', 'user.%')->orderByDesc('created_at')->limit(20)
            ->get(['action', 'result', 'created_at'])->all();
        return array_map(static fn (stdClass $row): array => [
            'action' => (string) $row->action,
            'result' => (string) $row->result,
            'createdAt' => (string) $row->created_at,
        ], $rows);
    }

    /**
     * @return list<array{action:string,result:string,createdAt:string}>
     * 只展示目标账号自身可归属的认证/令牌事件，过滤 request_id、对象 ID 和完整审计 metadata。
     */
    private function securityEventSummaries(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('audit_logs')->where('actor_user_id', $userId)
            ->whereIn('action', ['auth.login', 'auth.logout', 'personal_token.create', 'personal_token.revoke'])
            ->orderByDesc('created_at')->limit(20)->get(['action', 'result', 'created_at'])->all();
        return array_map(static fn (stdClass $row): array => [
            'action' => (string) $row->action,
            'result' => (string) $row->result,
            'createdAt' => (string) $row->created_at,
        ], $rows);
    }

    /** @return array<string, mixed> */
    private function findUser(string $userId): array
    {
        /** @var stdClass|null $row */
        $row = Db::table('users')->join('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
            ->where('users.id', $userId)->whereNull('users.deleted_at')->first([
                'users.id', 'users.username', 'users.display_name', 'users.email', 'users.status',
                'users.is_super_admin', 'users.locale', 'users.timezone', 'users.permission_version',
                'users.account_expires_at', 'users.last_login_at', 'users.created_at', 'users.updated_at',
                'preferences.version as profile_version',
            ]);
        if (!$row instanceof stdClass) {
            throw new UserNotFound('用户不存在。');
        }

        $roles = $this->rolesByUser([$userId])[$userId] ?? [];
        $sessions = $this->activeLoginSessionsByUser([$userId])[$userId] ?? 0;
        $directCapabilities = $this->directCapabilitiesByUser([$userId])[$userId] ?? [];

        $capabilities = $this->capabilityVocabulary();
        $libraries = $this->libraryVocabulary();
        $isSuper = (int) $row->is_super_admin === 1;
        return $this->mapUser(
            $row,
            $roles,
            $sessions,
            $isSuper ? array_column($capabilities, 'key') : ($this->capabilitiesByUser([$userId])[$userId] ?? []),
            $isSuper ? [] : $directCapabilities,
            $isSuper
                ? array_map(static fn (array $library): array => $library + ['accessLevel' => 'manage'], $libraries)
                : ($this->librariesByUser([$userId])[$userId] ?? []),
        );
    }

    /**
     * @param list<string> $userIds
     * @return array<string, list<array{key: string, name: string}>>
     */
    private function rolesByUser(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $userIds)
            ->orderBy('roles.role_key')
            ->get(['user_roles.user_id', 'roles.role_key', 'roles.name'])
            ->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->user_id][] = [
                'key' => (string) $row->role_key,
                'name' => (string) $row->name,
            ];
        }

        return $result;
    }

    /** @param list<string> $userIds @return array<string, int> */
    private function activeLoginSessionsByUser(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('auth_sessions')
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->groupBy('user_id')
            ->get(['user_id', Db::raw('COUNT(*) AS session_count')])
            ->all();
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->user_id] = (int) $row->session_count;
        }

        return $result;
    }

    /**
     * @param list<array{key: string, name: string}> $roles
     * @return array<string, mixed>
     */
    private function mapUser(
        stdClass $row,
        array $roles,
        int $activeLoginSessions,
        array $capabilities,
        array $directCapabilities,
        array $libraries,
    ): array
    {
        return [
            'id' => (string) $row->id,
            'username' => (string) $row->username,
            'displayName' => (string) $row->display_name,
            'email' => $row->email === null ? null : (string) $row->email,
            'status' => (string) $row->status,
            'isSuperAdmin' => (int) $row->is_super_admin === 1,
            'roles' => $roles,
            'capabilities' => $capabilities,
            'directCapabilities' => $directCapabilities,
            'libraries' => $libraries,
            'locale' => (string) $row->locale,
            'timezone' => (string) $row->timezone,
            'permissionVersion' => (int) $row->permission_version,
            'profileVersion' => (int) $row->profile_version,
            'accountExpiresAt' => $row->account_expires_at === null ? null : (string) $row->account_expires_at,
            'lastLoginAt' => $row->last_login_at === null ? null : (string) $row->last_login_at,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
            'activeLoginSessions' => $activeLoginSessions,
        ];
    }

    /** @return list<array{key:string,description:string}> 返回稳定能力词汇供筛选，不返回角色内部 ID。 */
    private function capabilityVocabulary(): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('capabilities')->orderBy('capability_key')
            ->get(['capability_key', 'description'])->all();
        return array_map(static fn (stdClass $row): array => [
            'key' => (string) $row->capability_key,
            'description' => (string) $row->description,
        ], $rows);
    }

    /** @return list<array{id:string,name:string}> 仅返回逻辑库标识与名称，绝不返回物理目录。 */
    private function libraryVocabulary(): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('music_libraries')->where('status', 'active')->orderBy('name')
            ->get(['id', 'name'])->all();
        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
        ], $rows);
    }

    /** @param list<string> $userIds @return array<string,list<string>> */
    private function capabilitiesByUser(array $userIds): array
    {
        if ($userIds === []) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('user_roles')->join('role_capabilities', 'role_capabilities.role_id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $userIds)->distinct()->orderBy('role_capabilities.capability_key')
            ->get(['user_roles.user_id', 'role_capabilities.capability_key'])->all();
        $result = [];
        foreach ($rows as $row) $result[(string) $row->user_id][] = (string) $row->capability_key;
        foreach ($this->directCapabilitiesByUser($userIds) as $userId => $capabilities) {
            $result[$userId] = array_values(array_unique([
                ...($result[$userId] ?? []),
                ...$capabilities,
            ]));
            sort($result[$userId]);
        }
        return $result;
    }

    /** @param list<string> $userIds @return array<string,list<string>> 返回用户明确绑定而非角色继承的能力。 */
    private function directCapabilitiesByUser(array $userIds): array
    {
        if ($userIds === []) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('user_capabilities')->whereIn('user_id', $userIds)
            ->orderBy('capability_key')->get(['user_id', 'capability_key'])->all();
        $result = [];
        foreach ($rows as $row) $result[(string) $row->user_id][] = (string) $row->capability_key;
        return $result;
    }

    /** @param list<string> $userIds @return array<string,list<array{id:string,name:string,accessLevel:string}>> */
    private function librariesByUser(array $userIds): array
    {
        if ($userIds === []) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('library_user_grants as grants')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'grants.library_id')
            ->whereIn('grants.user_id', $userIds)->where('libraries.status', 'active')->orderBy('libraries.name')
            ->get(['grants.user_id', 'libraries.id', 'libraries.name', 'grants.access_level'])->all();
        $result = [];
        foreach ($rows as $row) $result[(string) $row->user_id][] = [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'accessLevel' => (string) $row->access_level,
        ];
        return $result;
    }

    /** 只接受短协议键，空字符串视为未筛选。 */
    private function optionalToken(mixed $value, int $maximum): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || strlen($value) > $maximum || preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new UserConflict('用户筛选条件无效。');
        }
        return $value;
    }

    /** 空值表示不限库；非空值必须是规范 ULID。 */
    private function optionalUlid(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new UserConflict('音乐库筛选条件无效。');
        }
        return $value;
    }

    /** 把日历日期扩展成 UTC 闭区间边界，非法日期失败关闭。 */
    private function optionalDate(mixed $value, bool $endOfDay): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new UserConflict('最近登录日期筛选无效。');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new UserConflict('最近登录日期筛选无效。');
        return $date->format('Y-m-d') . ($endOfDay ? 'T23:59:59Z' : 'T00:00:00Z');
    }
}
