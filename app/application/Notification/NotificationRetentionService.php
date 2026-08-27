<?php

declare(strict_types=1);

namespace app\application\Notification;

use support\Db;

/**
 * Prunes expired notification and realtime replay rows in one bounded SQLite transaction.
 *
 * User projections already exclude expired rows, so cleanup changes storage only and never marks an
 * event read or alters a mute. IDs are selected before deletion because SQLite DELETE LIMIT support
 * varies by build. The dedicated single Worker and maximum 1,000-row batch bound lock duration;
 * partial batches are safe to retry because deleting an absent selected row is a no-op.
 */
final class NotificationRetentionService
{
    /**
     * Deletes at most one bounded batch from each retention table.
     *
     * Notification delete triggers may append a fresh `notifications.changed` invalidation. That
     * event is intentionally retained for seven days and tells an already-open page to refetch its
     * now-expired snapshot. No notification body, media object, path, or audit record is created.
     *
     * @return array{notifications: int, realtimeEvents: int}
     */
    public function prune(string $cutoffUtc, int $limit = 500): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $cutoffUtc) !== 1) {
            throw new NotificationRetentionInvalid('Retention cutoff must be canonical UTC.');
        }
        if ($limit < 1 || $limit > 1_000) {
            throw new NotificationRetentionInvalid('Retention batch is outside the supported range.');
        }

        return Db::transaction(function () use ($cutoffUtc, $limit): array {
            /** @var list<string> $notificationIds */
            $notificationIds = Db::table('user_notifications')
                ->where('expires_at', '<=', $cutoffUtc)
                ->orderBy('expires_at')->orderBy('id')
                ->limit($limit)->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)->all();
            $notifications = $notificationIds === [] ? 0 : Db::table('user_notifications')
                ->whereIn('id', $notificationIds)->where('expires_at', '<=', $cutoffUtc)->delete();

            /** @var list<int> $eventSequences */
            $eventSequences = Db::table('realtime_events')
                ->where('expires_at', '<=', $cutoffUtc)
                ->orderBy('sequence')->limit($limit)->pluck('sequence')
                ->map(static fn (mixed $sequence): int => (int) $sequence)->all();
            $events = $eventSequences === [] ? 0 : Db::table('realtime_events')
                ->whereIn('sequence', $eventSequences)->where('expires_at', '<=', $cutoffUtc)->delete();

            return ['notifications' => $notifications, 'realtimeEvents' => $events];
        });
    }
}
