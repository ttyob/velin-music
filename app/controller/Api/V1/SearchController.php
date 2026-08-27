<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaQueryService;
use app\application\Search\SearchValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes permission-scoped cross-entity media search without retaining search history.
 *
 * A valid Session and `play` capability are required before query execution. SearchValidator owns
 * all structural input limits, while MediaQueryService reapplies live library grants to each entity
 * section. Logs contain the request ID and exception class only; the user's search text is private
 * activity and must not enter server analytics or operational error context.
 */
final class SearchController
{
    /** Returns bounded song, album, and artist sections or safe field-level validation errors. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $validation = (new SearchValidator())->validate([
                'q' => $request->get('q'),
                'type' => $request->get('type', 'all'),
                'libraryId' => $request->get('libraryId'),
                'limit' => $request->get('limit', '20'),
                'offset' => $request->get('offset', '0'),
            ]);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请检查搜索条件。',
                    422,
                    $requestId,
                    ['fields' => $validation->errors],
                );
            }
            $data = (new MediaQueryService())->search($actor, $validation->input);

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有搜索音乐的权限。', 403, $requestId);
            }
            Log::error('Media search request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('SEARCH_UNAVAILABLE', '搜索服务暂时不可用。', 503, $requestId);
        }
    }
}
