<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\System\SystemHealthMonitorService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供只读系统进程与资源监控快照。
 *
 * 接口仅接受拥有 `manage_system` 的同源后台 Session；Bearer/PAT 即使带同名能力也拒绝，避免自动化
 * 凭据批量读取 PID 与资源侧信道。GET 不要求 CSRF，不接受路径、服务名或采样参数，也不会执行进程
 * 控制、文件清理或故障修复；未知异常仅记录类别和 requestId，响应不包含命令行与物理路径。
 */
final class SystemHealthController
{
    /** 返回 Velin 白名单进程、依赖、磁盘和容器资源的点时快照。 */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            if (isset($actor['authenticationType'])) {
                throw new AuthorizationDenied('System health requires an administration session.');
            }
            return JsonResponseFactory::create([
                'data' => (new SystemHealthMonitorService())->snapshot(),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有查看系统服务监控的权限。', 403, $requestId);
        } catch (Throwable $throwable) {
            Log::error('System health monitor failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error(
                'SYSTEM_HEALTH_UNAVAILABLE',
                '系统服务监控暂时不可用。',
                503,
                $requestId,
            );
        }
    }
}
