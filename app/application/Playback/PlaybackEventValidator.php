<?php

declare(strict_types=1);

namespace app\application\Playback;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Validates playback telemetry before authorization queries or SQLite write locking.
 *
 * Client event/playback/player IDs are opaque deduplication keys, not SQL or media identifiers.
 * occurredAt must be UTC-compatible and no more than seven days old or five minutes in the future;
 * the server's received time remains authoritative for anti-seek listening accumulation.
 */
final class PlaybackEventValidator
{
    /** Maps a JSON/form payload to an immutable command or throws without side effects. */
    public function validate(array $payload, ?DateTimeImmutable $now = null): PlaybackEventInput
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $eventId = $this->opaqueId($payload['eventId'] ?? null, 'eventId');
        $playbackId = $this->opaqueId($payload['playbackId'] ?? null, 'playbackId');
        $playerId = $this->opaqueId($payload['playerId'] ?? null, 'playerId');
        $songId = $payload['songId'] ?? null;
        if (!is_string($songId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
            throw new PlaybackEventInvalid('songId is invalid.');
        }
        $type = $payload['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['started', 'progress', 'paused', 'completed', 'stopped'], true)) {
            throw new PlaybackEventInvalid('type is invalid.');
        }
        $queueVersion = $this->boundedInteger($payload['queueVersion'] ?? null, 0, 2_147_483_647, 'queueVersion');
        $positionMs = $this->boundedInteger($payload['positionMs'] ?? null, 0, 604_800_000, 'positionMs');
        try {
            $occurredAt = new DateTimeImmutable((string) ($payload['occurredAt'] ?? ''));
        } catch (Throwable) {
            throw new PlaybackEventInvalid('occurredAt is invalid.');
        }
        $occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
        if ($occurredAt < $now->modify('-7 days') || $occurredAt > $now->modify('+5 minutes')) {
            throw new PlaybackEventInvalid('occurredAt is outside the accepted clock window.');
        }

        return new PlaybackEventInput(
            $eventId,
            $playbackId,
            $playerId,
            $songId,
            $queueVersion,
            $positionMs,
            $occurredAt,
            $type,
        );
    }

    /** Accepts printable identifier tokens without path separators, whitespace, or SQL punctuation. */
    private function opaqueId(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $value) !== 1) {
            throw new PlaybackEventInvalid($field . ' is invalid.');
        }

        return $value;
    }

    /** Accepts JSON integers and canonical unsigned form integers inside a closed range. */
    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $field): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new PlaybackEventInvalid($field . ' is invalid.');
        }

        return $value;
    }
}
