<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaQueryService;
use app\application\Preference\MediaPreferenceInvalid;
use app\application\Preference\MediaPreferenceNotFound;
use app\application\Preference\MediaPreferenceService;
use app\application\Preference\MediaPreferenceValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes isolated favorites for songs, albums, and artists (PERS-001).
 *
 * Every operation requires the play capability and repeats current library authorization in the
 * application layer. Write routes are explicitly CSRF-protected in route.php. Missing, unavailable,
 * malformed-object, and unauthorized media cannot reveal global catalog existence; validation
 * errors disclose only the public type contract and logs never include personal values.
 */
final class PreferenceController
{
    /** Returns one bounded page of the current user's favorites for the selected media type. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $type = (new MediaPreferenceValidator())->type($request->get('type', 'songs'));
            $libraryId = is_string($request->get('libraryId')) ? $request->get('libraryId') : null;
            $limit = is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50;
            $offset = is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0;
            $service = new MediaQueryService();
            $data = match ($type) {
                'songs' => $service->songs($actor, $libraryId, $limit, $offset, null, true),
                'albums' => $service->albums($actor, $libraryId, $limit, $offset, true),
                'artists' => $service->artists($actor, $libraryId, $limit, $offset, true),
            };

            return JsonResponseFactory::create([
                'data' => ['type' => $type] + $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Favorite list request failed.');
        }
    }

    /** Sets favorite=true idempotently and returns the complete preference state. */
    public function favorite(Request $request, string $type, string $mediaId): Response
    {
        return $this->changeFavorite($request, $type, $mediaId, true);
    }

    /** Sets favorite=false idempotently for the current user. */
    public function unfavorite(Request $request, string $type, string $mediaId): Response
    {
        return $this->changeFavorite($request, $type, $mediaId, false);
    }

    /** Applies a favorite command after validating URL structure and current authorization. */
    private function changeFavorite(
        Request $request,
        string $type,
        string $mediaId,
        bool $favorite,
    ): Response {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $validator = new MediaPreferenceValidator();
            $type = $validator->type($type);
            $mediaId = $validator->mediaId($mediaId);
            $preference = (new MediaPreferenceService())->setFavorite($actor, $type, $mediaId, $favorite);

            return $this->preferenceResponse($preference, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Media favorite command failed.');
        }
    }

    /** @param array<string, mixed> $preference Emits the stable command response envelope. */
    private function preferenceResponse(array $preference, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => ['preference' => $preference],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /** Maps stable API failures without logging private favorite values or request bodies. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof MediaPreferenceInvalid) {
            return JsonResponseFactory::error(
                'VALIDATION_FAILED',
                '媒体类型或标识无效。',
                422,
                $requestId,
            );
        }
        if ($throwable instanceof MediaPreferenceNotFound) {
            return JsonResponseFactory::error(
                'MEDIA_NOT_FOUND',
                '媒体不存在、不可用或无权访问。',
                404,
                $requestId,
            );
        }

        Log::error($logMessage, [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error('PREFERENCE_UNAVAILABLE', '个人音乐数据暂时不可用。', 503, $requestId);
    }
}
