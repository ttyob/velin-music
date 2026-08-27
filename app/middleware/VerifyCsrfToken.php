<?php

declare(strict_types=1);

namespace app\middleware;

use app\application\Auth\AuthorizationService;
use app\http\CsrfTokenManager;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Rejects state-changing Cookie-authenticated requests without a valid synchronizer token.
 *
 * The middleware is attached explicitly to current write routes. Future write endpoints must
 * opt in at route declaration and tests must fail if that boundary is omitted. A failure occurs
 * before request body mapping or database writes and returns a stable error without echoing the
 * supplied token.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    /**
     * Validates X-CSRF-Token against the server-side Session, then delegates unchanged.
     *
     * @param callable(Request): Response $handler Downstream route handler.
     */
    public function process(Request $request, callable $handler): Response
    {
        // Bearer 请求不依赖 Cookie，因此在固定 App/媒体白名单中验证令牌后跳过同步 CSRF；密码、资料、
        // 令牌签发与后台管理入口仍要求 Web Session + CSRF，防止客户端凭据扩大到账户管理权限。
        $authorization = $request->header('authorization');
        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            && $this->allowsBearerWrite($request->path())) {
            // 显式 Bearer 永不借用 Cookie 的 CSRF 状态。有效凭据交给目标 Controller 重验能力与对象范围；
            // 无效凭据在进入业务前直接收敛为 401，避免移动端把 token 到期误判为浏览器 CSRF 失效。
            if ((new AuthorizationService())->currentActor($request) === null) {
                $requestId = RequestContext::requestId();
                return JsonResponseFactory::error(
                    code: 'AUTHENTICATION_REQUIRED',
                    message: '请先登录。',
                    status: 401,
                    requestId: $requestId,
                );
            }
            return $handler($request);
        }
        $valid = (new CsrfTokenManager())->verify(
            $request->session(),
            $request->header('x-csrf-token'),
        );

        if (!$valid) {
            $requestId = RequestContext::requestId();

            return JsonResponseFactory::error(
                code: 'CSRF_TOKEN_INVALID',
                message: '请求验证已失效，请刷新后重试。',
                status: 403,
                requestId: $requestId,
            );
        }

        return $handler($request);
    }

    /**
     * 只允许已经统一使用 AuthorizationService 的固定资源前缀接受 Bearer 写入。
     *
     * 显式白名单避免后续新增一个仅依赖 Cookie 的写 Controller 后，被通用 `/api/v1/*` 判断意外跳过
     * CSRF。能力与对象范围仍由目标 Controller 重验，令牌本身不会因为命中本方法而获得权限。
     */
    private function allowsBearerWrite(string $path): bool
    {
        foreach ([
            '/api/v1/play-queues',
            '/api/v1/streams',
            '/api/v1/favorites',
            '/api/v1/playback-events',
            '/api/v1/playback-leases',
            '/api/v1/bookmarks',
            '/api/v1/radios',
            '/api/v1/playlists',
            '/api/v1/smart-playlists',
            '/api/v1/me/preferences',
            '/api/v1/me/playback-preferences',
            '/api/v1/history',
            '/api/v1/dlna',
            '/api/v1/airplay',
            '/api/v1/auth/app/logout',
            '/api/v1/me/app-sessions',
            '/api/v1/app',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
        }
        return false;
    }
}
