<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Library\OneDriveAuthorizationFailed;
use app\application\Library\OneDriveDeviceAuthorizationService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露管理员 OneDrive 设备码授权的最小 HTTP 边界。
 *
 * 两个命令都由显式路由附加 CSRF，并在这里要求全局 `manage_library`；服务层继续绑定 actor、限频和执行
 * 一次性消费。响应只包含登录所需用户代码、固定 Microsoft 验证地址、到期时间及状态，不返回任何 OAuth
 * token、device code、账号 UPN 或 drive ID。Controller 不记录请求体或上游异常消息。
 */
final class OneDriveAuthorizationController
{
    /** 启动新的 Device Code Flow；请求只接受 tenantId 与 clientId 两个 UUID 字段。 */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['tenantId', 'clientId', 'proxyProfileId']) !== []
                || count($payload) !== 3 || !is_string($payload['tenantId']) || !is_string($payload['clientId'])
                || (!is_null($payload['proxyProfileId']) && !is_string($payload['proxyProfileId']))) {
                throw new OneDriveAuthorizationFailed(
                    'ONEDRIVE_AUTHORIZATION_INPUT_INVALID', '租户 ID 或客户端 ID 无效。', 422,
                );
            }
            $authorization = (new OneDriveDeviceAuthorizationService())->start(
                $payload['tenantId'], $payload['clientId'], $actor, $payload['proxyProfileId'],
            );
            return JsonResponseFactory::create([
                'data' => ['authorization' => $authorization],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'OneDrive authorization start failed.');
        }
    }

    /** 推进一次授权轮询；空请求体避免浏览器提交租户、token 或其他可伪造状态。 */
    public function poll(Request $request, string $authorizationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (is_array($payload) && $payload !== []) {
                throw new OneDriveAuthorizationFailed(
                    'ONEDRIVE_AUTHORIZATION_INPUT_INVALID', '授权轮询请求不能包含额外字段。', 422,
                );
            }
            $authorization = (new OneDriveDeviceAuthorizationService())->poll($authorizationId, $actor);
            return JsonResponseFactory::create([
                'data' => ['authorization' => $authorization],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'OneDrive authorization poll failed.');
        }
    }

    /** 将已脱敏领域错误映射为稳定 HTTP；未知异常只记录类别与 request ID。 */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行此操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof OneDriveAuthorizationFailed) {
            return JsonResponseFactory::error(
                $throwable->errorCode, $throwable->getMessage(), $throwable->httpStatus, $requestId,
            );
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error(
            'ONEDRIVE_AUTHORIZATION_UNAVAILABLE', 'OneDrive 授权服务暂时不可用。', 503, $requestId,
        );
    }
}
