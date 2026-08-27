<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Home\HomeQueryService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes the authenticated discovery homepage aggregate (DISC-001, WEB-PAGE-003).
 *
 * Authentication and the global play capability are required before any personal data is read.
 * HomeQueryService owns live library authorization and partial-module semantics. The controller is
 * read-only: refreshing the page cannot mutate playback history, rotate queues, or start scans.
 */
final class HomeController
{
    /** Returns a successful envelope even when explicitly marked child modules are unavailable. */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $data = (new HomeQueryService())->snapshot($actor, $requestId);

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            Log::error('Home discovery request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('HOME_UNAVAILABLE', '首页内容暂时不可用。', 503, $requestId);
        }
    }
}
