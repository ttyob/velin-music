<?php

declare(strict_types=1);

namespace app\application\Home;

use app\application\Media\MediaQueryService;
use app\application\Playback\PlaybackHistoryService;
use support\Log;
use Throwable;

/**
 * Builds the authenticated user's permission-scoped discovery dashboard (DISC-001).
 *
 * Each module is evaluated behind an independent failure boundary: one broken personal-data or
 * catalog query is represented as that module's `error` state while other modules remain usable.
 * The service never accepts a target user or library from the request. MediaQueryService and
 * PlaybackHistoryService reapply current library grants, availability, and metadata state for every
 * child query; cached or historical relationships cannot grant access.
 */
final readonly class HomeQueryService
{
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private PlaybackHistoryService $history = new PlaybackHistoryService(),
    ) {
    }

    /**
     * Returns independently statused modules in the server-defined initial display order.
     *
     * Limits are intentionally small because this endpoint supplies previews, not full catalogs.
     * Recent playback is an account-level song list ordered by each song's newest session; player
     * occurrences remain available only to now-playing. The removed continue-listening preview is
     * deliberately not reconstructed from older occurrences. Failures expose only a stable
     * code, while logs contain request/module correlation and exception class but no user or media ID.
     * Calling this method is read-only and never starts playback, scanning, or file-system work.
     *
     * @param array<string, mixed> $actor Authenticated principal already proven to have `play`.
     * @return array{modules: array<string, array<string, mixed>>}
     */
    public function snapshot(array $actor, string $requestId): array
    {
        return ['modules' => [
            'nowPlaying' => $this->module(
                'nowPlaying',
                $requestId,
                fn (): array => $this->history->nowPlaying($actor, 8),
            ),
            'recentPlayback' => $this->module(
                'recentPlayback',
                $requestId,
                fn (): array => $this->history->page($actor, 8, 0)['history'],
            ),
            'recentlyAdded' => $this->module(
                'recentlyAdded',
                $requestId,
                fn (): array => $this->media->recentlyAddedAlbums($actor, 8),
            ),
            'favorites' => $this->module('favorites', $requestId, fn (): array => [
                'songs' => $this->media->songs($actor, null, 8, 0, null, true)['songs'],
                'albums' => $this->media->albums($actor, null, 8, 0, true)['albums'],
            ]),
            'frequentlyPlayed' => $this->module(
                'frequentlyPlayed',
                $requestId,
                fn (): array => $this->media->frequentlyPlayedSongs($actor, 8),
            ),
            'randomAlbums' => $this->module(
                'randomAlbums',
                $requestId,
                fn (): array => $this->media->randomAlbums($actor, 8),
            ),
        ]];
    }

    /**
     * Executes one read module and converts only that failure to a stable partial-result state.
     *
     * The callback must be read-only and permission scoped. Returning an empty `ready` value means
     * the query succeeded with no matching personal/catalog data; it is deliberately distinct from
     * `error`, which the Web client can retry without pretending an outage is an empty library.
     *
     * @param callable(): array<mixed> $query
     * @return array{status: 'ready', items: array<mixed>}|array{status: 'error', error: array{code: string}}
     */
    private function module(string $name, string $requestId, callable $query): array
    {
        try {
            return ['status' => 'ready', 'items' => $query()];
        } catch (Throwable $throwable) {
            if (is_array(config('log'))) {
                try {
                    Log::warning('Home discovery module query failed.', [
                        'request_id' => $requestId,
                        'module' => $name,
                        'exception_class' => $throwable::class,
                    ]);
                } catch (Throwable) {
                    // Partial-result semantics must survive a logger failure in CLI recovery.
                    // Production health checks report logger initialization separately; exposing
                    // that secondary failure here would erase otherwise usable home modules.
                }
            }

            return ['status' => 'error', 'error' => ['code' => 'HOME_MODULE_UNAVAILABLE']];
        }
    }
}
