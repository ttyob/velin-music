<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Library\GoogleDriveAuthorizationFailed;
use app\application\Library\GoogleDriveAuthorizationService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露 Google Drive Authorization Code + PKCE 的最小 HTTP 边界。
 *
 * start/status 要求 `manage_library`，写命令由路由附加 CSRF；公开 callback 依靠 256 位随机 state 摘要、
 * 到期时间和一次性条件更新防伪，不读取 session、Host 或代理头。JSON 响应不含 client secret、verifier、
 * code、token 或 Google 身份；回调只返回固定 HTML，不嵌入上游参数和错误正文。
 */
final class GoogleDriveAuthorizationController
{
    /** 严格接收 OAuth Web 客户端 ID/secret 并返回一次性授权 URL。 */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (!is_array($payload) || count($payload) !== 3
                || array_diff(array_keys($payload), ['clientId', 'clientSecret', 'proxyProfileId']) !== []
                || !is_string($payload['clientId'] ?? null) || !is_string($payload['clientSecret'] ?? null)
                || (!is_null($payload['proxyProfileId'] ?? null) && !is_string($payload['proxyProfileId']))) {
                throw new GoogleDriveAuthorizationFailed(
                    'GOOGLE_DRIVE_AUTHORIZATION_INPUT_INVALID', 'Google OAuth 客户端配置无效。', 422,
                );
            }
            $authorization = (new GoogleDriveAuthorizationService())->start(
                $payload['clientId'], $payload['clientSecret'], $actor, $payload['proxyProfileId'],
            );
            return JsonResponseFactory::create([
                'data' => ['authorization' => $authorization],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Google Drive authorization start failed.');
        }
    }

    /** 返回绑定当前 actor 的授权状态；空请求体且不执行外部轮询。 */
    public function status(Request $request, string $authorizationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (is_array($payload) && $payload !== []) {
                throw new GoogleDriveAuthorizationFailed(
                    'GOOGLE_DRIVE_AUTHORIZATION_INPUT_INVALID', '授权状态请求不能包含额外字段。', 422,
                );
            }
            $authorization = (new GoogleDriveAuthorizationService())->status($authorizationId, $actor);
            return JsonResponseFactory::create([
                'data' => ['authorization' => $authorization],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Google Drive authorization status failed.');
        }
    }

    /**
     * 接收 Google 重定向并完成 code 兑换。
     *
     * 无论成功还是已知失败都只显示固定中文结果，不回显 query、state、code 或 Google description；未知
     * 异常仅记录类别与 request ID。callback 不修改远端文件，刷新页面因 state 已消费而失败关闭。
     */
    public function callback(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $state = $request->get('state');
            $code = $request->get('code');
            $error = $request->get('error');
            (new GoogleDriveAuthorizationService())->complete(
                is_string($state) ? $state : '',
                is_string($code) ? $code : null,
                is_string($error) ? $error : null,
            );
            return $this->html('Google Drive 授权已完成，可以关闭此窗口。', 200);
        } catch (GoogleDriveAuthorizationFailed $failure) {
            return $this->html($failure->getMessage(), $failure->httpStatus);
        } catch (Throwable $throwable) {
            Log::error('Google Drive authorization callback failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return $this->html('Google Drive 授权暂时无法完成，请关闭窗口后重试。', 503);
        }
    }

    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行此操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof GoogleDriveAuthorizationFailed) {
            return JsonResponseFactory::error(
                $throwable->errorCode, $throwable->getMessage(), $throwable->httpStatus, $requestId,
            );
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error(
            'GOOGLE_DRIVE_AUTHORIZATION_UNAVAILABLE', 'Google Drive 授权服务暂时不可用。', 503, $requestId,
        );
    }

    /** 固定无脚本结果页，CSP 禁止加载外部资源或把敏感回调 URL发送给第三方。 */
    private function html(string $message, int $status): Response
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>Velin Music</title><body style="margin:0;background:#111;color:#eee;font:16px sans-serif;display:grid;place-items:center;min-height:100vh">'
            . '<p>' . $escaped . '</p></body></html>';
        return new Response($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], $body);
    }
}
