<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\MediaQueryService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * Reads and hides the authenticated user's recent playback songs.
 *
 * Storage retains every playback session for idempotency and aggregate statistics, while recent
 * history exposes only the newest uncleared session per song. Clearing sets cleared_at instead of
 * deleting sessions/events, so old event retries remain idempotent and play counts never decrease.
 * No administrator can select another user through this service.
 */
final class PlaybackHistoryService
{
    public function __construct(private readonly MediaQueryService $media = new MediaQueryService())
    {
    }

    /**
     * Returns unique recent songs in stable newest-session-first order with bounded pagination.
     *
     * @param array<string, mixed> $actor Authenticated owner with live library grants.
     * @return array{history: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function page(array $actor, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = $this->latestSongQuery($actor);
        $total = (clone $query)->count('sessions.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('sessions.updated_at')->orderByDesc('sessions.id')
            ->offset($offset)->limit($limit)->get([
                'sessions.playback_id', 'sessions.song_id', 'sessions.player_id', 'sessions.started_at',
                'sessions.last_received_at', 'sessions.updated_at', 'sessions.last_position_ms', 'sessions.listened_ms',
                'sessions.threshold_ms', 'sessions.status', 'sessions.counted_at',
                'sessions.reported_state', 'sessions.playback_rate',
            ])->all();
        return ['history' => $this->project($actor, $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Returns this user's players that have reported `playing` within the heartbeat window.
     *
     * The initial Web client reports progress frequently enough that two minutes distinguishes an
     * active player from a closed tab without a separate online-presence table. Unlike recent history,
     * this keeps one occurrence per player so two devices playing the same song remain distinguishable.
     * Current library grants and availability still apply before any session is projected.
     *
     * @param array<string, mixed> $actor Authenticated owner with live library grants.
     * @return list<array<string, mixed>> Newest active occurrences, bounded to 24.
     */
    public function nowPlaying(array $actor, int $limit): array
    {
        $limit = max(1, min(24, $limit));
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-2 minutes')->format('Y-m-d\TH:i:s.v\Z');
        /** @var list<stdClass> $rows */
        $rows = $this->visibleSessionQuery($actor)
            ->where('sessions.status', 'playing')->where('sessions.updated_at', '>=', $cutoff)
            ->orderByDesc('sessions.updated_at')->orderByDesc('sessions.id')->limit($limit)
            ->get($this->projectionColumns())->all();

        return $this->project($actor, $rows);
    }

    /** Hides every current occurrence of the selected recent song for this user. */
    public function clearOne(array $actor, string $playbackId): int
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $playbackId) !== 1) {
            throw new PlaybackEventInvalid('playbackId is invalid.');
        }

        $userId = (string) $actor['id'];
        /** @var stdClass|null $selected */
        $selected = Db::table('playback_sessions')->where('user_id', $userId)
            ->where('playback_id', $playbackId)->whereNull('cleared_at')->first(['song_id']);
        if (!$selected instanceof stdClass) {
            return 0;
        }
        $now = $this->now();
        Db::table('playback_sessions')->where('user_id', $userId)
            ->where('song_id', (string) $selected->song_id)->whereNull('cleared_at')
            ->update(['cleared_at' => $now, 'updated_at' => $now]);

        return 1;
    }

    /**
     * Hides all history or sessions updated at/before an explicit UTC boundary.
     *
     * Aggregate user_song_play_stats and deduplication events are intentionally untouched. A late
     * event may update a hidden session but cannot clear cleared_at or make it visible again.
     */
    public function clear(array $actor, ?DateTimeImmutable $before): int
    {
        $visibleLatest = $this->latestSongQuery($actor);
        if ($before instanceof DateTimeImmutable) {
            $visibleLatest->where('sessions.updated_at', '<=', $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'));
        }
        $removedSongs = $visibleLatest->count('sessions.id');

        $query = Db::table('playback_sessions')->where('user_id', (string) $actor['id'])
            ->whereNull('cleared_at');
        if ($before instanceof DateTimeImmutable) {
            $query->where('updated_at', '<=', $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'));
        }
        $now = $this->now();

        $query->update(['cleared_at' => $now, 'updated_at' => $now]);

        return $removedSongs;
    }

    /** Builds the owner- and authorization-scoped occurrence query shared by both history views. */
    private function visibleSessionQuery(array $actor): Builder
    {
        $query = Db::table('playback_sessions as sessions')
            ->where('sessions.user_id', (string) $actor['id'])
            ->whereNull('sessions.cleared_at');
        $this->media->constrainToVisibleSongs($query, $actor, 'sessions.song_id');

        return $query;
    }

    /** Restricts visible occurrences to the stable newest row for each song. */
    private function latestSongQuery(array $actor): Builder
    {
        $userId = (string) $actor['id'];

        return $this->visibleSessionQuery($actor)->whereNotExists(
            static function (Builder $newer) use ($userId): void {
                $newer->selectRaw('1')->from('playback_sessions as newer')
                    ->where('newer.user_id', $userId)->whereNull('newer.cleared_at')
                    ->whereColumn('newer.song_id', 'sessions.song_id')
                    ->where(static function (Builder $order): void {
                        $order->whereColumn('newer.updated_at', '>', 'sessions.updated_at')
                            ->orWhere(static function (Builder $tie): void {
                                $tie->whereColumn('newer.updated_at', 'sessions.updated_at')
                                    ->whereColumn('newer.id', '>', 'sessions.id');
                            });
                    });
            },
        );
    }

    /** @return list<string> Closed path-free session columns required by history projection. */
    private function projectionColumns(): array
    {
        return [
            'sessions.playback_id', 'sessions.song_id', 'sessions.player_id', 'sessions.started_at',
            'sessions.last_received_at', 'sessions.updated_at', 'sessions.last_position_ms', 'sessions.listened_ms',
            'sessions.threshold_ms', 'sessions.status', 'sessions.counted_at',
            'sessions.reported_state', 'sessions.playback_rate',
        ];
    }

    /** @param list<stdClass> $rows @return list<array<string, mixed>> */
    private function project(array $actor, array $rows): array
    {
        $songIds = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows)));
        $songs = $this->media->songsByIds($actor, $songIds);
        $stats = $this->stats((string) $actor['id'], $songIds);
        $history = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) {
                continue;
            }
            $history[] = [
                'playbackId' => (string) $row->playback_id,
                'playerId' => (string) $row->player_id,
                'song' => $songs[$songId],
                'startedAt' => (string) $row->started_at,
                'lastReceivedAt' => (string) $row->last_received_at,
                'updatedAt' => (string) $row->updated_at,
                'positionMs' => (int) $row->last_position_ms,
                'listenedMs' => (int) $row->listened_ms,
                'thresholdMs' => (int) $row->threshold_ms,
                'status' => (string) $row->status,
                'reportedState' => is_string($row->reported_state ?? null)
                    ? (string) $row->reported_state
                    : (string) $row->status,
                'playbackRate' => max(0.1, min(16.0, (float) ($row->playback_rate ?? 1.0))),
                'playCounted' => $row->counted_at !== null,
                'playCount' => $stats[$songId] ?? 0,
            ];
        }

        return $history;
    }

    /** @param list<string> $songIds @return array<string, int> Current-user counts keyed by song. */
    private function stats(string $userId, array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }
        $rows = Db::table('user_song_play_stats')->where('user_id', $userId)
            ->whereIn('song_id', $songIds)->get(['song_id', 'play_count']);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->song_id] = (int) $row->play_count;
        }

        return $result;
    }

    /** Returns sortable UTC text shared by clear filters and history ordering. */
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
