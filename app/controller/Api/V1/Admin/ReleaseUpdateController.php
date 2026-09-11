<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\System\ReleaseUpdateService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供官方 GitHub Release 的只读更新提示。
 *
 * 端点只接受拥有 `manage_system` 的同源后台 Session，拒绝 PAT/Bearer，且不提供安装、替换、重启或文件
 * 写入命令。网络失败只返回统一 503；异常日志不记录 URL、上游正文或连接细节，登录与其他业务不依赖
 * 本接口成功。
 */
final class ReleaseUpdateController
{
    /** 返回当前版本、最新稳定 Release、纯文本更新说明和经过白名单校验的下载链接。 */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            if (isset($actor['authenticationType'])) {
                throw new AuthorizationDenied('Release update check requires an administration session.');
            }
            return JsonResponseFactory::create([
                'data' => (new ReleaseUpdateService())->check(),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有检查系统更新的权限。', 403, $requestId);
        } catch (Throwable $throwable) {
            Log::warning('Release update check failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('RELEASE_UPDATE_UNAVAILABLE', '暂时无法检查新版本。', 503, $requestId);
        }
    }
}
