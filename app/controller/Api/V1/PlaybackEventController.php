<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playback\PlaybackEventInvalid;
use app\application\Playback\PlaybackEventService;
use app\application\Playback\PlaybackEventSongNotFound;
use app\application\Playback\PlaybackEventValidator;
use app\application\Playback\PlaybackHistoryService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use DateTimeImmutable;
use DateTimeZone;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes playback telemetry and current-user recent history (PERS-003/005, API-PLAY-001/002).
 *
 * All operations require the play capability; POST/DELETE routes are CSRF-protected explicitly.
 * The controller never accepts a user ID, never logs event payloads or listening behavior, and maps
 * unavailable/unauthorized songs to the same 404. Playback streams themselves do not call this API.
 */
final class PlaybackEventController
{
    /** Validates and records one idempotent playback event. */
    public function record(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $payload = $request->post();
            $input = (new PlaybackEventValidator())->validate(is_array($payload) ? $payload : []);
            $event = (new PlaybackEventService())->record($actor, $input);

            return JsonResponseFactory::create([
                'data' => ['event' => $event],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playback event command failed.');
        }
    }

    /** Returns one page of the authenticated user's currently visible history. */
    public function history(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $limit = is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50;
            $offset = is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0;
            $data = (new PlaybackHistoryService())->page($actor, $limit, $offset);

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playback history request failed.');
        }
    }

    /** Hides one occurrence idempotently without changing its aggregate play count. */
    public function clearOne(Request $request, string $playbackId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $changed = (new PlaybackHistoryService())->clearOne($actor, $playbackId);

            return $this->clearResponse($changed, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playback history item clear failed.');
        }
    }

    /** Hides all history or an explicit before-time range for the current user. */
    public function clear(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $scope = $request->post('scope');
            if ($scope !== 'all' && $scope !== 'before') {
                throw new PlaybackEventInvalid('History clear scope is invalid.');
            }
            $before = null;
            if ($scope === 'before') {
                try {
                    $before = new DateTimeImmutable((string) $request->post('before'), new DateTimeZone('UTC'));
                } catch (Throwable) {
                    throw new PlaybackEventInvalid('History clear boundary is invalid.');
                }
            }
            $changed = (new PlaybackHistoryService())->clear($actor, $before);

            return $this->clearResponse($changed, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playback history range clear failed.');
        }
    }

    /** Emits an idempotent clear result with only the current user's changed-row count. */
    private function clearResponse(int $changed, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => ['cleared' => $changed],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /** Maps stable failures without logging event IDs, player IDs, song IDs, or history values. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof PlaybackEventInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '播放事件或历史范围无效。', 422, $requestId);
        }
        if ($throwable instanceof PlaybackEventSongNotFound) {
            return JsonResponseFactory::error('SONG_NOT_FOUND', '歌曲不存在、不可用或无权访问。', 404, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('PLAYBACK_DATA_UNAVAILABLE', '播放记录服务暂时不可用。', 503, $requestId);
    }
}
