<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Radio\RadioConflict;
use app\application\Radio\RadioInvalid;
use app\application\Radio\RadioNotFound;
use app\application\Radio\RadioService;
use app\application\Radio\RadioValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes the Web internet-radio directory, personal favorites, and administrator commands.
 *
 * Reads/favorites require Session `play`; catalog writes require `manage_system` and are CSRF guarded
 * in route.php. The application service repeats authorization and owns visibility/concurrency. Raw URLs
 * and request bodies are never logged, and this adapter never opens submitted stream or artwork URLs.
 */
final class RadioController
{
    /** Returns a bounded, searchable station page with the current user's favorite flags. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $validator = new RadioValidator();
            $favoritesOnly = $request->get('favoritesOnly');
            if (!in_array($favoritesOnly, [null, '', '0', '1', 0, 1, false, true], true)) {
                throw new RadioInvalid('Favorite filter is invalid.');
            }
            $page = (new RadioService())->page(
                $actor,
                $validator->page($request->get('limit'), 100, 1, 100),
                $validator->page($request->get('offset'), 0, 0, 10_000),
                $validator->search($request->get('search')),
                in_array($favoritesOnly, ['1', 1, true], true),
            );

            return $this->response($page, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio list failed.');
        }
    }

    /** Returns one live-visible station without differentiating disabled from absent for listeners. */
    public function show(Request $request, string $stationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $station = (new RadioService())->one($actor, (new RadioValidator())->id($stationId));

            return $this->response(['station' => $station], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio read failed.');
        }
    }

    /** Creates one inert station definition; availability is tested only by a user's browser playback. */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            $station = (new RadioService())->create(
                $actor,
                (new RadioValidator())->command($request->post(), creating: true),
                $requestId,
            );

            return $this->response(['station' => $station], $requestId, 201);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio create failed.');
        }
    }

    /** Replaces one station's metadata only when its Web expectedVersion is still current. */
    public function update(Request $request, string $stationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            $validator = new RadioValidator();
            $station = (new RadioService())->update(
                $actor,
                $validator->id($stationId),
                $validator->command($request->post()),
                $requestId,
            );

            return $this->response(['station' => $station], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio update failed.');
        }
    }

    /** Deletes one exact station version and its preference rows, never a remote resource. */
    public function delete(Request $request, string $stationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            $validator = new RadioValidator();
            (new RadioService())->delete(
                $actor,
                $validator->id($stationId),
                $validator->version($request->post('expectedVersion')),
                $requestId,
            );

            return $this->response(['deleted' => true], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio delete failed.');
        }
    }

    /** Idempotently marks one currently readable station as the current user's favorite. */
    public function favorite(Request $request, string $stationId): Response
    {
        return $this->favoriteCommand($request, $stationId, true);
    }

    /** Idempotently removes only the current user's favorite state. */
    public function unfavorite(Request $request, string $stationId): Response
    {
        return $this->favoriteCommand($request, $stationId, false);
    }

    /** Executes one personal favorite command behind the common auth/error envelope. */
    private function favoriteCommand(Request $request, string $stationId, bool $favorite): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $station = (new RadioService())->favorite(
                $actor,
                (new RadioValidator())->id($stationId),
                $favorite,
            );

            return $this->response(['station' => $station], $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Radio favorite failed.');
        }
    }

    /** @param array<string, mixed> $data Emits the standard versioned API response envelope. */
    private function response(array $data, string $requestId, int $status = 200): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], $status, $requestId);
    }

    /** Maps stable failures without exposing submitted URLs, user IDs, SQL, or internal host data. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行电台操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof RadioInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查电台名称、公开 URL 和版本。', 422, $requestId);
        }
        if ($throwable instanceof RadioNotFound) {
            return JsonResponseFactory::error('RADIO_NOT_FOUND', '电台不存在、已停用或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof RadioConflict) {
            return JsonResponseFactory::error('RADIO_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('RADIO_UNAVAILABLE', '互联网电台服务暂时不可用。', 503, $requestId);
    }
}
