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

/** Exposes one read-only, capability-filtered administration health snapshot. */
final class OverviewController
{
    /**
     * Returns HTTP 401 for invalid Session, 403 without any management capability, and otherwise a
     * partial snapshot whose failed probes remain explicit unknown modules. No CSRF is required for
     * this GET and the operation never enqueues scans or touches media files.
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
