<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\CredentialService;
use app\application\Auth\SessionService;
use app\application\Auth\TrustedProxyAuthDenied;
use app\application\Auth\TrustedProxyAuthService;
use app\application\Auth\TrustedProxyAuthUnavailable;
use app\http\CsrfTokenManager;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Handles Cookie-based browser authentication for the Web frontend.
 *
 * This controller never accepts Bearer or Subsonic credentials; those future authentication
 * surfaces require separate adapters and revocation semantics. All response failures are stable
 * and intentionally omit account existence, status, password, Session ID, and CSRF token values.
 */
final class AuthController
{
    /**
     * 返回可信代理认证是否由部署显式启用。
     *
     * 响应不包含身份头名、可信 CIDR 或代理地址。配置声明启用但边界非法时返回 503，避免登录页展示
     * 一个实际上会信任错误来源的入口；关闭是正常 200 状态。
     */
    public function proxyStatus(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            return JsonResponseFactory::create([
                'data' => (new TrustedProxyAuthService(\app\application\Auth\TrustedProxyAuthConfig::fromEnvironment()))->status(),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (TrustedProxyAuthUnavailable) {
            return JsonResponseFactory::error('PROXY_AUTH_UNAVAILABLE', '外部认证配置不可用。', 503, $requestId);
        }
    }

    /**
     * 使用可信直连代理注入的身份登录现有本地账号。
     *
     * 路由先通过 CSRF；服务再验证直连 IP/CIDR、固定身份头和账号状态。成功后与本地登录一样轮换
     * Session ID 和 CSRF token，并建立可撤销数据库 Session。所有身份拒绝统一返回 401，不泄露账号
     * 是否存在；功能关闭返回 404，便于未启用部署保持最小攻击面。
     */
    public function proxyLogin(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $service = TrustedProxyAuthService::fromEnvironment();
            if (!$service->status()['enabled']) {
                return JsonResponseFactory::error('AUTH_METHOD_NOT_AVAILABLE', '外部认证未启用。', 404, $requestId);
            }
            $userId = $service->authenticate($request, $requestId);
            $sessions = new SessionService();
            $csrfToken = $sessions->establish($request, $userId, $requestId, 'trusted_proxy');
            return JsonResponseFactory::create([
                'data' => ['user' => $sessions->currentUser($request), 'csrfToken' => $csrfToken],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (TrustedProxyAuthDenied) {
            return JsonResponseFactory::error('PROXY_AUTHENTICATION_FAILED', '外部认证失败。', 401, $requestId);
        } catch (TrustedProxyAuthUnavailable) {
            return JsonResponseFactory::error('PROXY_AUTH_UNAVAILABLE', '外部认证配置不可用。', 503, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Trusted proxy authentication failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('AUTHENTICATION_UNAVAILABLE', '暂时无法登录，请稍后重试。', 503, $requestId);
        }
    }

    /** Returns or creates the anonymous/authenticated Session's synchronizer token. */
    public function csrf(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $token = (new CsrfTokenManager())->getOrCreate($request->session());

        return JsonResponseFactory::create([
            'data' => ['csrfToken' => $token],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /**
     * Verifies local credentials, rotates the Session ID, and returns the current user snapshot.
     *
     * The route requires CSRF. Invalid, disabled, deleted, and unknown users all receive the same
     * 401 contract. Rate limiting returns 429 with only a retry duration, never an identity hint.
     */
    public function login(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $payload = $request->post();
        $username = is_array($payload) && is_string($payload['username'] ?? null)
            ? $payload['username']
            : '';
        $password = is_array($payload) && is_string($payload['password'] ?? null)
            ? $payload['password']
            : '';

        // Bound attacker-controlled input before Argon2 verification while retaining one public error.
        if (strlen($username) > 254 || strlen($password) > 1024) {
            $username = '';
            $password = '';
        }

        try {
            $decision = (new CredentialService())->authenticate($username, $password, $requestId);
            if ($decision->isRateLimited()) {
                $response = JsonResponseFactory::error(
                    'LOGIN_RATE_LIMITED',
                    '登录尝试过多，请稍后重试。',
                    429,
                    $requestId,
                    ['retryAfterSeconds' => $decision->retryAfterSeconds],
                );

                return $response->withHeader('Retry-After', (string) $decision->retryAfterSeconds);
            }
            if (!$decision->authenticated || $decision->userId === null) {
                return JsonResponseFactory::error(
                    'INVALID_CREDENTIALS',
                    '用户名或密码错误。',
                    401,
                    $requestId,
                );
            }

            $sessions = new SessionService();
            $csrfToken = $sessions->establish($request, $decision->userId, $requestId);
            $user = $sessions->currentUser($request);

            return JsonResponseFactory::create([
                'data' => ['user' => $user, 'csrfToken' => $csrfToken],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Authentication request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'AUTHENTICATION_UNAVAILABLE',
                '暂时无法登录，请稍后重试。',
                503,
                $requestId,
            );
        }
    }

    /**
     * Revokes the current session and replaces its Cookie with a fresh anonymous Session ID.
     *
     * Logout is idempotent and deliberately returns success for an already anonymous request.
     */
    public function logout(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            (new SessionService())->logout($request, $requestId);

            return JsonResponseFactory::create([
                'data' => ['loggedOut' => true],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Logout request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'LOGOUT_FAILED',
                '退出登录失败，请稍后重试。',
                500,
                $requestId,
            );
        }
    }
}
