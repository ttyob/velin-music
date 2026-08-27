<?php

declare(strict_types=1);

namespace app\application\Account;

use app\application\Subsonic\SubsonicCredentialCipher;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理当前 Session 账号自己的资料、密码和 Web 登录会话。
 *
 * 所有查询都以可信 actor ID 为首要条件，URL 或请求体不能指定另一个用户。服务不返回密码哈希、
 * Session/Cookie 摘要或 User-Agent 指纹；资料与安全写入均在同一短 SQLite 事务内记录审计。Argon2
 * 计算和密码验证刻意放在事务外，避免昂贵 CPU 工作长期持有 SQLite 写锁。
 */
final class SelfAccountService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly SubsonicCredentialCipher $subsonicCipher = new SubsonicCredentialCipher(),
    ) {
    }

    /**
     * 用个人偏好版本锁原子更新显示名、邮箱和时区。
     *
     * `users.timezone` 与 `user_preferences.timezone` 同步写入，以兼容尚未迁移的客户端投影；偏好行版本
     * 是唯一并发令牌。任一行不存在、账号失效或版本变化都会整体回滚，审计只记录字段变化标志和时区，
     * 不记录显示名或邮箱正文。
     *
     * @param array<string, mixed> $actor 已复验的当前 Session 投影
     * @return array{displayName: string, email: ?string, timezone: string, version: int, updatedAt: string}
     */
    public function updateProfile(
        array $actor,
        string $displayName,
        ?string $email,
        string $timezone,
        int $expectedVersion,
        string $requestId,
    ): array {
        $validated = (new SelfAccountValidator())->profile([
            'displayName' => $displayName,
            'email' => $email,
            'timezone' => $timezone,
            'expectedVersion' => $expectedVersion,
        ]);
        $displayName = $validated['displayName'];
        $email = $validated['email'];
        $timezone = $validated['timezone'];
        $userId = $this->actorId($actor);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $current */
            $current = Db::table('users')->join('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
                ->where('users.id', $userId)->where('users.status', 'active')->whereNull('users.deleted_at')
                ->first(['users.display_name', 'users.email', 'preferences.version']);
            if (!$current instanceof stdClass || (int) $current->version !== $expectedVersion) {
                throw new SelfAccountConflict('个人资料已变化，请重新加载后再试。');
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            $userChanged = Db::table('users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')
                ->update(['display_name' => $displayName, 'email' => $email, 'timezone' => $timezone, 'updated_at' => $now]);
            $preferenceChanged = Db::table('user_preferences')->where('user_id', $userId)->where('version', $expectedVersion)
                ->update(['timezone' => $timezone, 'version' => $nextVersion, 'updated_at' => $now]);
            if ($userChanged !== 1 || $preferenceChanged !== 1) {
                throw new SelfAccountConflict('个人资料已变化，请重新加载后再试。');
            }
            $this->audit->record($userId, 'user.profile.update', 'user', $userId, 'success', $requestId, [
                'displayNameChanged' => (string) $current->display_name !== $displayName,
                'emailChanged' => ($current->email === null ? null : (string) $current->email) !== $email,
                'timezone' => $timezone,
                'version' => $nextVersion,
            ]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return [
            'displayName' => $displayName,
            'email' => $email,
            'timezone' => $timezone,
            'version' => $nextVersion,
            'updatedAt' => $now,
        ];
    }

    /**
     * 复验旧密码后更换 Argon2id 哈希，并撤销当前登录之外的全部 Web 登录会话。
     *
     * 初次读取和 Argon2 工作在事务外；写事务会再次比较原哈希，防止并发改密后用陈旧校验覆盖新密码。
     * 成功时同步更新 Subsonic 兼容密钥，因为该密钥由新明文用途分离加密；当前会话以单向摘要保留，
     * 其他活动会话被标记为 `password_changed`。失败不会修改密码、会话或兼容密钥。
     *
     * @param array<string, mixed> $actor 已复验的当前 Session 投影
     * @return array{revokedSessions: int, changedAt: string}
     */
    public function changePassword(
        array $actor,
        string $currentPassword,
        string $newPassword,
        string $currentSessionHash,
        string $requestId,
    ): array {
        (new SelfAccountValidator())->password([
            'currentPassword' => $currentPassword,
            'newPassword' => $newPassword,
        ]);
        $userId = $this->actorId($actor);
        if (preg_match('/^[a-f0-9]{64}$/', $currentSessionHash) !== 1) {
            throw new SelfAccountInvalid('Current session is invalid.');
        }
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')
            ->first(['password_hash']);
        $originalHash = $user instanceof stdClass ? (string) $user->password_hash : '';
        if ($originalHash === '' || !password_verify($currentPassword, $originalHash)) {
            $this->audit->record($userId, 'user.password.update', 'user', $userId, 'failure', $requestId, [
                'reason' => 'current_password_invalid',
            ]);
            throw new SelfAccountInvalid('Current password is invalid.');
        }
        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        if (!is_string($newHash)) {
            throw new SelfAccountInvalid('Password hashing failed.');
        }
        $subsonicSecret = $this->subsonicCipher->encrypt($newPassword);

        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $locked */
            $locked = Db::table('users')->where('id', $userId)->where('status', 'active')->whereNull('deleted_at')
                ->first(['password_hash']);
            if (!$locked instanceof stdClass || !hash_equals($originalHash, (string) $locked->password_hash)) {
                throw new SelfAccountConflict('密码已在其他会话中变化，请重新登录后再试。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('users')->where('id', $userId)->where('password_hash', $originalHash)->update([
                'password_hash' => $newHash,
                'subsonic_secret_ciphertext' => $subsonicSecret,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new SelfAccountConflict('密码已在其他会话中变化，请重新登录后再试。');
            }
            $revokedSessions = Db::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')
                ->where('session_hash', '<>', $currentSessionHash)->update([
                    'revoked_at' => $now,
                    'revoked_reason' => 'password_changed',
                ]);
            $this->audit->record($userId, 'user.password.update', 'user', $userId, 'success', $requestId, [
                'revokedSessions' => $revokedSessions,
            ]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return ['revokedSessions' => $revokedSessions, 'changedAt' => $now];
    }

    /**
     * 返回当前账号仍有效的 Web 登录会话，按最近活动倒序排列。
     *
     * 当前会话只通过请求进程计算的 SHA-256 摘要做相等判断；响应使用公开 ULID 命令标识，不返回
     * session_hash、Cookie、UA 摘要或 IP。过期和已撤销记录不会出现在自助列表中。
     *
     * @param array<string, mixed> $actor 已复验的当前 Session 投影
     * 单账号活动 Web 登录由认证服务限制为 20 个；这里使用相同硬上限，避免接口把播放会话或其他批次
     * 误混入结果。排序、当前标记和所有权均只基于 auth_sessions，不读取 playback_sessions。
     *
     * @return list<array{id: string, current: bool, createdAt: string, lastSeenAt: string, expiresAt: string}>
     */
    public function sessions(array $actor, string $currentSessionHash): array
    {
        $userId = $this->actorId($actor);
        if (preg_match('/^[a-f0-9]{64}$/', $currentSessionHash) !== 1) {
            throw new SelfAccountInvalid('Current session is invalid.');
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')
            ->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))->orderByDesc('last_seen_at')
            ->limit(20)->get(['id', 'session_hash', 'created_at', 'last_seen_at', 'expires_at'])->all();

        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'current' => hash_equals($currentSessionHash, (string) $row->session_hash),
            'createdAt' => (string) $row->created_at,
            'lastSeenAt' => (string) $row->last_seen_at,
            'expiresAt' => (string) $row->expires_at,
        ], $rows);
    }

    /**
     * 撤销一个属于当前账号的活动 Web 登录会话并返回它是否为当前登录。
     *
     * 目标不存在、属于其他账号、已撤销或已过期统一抛出 SelfSessionNotFound，避免利用状态差异枚举。
     * 数据库提交后 Controller 才清理当前文件 Session；即使客户端断线，数据库撤销仍是权威事实。
     *
     * @param array<string, mixed> $actor 已复验的当前 Session 投影
     * @return array{revokedCurrent: bool, revokedAt: string}
     */
    public function revokeSession(
        array $actor,
        string $sessionId,
        string $currentSessionHash,
        string $requestId,
    ): array {
        $userId = $this->actorId($actor);
        if (preg_match('/^[a-f0-9]{64}$/', $currentSessionHash) !== 1) {
            throw new SelfAccountInvalid('Current session is invalid.');
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $sessionId) !== 1) {
            throw new SelfSessionNotFound('Session not found.');
        }
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $row */
            $row = Db::table('auth_sessions')->where('id', $sessionId)->where('user_id', $userId)
                ->whereNull('revoked_at')->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
                ->first(['session_hash']);
            if (!$row instanceof stdClass) {
                throw new SelfSessionNotFound('Session not found.');
            }
            $revokedAt = gmdate('Y-m-d\TH:i:s\Z');
            $revokedCurrent = hash_equals($currentSessionHash, (string) $row->session_hash);
            $changed = Db::table('auth_sessions')->where('id', $sessionId)->where('user_id', $userId)
                ->whereNull('revoked_at')->update(['revoked_at' => $revokedAt, 'revoked_reason' => 'user_revoked']);
            if ($changed !== 1) {
                throw new SelfSessionNotFound('Session not found.');
            }
            $this->audit->record($userId, 'user.session.revoke', 'session', $sessionId, 'success', $requestId, [
                'current' => $revokedCurrent,
            ]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return compact('revokedCurrent', 'revokedAt');
    }

    /** 从可信 Session 投影提取 ULID，防止服务被 Controller 之外的调用者误用于跨账号写入。 */
    private function actorId(array $actor): string
    {
        $userId = $actor['id'] ?? null;
        if (!is_string($userId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new SelfAccountInvalid('Authenticated user is invalid.');
        }

        return $userId;
    }
}
