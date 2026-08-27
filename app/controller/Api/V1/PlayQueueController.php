<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playback\PlayQueueConflict;
use app\application\Playback\PlayQueueItemNotFound;
use app\application\Playback\PlayQueueService;
use app\application\Playback\PlayQueueValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes the authenticated user's current versioned Web queue (PLAY-001 through PLAY-003).
 *
 * Both operations require the global play capability and PlayQueueService repeats per-song library
 * scope. PUT is CSRF-protected at the explicit route. The controller accepts only a complete queue
 * replacement; partial browser commands cannot bypass index/version consistency validation.
 */
final class PlayQueueController
{
    /** Returns the current path-free queue, using version zero when none has been persisted. */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $queue = (new PlayQueueService())->snapshot($actor);

            return JsonResponseFactory::create([
                'data' => ['queue' => $queue],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Play queue read failed.');
        }
    }

    /**
     * Replaces queue order, selection, progress, and modes under optimistic version control.
     *
     * Validation occurs before the service acquires SQLite's write reservation. Known stale and
     * inaccessible-item failures use stable 409/404 contracts without exposing other libraries.
     */
    public function replace(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $payload = $request->post();
            $validation = (new PlayQueueValidator())->validate(is_array($payload) ? $payload : []);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请检查播放队列数据。',
                    422,
                    $requestId,
                    ['fields' => $validation->errors],
                );
            }
            $queue = (new PlayQueueService())->replace($actor, $validation->input);

            return JsonResponseFactory::create([
                'data' => ['queue' => $queue],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Play queue replacement failed.');
        }
    }

    /** Maps only stable personal-queue errors and logs no song list, progress, or request body. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof PlayQueueConflict) {
            return JsonResponseFactory::error('PLAY_QUEUE_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof PlayQueueItemNotFound) {
            return JsonResponseFactory::error(
                'PLAY_QUEUE_ITEM_NOT_FOUND',
                '一首或多首歌曲不存在或已无权访问。',
                404,
                $requestId,
            );
        }

        Log::error($logMessage, [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error(
            'PLAY_QUEUE_UNAVAILABLE',
            '播放队列服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
