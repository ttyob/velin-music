<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AppAuthenticationInvalid;
use app\application\Auth\AppAuthenticationService;
use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露原生 App 独立于 Web Cookie 的 PKCE 与设备会话生命周期。
 *
 * authorize/token 不建立 Web Session、不返回 CSRF，也不接受 Cookie 作为授权依据；logout 和设备撤销只
 * 能作用于当前账号的令牌族。错误响应不区分未知账号、停用账号、错误密码、摘要或到期细节，日志仅记录
 * 请求 ID 和异常类别，绝不记录密码、授权码、verifier 或 token。
 */
final class AppAuthController
{
    /** 校验凭据并返回五分钟、单次使用且绑定 S256 challenge 的授权码。 */
    public function authorize(Request $request): Response
    {
        return $this->publicCommand($request, 'authorize');
    }

    /** 交换授权码或轮换 refresh token，成功响应强制 no-store。 */
    public function token(Request $request): Response
    {
        return $this->publicCommand($request, 'token');
    }

    /** 撤销当前 App access token 所属设备族；撤销后族内所有 access/refresh 立即失效。 */
    public function logout(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) throw new AuthenticationRequired('Authentication required.');
            $familyId = $actor['appTokenFamilyId'] ?? null;
            if (!is_string($familyId)) {
                return JsonResponseFactory::error('APP_ACCESS_TOKEN_REQUIRED', '需要 App 访问令牌。', 403, $requestId);
            }
            (new AppAuthenticationService())->revokeFamily($actor, $familyId, $requestId, 'app_logout');
            return JsonResponseFactory::create(['data' => ['loggedOut' => true], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId)->withHeader('Cache-Control', 'no-store');
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 返回当前账号最多一百个未撤销设备会话，不返回指纹、推送标识或令牌摘要。 */
    public function sessions(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) throw new AuthenticationRequired('Authentication required.');
            if (($actor['authenticationType'] ?? null) === 'personal_token') {
                return JsonResponseFactory::error('APP_SESSION_MANAGEMENT_DENIED',
                    '个人访问令牌不能管理 App 设备会话。', 403, $requestId);
            }
            return JsonResponseFactory::create(['data' => [
                'sessions' => (new AppAuthenticationService())->sessions($actor),
            ], 'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 按所有权撤销一个设备会话；跨账号、已撤销或伪造 ULID 均返回相同 404。 */
    public function revokeSession(Request $request, string $familyId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) throw new AuthenticationRequired('Authentication required.');
            if (($actor['authenticationType'] ?? null) === 'personal_token') {
                return JsonResponseFactory::error('APP_SESSION_MANAGEMENT_DENIED',
                    '个人访问令牌不能管理 App 设备会话。', 403, $requestId);
            }
            (new AppAuthenticationService())->revokeFamily($actor, $familyId, $requestId);
            return JsonResponseFactory::create(['data' => ['revoked' => true], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    private function publicCommand(Request $request, string $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $payload = $request->post();
            if (!is_array($payload)) throw new AppAuthenticationInvalid(
                'APP_AUTH_REQUEST_INVALID', 'App 认证请求无效。',
            );
            $service = new AppAuthenticationService();
            $data = $operation === 'authorize'
                ? $service->authorize($payload, $requestId)
                : $service->token($payload, $requestId);
            return JsonResponseFactory::create(['data' => $data, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId)->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AppAuthenticationInvalid) {
            $status = match ($throwable->reasonCode) {
                'INVALID_CREDENTIALS', 'APP_AUTHORIZATION_CODE_INVALID', 'APP_REFRESH_TOKEN_INVALID',
                'APP_REFRESH_TOKEN_REUSED', 'APP_TOKEN_INVALID' => 401,
                'APP_LOGIN_RATE_LIMITED' => 429,
                'APP_SESSION_NOT_FOUND' => 404,
                default => 422,
            };
            $message = $throwable->reasonCode === 'APP_LOGIN_RATE_LIMITED'
                ? '登录尝试过多，请稍后重试。' : $throwable->getMessage();
            $response = JsonResponseFactory::error($throwable->reasonCode, $message, $status, $requestId);
            if ($status === 429) $response = $response->withHeader('Retry-After', $throwable->getMessage());
            return $response->withHeader('Cache-Control', 'no-store');
        }
        Log::error('App authentication request failed.', [
            'request_id' => $requestId, 'exception_class' => $throwable::class,
        ]);
        return JsonResponseFactory::error('APP_AUTHENTICATION_UNAVAILABLE',
            'App 登录服务暂时不可用。', 503, $requestId)->withHeader('Cache-Control', 'no-store');
    }
}
