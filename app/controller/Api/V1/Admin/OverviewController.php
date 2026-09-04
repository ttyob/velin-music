<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Admin\AdminOverviewService;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\SessionService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 对同源后台 Session 暴露一份按 capability 裁剪的只读健康快照。 */
final class OverviewController
{
    /**
     * Session 无效返回 401，没有任何后台能力返回 403；其余情况返回部分快照，单探针失败明确标为 unknown。
     * GET 不要求 CSRF 且不排队扫描、不修改文件。自动备份汇总仅对 manage_system 出现，并由领域服务保证
     * 不包含路径、文件名、摘要、下载、创建或恢复能力；控制器不得根据请求参数选择备份目录。
     */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            $snapshot = (new AdminOverviewService())->snapshot($actor);

            return JsonResponseFactory::create([
                'data' => $snapshot,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有访问管理概览的权限。', 403, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Administration overview failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'ADMIN_OVERVIEW_UNAVAILABLE',
                '管理概览暂时不可用。',
                503,
                $requestId,
            );
        }
    }
}
