<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\http\CsrfTokenManager;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use app\application\Library\LibraryAccessResolver;
use app\application\Notification\NotificationPublisher;
use app\application\Theme\ThemeService;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Request;

/**
 * 在 Webman Session 与可撤销数据库登录会话之间建立认证边界（AUTH-007）。
 *
 * Cookie 只保存 Webman 随机 Session ID，SQLite 只保存其 SHA-256 摘要；每次授权同时复验两端、
 * 到期时间和账号状态，因此停用账号或撤销登录无需等待 Cookie 自然失效。重新登录会替换当前 Cookie
 * 对应的旧记录，单账号最多保留 20 个活动登录，防止自动化客户端反复创建 Cookie 容器导致会话无限
 * 增长。服务绝不持久化或记录原始 Cookie 与 CSRF token，也不承担播放会话或上传批次的生命周期。
 */
final class SessionService
{
    private const LIFETIME_SECONDS = 604800;
    private const LAST_SEEN_WRITE_INTERVAL_SECONDS = 300;
    private const MAX_ACTIVE_SESSIONS_PER_USER = 20;
    private const TERMINAL_SESSION_RETENTION_SECONDS = 2592000;
    private const CLEANUP_BATCH_SIZE = 100;

    public function __construct(
        private readonly CsrfTokenManager $csrf = new CsrfTokenManager(),
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly CapabilityResolver $capabilityResolver = new CapabilityResolver(),
        private readonly LibraryAccessResolver $libraryAccessResolver = new LibraryAccessResolver(),
        private readonly ThemeService $themeService = new ThemeService(),
        private readonly NotificationPublisher $notifications = new NotificationPublisher(),
    ) {
    }

