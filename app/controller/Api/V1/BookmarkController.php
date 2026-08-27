<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Bookmark\BookmarkConflict;
use app\application\Bookmark\BookmarkInvalid;
use app\application\Bookmark\BookmarkNotFound;
use app\application\Bookmark\BookmarkService;
use app\application\Bookmark\BookmarkValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes versioned personal song bookmarks for the Web client (PERS-004, WEB-PAGE-018).
 *
 * All routes require Session authentication and `play`; mutation routes are explicitly CSRF guarded
 * in route.php. The application service owns user isolation, live song authorization, transactions,
 * and optimistic versions. Controller logs contain no song ID, position, comment, or request body.
 */
final class BookmarkController
{
    /** Returns a bounded recent-first page whose songs remain currently authorized. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $validator = new BookmarkValidator();
            $page = (new BookmarkService())->list(
                $actor,
                $validator->page($request->get('limit'), 50, 1, 100),
                $validator->page($request->get('offset'), 0, 0, 10_000),
            );

            return $this->response($page, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Bookmark list failed.');
        }
    }

    /** Returns one bookmark or null without disclosing absent versus unauthorized media. */
    public function show(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $songId = (new BookmarkValidator())->songId($songId);

            return $this->response(['bookmark' => (new BookmarkService())->one($actor, $songId)], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Bookmark read failed.');
        }
    }

    /** Creates with expectedVersion zero or replaces the exact current Web bookmark version. */
    public function save(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $input = (new BookmarkValidator())->input(
                $songId,
                $request->post('positionMs'),
                $request->post('comment'),
                $request->post('expectedVersion'),
            );
            $bookmark = (new BookmarkService())->save($actor, $input);

            return $this->response(['bookmark' => $bookmark], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Bookmark save failed.');
        }
    }

    /** Deletes one exact Web version and leaves the song/media file untouched. */
    public function delete(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $validator = new BookmarkValidator();
            (new BookmarkService())->delete(
                $actor,
                $validator->songId($songId),
                $validator->version($request->post('expectedVersion')),
            );

            return $this->response(['deleted' => true], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Bookmark delete failed.');
        }
    }

    /** @param array<string, mixed> $data Wraps successful bookmark data in the shared envelope. */
    private function response(array $data, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /** Maps stable failures without exposing personal comments, positions, song IDs, SQL, or paths. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof BookmarkInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查书签位置、备注和版本。', 422, $requestId);
        }
        if ($throwable instanceof BookmarkConflict) {
            return JsonResponseFactory::error('BOOKMARK_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof BookmarkNotFound) {
            return JsonResponseFactory::error('BOOKMARK_SONG_NOT_FOUND', '歌曲不存在、不可用或无权访问。', 404, $requestId);
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('BOOKMARK_UNAVAILABLE', '书签服务暂时不可用。', 503, $requestId);
    }
}
