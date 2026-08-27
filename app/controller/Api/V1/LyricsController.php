<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Lyrics\LyricsQueryService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes path-free structured lyrics for one currently playable song (LYRIC-API-001/002).
 *
 * The route requires the global play capability and LyricsQueryService repeats live song/library
 * scope. Unavailable, unauthorized, malformed, and unknown song IDs share the same 404 response to
 * prevent catalog enumeration. The endpoint is read-only and never contacts external providers in
 * an HTTP worker.
 */
final class LyricsController
{
    /** Returns all authorized displayable versions, including a successful empty collection. */
    public function show(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $lyrics = (new LyricsQueryService())->forSong($actor, $songId);
            if ($lyrics === null) {
                return JsonResponseFactory::error('SONG_NOT_FOUND', '歌曲不存在或不可用。', 404, $requestId);
            }

            return JsonResponseFactory::create([
                'data' => $lyrics,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有查看歌词的权限。', 403, $requestId);
            }
            Log::error('Lyrics query failed.', [
                'request_id' => $requestId,
                'song_id' => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) === 1 ? $songId : null,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('LYRICS_UNAVAILABLE', '歌词服务暂时不可用。', 503, $requestId);
        }
    }
}
