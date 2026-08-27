<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistInvalid;
use app\application\Playlist\PlaylistNotFound;
use app\application\Playlist\PlaylistValidator;
use app\application\Playlist\SmartPlaylistService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes owner-only smart-playlist rule commands (ND-014, LIST-005/006, WEB-PAGE-017).
 *
 * Every route requires an authenticated principal with create_playlist; ownership and versions are
 * repeated by SmartPlaylistService. Bodies contain constrained JSON rules, never SQL. CSRF middleware
 * is attached to every mutation/preview POST because previews can be computationally meaningful even
 * though they do not persist. Rule values, media IDs, names, descriptions, and request bodies are not
 * logged; failures return the same non-enumerating playlist boundary used by ordinary lists.
 */
final class SmartPlaylistController
{
    /** Atomically creates one smart header/definition and returns its first live result. */
    public function create(Request $request): Response
    {
        return $this->command($request, null, 'create');
    }

    /** Evaluates an unsaved bounded rule draft without creating a header or audit record. */
    public function previewDraft(Request $request): Response
    {
        return $this->command($request, null, 'preview_draft');
    }

    /** Returns one owner-only canonical rule snapshot and its shared optimistic version. */
    public function showRules(Request $request, string $playlistId): Response
    {
        return $this->command($request, $playlistId, 'show');
    }

    /** Replaces the complete rule definition under expectedVersion. */
    public function updateRules(Request $request, string $playlistId): Response
    {
        return $this->command($request, $playlistId, 'update');
    }

    /** Evaluates a saved or modified owner draft with the saved playlist's random seed. */
    public function previewRules(Request $request, string $playlistId): Response
    {
        return $this->command($request, $playlistId, 'preview_saved');
    }

    /**
     * Maps the small route surface to one service and one stable JSON/error contract.
     *
     * The service revalidates every definition and owner boundary. No action accepts a target user,
     * library path, arbitrary query fragment, or result offset; the hard server result cap is 500.
     */
    private function command(Request $request, ?string $playlistId, string $command): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $payload = is_array($payload) ? $payload : [];
            $service = new SmartPlaylistService();
            $data = match ($command) {
                'create' => ['playlist' => $service->create(
                    $actor,
                    (new PlaylistValidator())->create($payload),
                    $payload,
                    $requestId,
                )],
                'preview_draft' => $service->previewDraft($actor, $payload),
                'show' => ['definition' => $service->definition($actor, (string) $playlistId)],
                'update' => $service->update(
                    $actor,
                    (string) $playlistId,
                    (new PlaylistValidator())->version($payload['expectedVersion'] ?? null),
                    $payload,
                    $requestId,
                ),
                'preview_saved' => $service->previewSaved($actor, (string) $playlistId, $payload === [] ? null : $payload),
                default => throw new PlaylistInvalid('Unknown smart playlist command.'),
            };
            $status = $command === 'create' ? 201 : 200;

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], $status, $requestId)->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId);
        }
    }

    /** Maps safe domain failures without returning raw rule values, SQL, or private object existence. */
    private function mapFailure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有编辑智能播放列表的权限。', 403, $requestId);
        }
        if ($throwable instanceof PlaylistInvalid) {
            return JsonResponseFactory::error('SMART_PLAYLIST_INVALID', '请检查智能播放列表规则。', 422, $requestId);
        }
        if ($throwable instanceof PlaylistNotFound) {
            return JsonResponseFactory::error('PLAYLIST_NOT_FOUND', '智能播放列表不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof PlaylistConflict) {
            return JsonResponseFactory::error('PLAYLIST_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        Log::error('Smart playlist command failed.', [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error('PLAYLIST_UNAVAILABLE', '智能播放列表服务暂时不可用。', 503, $requestId);
    }
}