    /**
     * 轮换匿名/旧登录 Session ID，并创建一条可撤销的 Web 登录会话。
     *
     * 轮换前先计算旧 Cookie 摘要，避免 `sessionRegenerateId(true)` 删除文件 Session 后失去数据库定位
     * 能力。旧记录撤销、新记录、登录审计和后台安全通知在同一短事务提交；任一步失败都会回滚数据库
     * 变化，调用方把本次登录视为失败。新记录创建后按最近活动保留最多 20 个活动登录，超出部分只撤销
     * 而不删除，便于短期安全审计；超过 30 天的终态记录每次最多清理 100 条，避免登录请求形成长事务。
     *
     * 幂等性：成功调用始终产生新的登录记录，客户端不得自动重放不确定结果；同一 Cookie 再次登录会
     * 先撤销它替代的旧记录。副作用包括轮换 CSRF、写入 Webman Session、认证表、审计和后台通知。
     *
     * @return string 供下一次状态变更请求使用的已轮换 CSRF token。
     */
    public function establish(
        Request $request,
        string $userId,
        string $requestId,
        string $authenticationMethod = 'local',
    ): string
    {
        if (!in_array($authenticationMethod, ['local', 'trusted_proxy'], true)) {
            throw new \InvalidArgumentException('Unsupported authentication method.');
        }
        $previousSessionHash = $this->sessionHash($request->sessionId());
        $sessionId = $request->sessionRegenerateId(true);
        $session = $request->session();
        $csrfToken = $this->csrf->rotate($session);
        $session->set('user_id', $userId);
        $session->set('authenticated_at', gmdate('Y-m-d\TH:i:s\Z'));

        $nowTimestamp = time();
        $now = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp);
        $sessionRecordId = (string) new Ulid();
        Db::transaction(function () use (
            $request,
            $requestId,
            $previousSessionHash,
            $sessionId,
            $sessionRecordId,
            $userId,
            $now,
            $nowTimestamp,
            $authenticationMethod,
        ): void {
            Db::table('auth_sessions')
                ->where('session_hash', $previousSessionHash)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revoked_reason' => 'session_replaced',
                ]);
            Db::table('auth_sessions')->insert([
                'id' => $sessionRecordId,
                'user_id' => $userId,
                'session_hash' => $this->sessionHash($sessionId),
                'user_agent_hash' => RequestContext::userAgentHash($request),
                'created_at' => $now,
                'last_seen_at' => $now,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp + self::LIFETIME_SECONDS),
                'revoked_at' => null,
                'revoked_reason' => null,
            ]);
            $this->enforceActiveSessionLimit($userId, $now);
            $this->cleanupTerminalSessions($nowTimestamp);
            $this->auditLogger->record(
                $userId,
                'auth.login',
                'session',
                $sessionRecordId,
                'success',
                $requestId,
                ['method' => $authenticationMethod],
            );
            $this->notifications->publishLogin($userId, $sessionRecordId, $now);
        });

        return $csrfToken;
    }

    /**
     * 撤销超过账号活动登录上限的旧记录。
     *
     * 调用者必须已经处于创建登录记录的数据库事务中。排序优先采用最近活动时间，再用创建时间和 ULID
     * 稳定决胜，因此刚建立的会话在同秒并发登录时仍能保留；更新条件再次限定未撤销且未过期，避免覆盖
     * 用户同时执行的退出或管理员撤销原因。该限制只约束 Web 登录，不影响播放设备和 App token。
     */
    private function enforceActiveSessionLimit(string $userId, string $now): void
    {
        /** @var list<string> $retainedIds */
        $retainedIds = Db::table('auth_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ACTIVE_SESSIONS_PER_USER)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if (count($retainedIds) < self::MAX_ACTIVE_SESSIONS_PER_USER) {
            return;
        }

        Db::table('auth_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->whereNotIn('id', $retainedIds)
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => 'session_limit_exceeded',
            ]);
    }

    /**
     * 有界清理已结束且超过保留期的登录会话事实。
     *
     * 只删除已撤销 30 天或到期 30 天以上的记录，最近安全历史仍由会话摘要和不可变审计提供。先固定选择
     * 最旧的 100 个 ID 再删除，避免 SQLite 在登录事务中扫描并删除无界数据；并发撤销只会让记录更早
     * 满足后续清理，不会误删仍有效会话。删除不触碰 Webman/Redis Session，过期 Cookie 仍由授权复验
     * 失败关闭。
     */
    private function cleanupTerminalSessions(int $nowTimestamp): void
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp - self::TERMINAL_SESSION_RETENTION_SECONDS);
        /** @var list<string> $expiredIds */
        $expiredIds = Db::table('auth_sessions')
            ->where(static function ($query) use ($cutoff): void {
                $query->where(static function ($revoked) use ($cutoff): void {
                    $revoked->whereNotNull('revoked_at')->where('revoked_at', '<=', $cutoff);
                })->orWhere(static function ($expired) use ($cutoff): void {
                    $expired->whereNull('revoked_at')->where('expires_at', '<=', $cutoff);
                });
            })
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::CLEANUP_BATCH_SIZE)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($expiredIds !== []) {
            Db::table('auth_sessions')->whereIn('id', $expiredIds)->delete();
        }
    }

    /**
     * Resolves the current user after rechecking revocation, expiry, and account state.
     *
     * @return array<string, mixed>|null Sanitized /me view; null for every invalid-session cause.
     */
    public function currentUser(Request $request): ?array
    {
        $userId = $request->session()->get('user_id');
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        /** @var stdClass|null $row */
        $row = Db::table('auth_sessions as sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->leftJoin('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
            ->where('sessions.session_hash', $this->sessionHash($request->sessionId()))
            ->where('sessions.user_id', $userId)
            ->whereNull('sessions.revoked_at')
            ->where('sessions.expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->where(static function ($query): void {
                $query->whereNull('users.account_expires_at')
                    ->orWhere('users.account_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
            })
            ->first([
                'sessions.id as auth_session_id',
                'sessions.last_seen_at',
                'users.id',
                'users.username',
                'users.display_name',
                'users.email',
                'users.is_super_admin',
                'users.locale as user_locale',
                'users.timezone as user_timezone',
                'users.permission_version',
                'preferences.theme_id',
                'preferences.locale as preference_locale',
                'preferences.timezone as preference_timezone',
                'preferences.reduce_motion',
                'preferences.version as preference_version',
            ]);

        if (!$row instanceof stdClass) {
            $request->session()->flush();
            return null;
        }

        $lastSeen = strtotime((string) $row->last_seen_at) ?: 0;
        if ($lastSeen <= time() - self::LAST_SEEN_WRITE_INTERVAL_SECONDS) {
            Db::table('auth_sessions')->where('id', $row->auth_session_id)->update([
                'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        $isSuperAdmin = (int) $row->is_super_admin === 1;

        $theme = $this->themeService->resolvePreference(
            $row->theme_id === null ? null : (string) $row->theme_id,
        );

        return [
            'id' => (string) $row->id,
            'username' => (string) $row->username,
            'displayName' => (string) $row->display_name,
            'email' => $row->email === null ? null : (string) $row->email,
            'isSuperAdmin' => $isSuperAdmin,
            'permissionVersion' => (int) $row->permission_version,
            'capabilities' => $this->capabilityResolver->resolve((string) $row->id, $isSuperAdmin),
            'libraries' => $this->libraryAccessResolver->resolve((string) $row->id, $isSuperAdmin),
            'preferences' => [
                'themeId' => $theme['themeId'],
                'themeFallbackFrom' => $theme['themeFallbackFrom'],
                'locale' => (string) ($row->preference_locale ?? $row->user_locale),
                'timezone' => (string) ($row->preference_timezone ?? $row->user_timezone),
                'reduceMotion' => (int) ($row->reduce_motion ?? 0) === 1,
                'version' => (int) ($row->preference_version ?? 1),
            ],
        ];
    }

    /**
     * Revokes the current database session and clears the file Session.
     *
     * Repeated logout is safe: an absent/revoked database row updates zero records and still
     * produces a fresh anonymous Session ID. The audit row never includes Cookie data.
     */
    public function logout(Request $request, string $requestId): void
    {
        $userId = $request->session()->get('user_id');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('auth_sessions')
            ->where('session_hash', $this->sessionHash($request->sessionId()))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => 'user_logout',
            ]);

        if (is_string($userId) && $userId !== '') {
            $this->auditLogger->record(
                $userId,
                'auth.logout',
                'session',
                null,
                'success',
                $requestId,
            );
        }

        $request->session()->flush();
        $request->sessionRegenerateId(true);
    }

    /** Returns a one-way lookup digest; raw Webman IDs must never cross this boundary. */
    private function sessionHash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

}
