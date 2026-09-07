<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Media\MediaQueryService;
use app\application\Playback\ListeningTimeService;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Records Subsonic now-playing/submission commands in Velin's private playback history model.
 *
 * `submission=false` updates current activity without incrementing play count. `submission=true`
 * represents the protocol's explicit completed-listen assertion and increments at most once for the
 * deterministic user/client/song/time occurrence. It does not contact external services. Unknown
 * duration remains zero and uses a conservative internal threshold; it is never rewritten as 1ms.
 */
final readonly class SubsonicScrobbleService
{
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private ListeningTimeService $listeningTime = new ListeningTimeService(),
    ) {
    }

    /**
     * Persists one idempotent scrobble after live song authorization.
     *
     * `time` is Unix epoch milliseconds per Subsonic v1.16.1. Supplied values may be historical but
     * not more than five minutes in the future. When omitted, a ten-second server bucket supplies a
     * stable retry key. The deterministic event/session IDs contain hashes only, not client names or
     * user/media identifiers. BEGIN IMMEDIATE serializes duplicate checks with stat increments. An
     * explicit submission may count even when duration is unknown, but its stored position remains zero.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @param array<string, mixed> $parameters Merged protocol parameters including id/c/time/submission.
     */
    public function record(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $songId = $this->songId($parameters['id'] ?? null);
        $submission = array_key_exists('submission', $parameters)
            ? $this->boolean($parameters['submission'])
            : true;
        $nowMs = (int) floor(microtime(true) * 1000);
        $suppliedTime = array_key_exists('time', $parameters);
        $occurredMs = $suppliedTime ? $this->time($parameters['time'], $nowMs) : $nowMs;
        $occurrenceKey = $suppliedTime ? $occurredMs : intdiv($occurredMs, 10_000) * 10_000;
        $client = $parameters['c'] ?? null;
        if (!is_string($client) || $client === '' || strlen($client) > 64) {
            throw new SubsonicRequestInvalid('Client identifier is invalid.');
        }
        $songs = $this->media->songsByIds($actor, [$songId]);
        $song = $songs[$songId] ?? null;
        if (!is_array($song)) {
            throw new SubsonicEntityNotFound('Song was not found.');
        }
        // MediaQueryService 为旧来源 ID 保留请求 key，但投影 ID 是重新授权后的保留歌曲。幂等材料、
        // 会话和统计统一使用规范 ID，确保客户端混用旧/新 ID 时不会重复计次或重新分叉个人状态。
        $songId = (string) ($song['id'] ?? '');
        if ($songId === '') throw new SubsonicEntityNotFound('Song was not found.');

        $userId = (string) ($actor['id'] ?? '');
        $playerId = 'subsonic-' . substr(hash('sha256', $client), 0, 32);
        $occurrenceMaterial = implode("\0", [$userId, $playerId, $songId, (string) $occurrenceKey]);
        $playbackId = 'subsonic-play-' . substr(hash('sha256', $occurrenceMaterial), 0, 36);
        $eventId = 'subsonic-event-' . substr(
            hash('sha256', $occurrenceMaterial . "\0" . ($submission ? 'submission' : 'now-playing')),
            0,
            34,
        );
        $durationMs = max(0, (int) ($song['durationMs'] ?? 0));
        $thresholdMs = $this->thresholdMs($durationMs);
        $occurredAt = $this->timestamp($occurredMs);
        $receivedAt = $this->timestamp($nowMs);
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try {
            $duplicate = Db::table('playback_events')->where('user_id', $userId)
                ->where('player_id', $playerId)->where('event_id', $eventId)->exists();
            if ($duplicate) {
                $pdo->exec('COMMIT');
                $transactionOpen = false;
                return [];
            }

            /** @var stdClass|null $session */
            $session = Db::table('playback_sessions')->where('user_id', $userId)
                ->where('player_id', $playerId)->where('playback_id', $playbackId)->first();
            $countedBefore = $session instanceof stdClass && $session->counted_at !== null;
            $countedNow = $submission && !$countedBefore;
            $counted = $countedBefore || $submission;
            $sessionId = $session instanceof stdClass ? (string) $session->id : (string) new Ulid();
            $positionMs = $submission ? $durationMs : 0;
            $listenedMs = $submission ? $thresholdMs : (int) ($session?->listened_ms ?? 0);
            $status = $submission ? 'completed' : 'playing';
            $countedAt = $countedNow ? $occurredAt : ($session?->counted_at ?? null);
            if ($session instanceof stdClass) {
                Db::table('playback_sessions')->where('id', $sessionId)->update([
                    'last_occurred_at' => $occurredAt,
                    'last_received_at' => $receivedAt,
                    'last_position_ms' => $positionMs,
                    'listened_ms' => $listenedMs,
                    'threshold_ms' => $thresholdMs,
                    'status' => $status,
                    'counted_at' => $countedAt,
                    'updated_at' => $receivedAt,
                ]);
            } else {
                Db::table('playback_sessions')->insert([
                    'id' => $sessionId,
                    'playback_id' => $playbackId,
                    'user_id' => $userId,
                    'player_id' => $playerId,
                    'song_id' => $songId,
                    'queue_version' => 0,
                    'started_at' => $occurredAt,
                    'last_occurred_at' => $occurredAt,
                    'last_received_at' => $receivedAt,
                    'start_position_ms' => 0,
                    'last_position_ms' => $positionMs,
                    'listened_ms' => $listenedMs,
                    'threshold_ms' => $thresholdMs,
                    'status' => $status,
                    'counted_at' => $countedAt,
                    'cleared_at' => null,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ]);
            }

            /** @var stdClass|null $stats */
            $stats = Db::table('user_song_play_stats')->where('user_id', $userId)
                ->where('song_id', $songId)->first();
            $playCount = (int) ($stats?->play_count ?? 0) + ($countedNow ? 1 : 0);
            $statValues = [
                'play_count' => $playCount,
                'last_played_at' => $countedNow ? $occurredAt : ($stats?->last_played_at ?? null),
                'last_activity_at' => $receivedAt,
                'last_position_ms' => $positionMs,
                'updated_at' => $receivedAt,
            ];
            if ($stats instanceof stdClass) {
                Db::table('user_song_play_stats')->where('user_id', $userId)
                    ->where('song_id', $songId)->update($statValues);
            } else {
                Db::table('user_song_play_stats')->insert([
                    'user_id' => $userId,
                    'song_id' => $songId,
                    ...$statValues,
                ]);
            }

            // 传统 Subsonic submission 只有“完成收听”事实而没有连续心跳。已知曲长可按完整曲长记入
            // 日统计；未知曲长继续保持 0，不能把内部四分钟计次阈值伪装成真实听歌时长。确定性事件
            // 去重已在事务开头完成，因此客户端重试不会再次累计。
            $this->listeningTime->addInterval(
                $userId,
                (string) ($actor['timezone'] ?? 'UTC'),
                $this->dateTimeFromEpochMs($occurredMs),
                $countedNow ? $durationMs : 0,
                $receivedAt,
            );

            Db::table('playback_events')->insert([
                'id' => $playbackEventId = (string) new Ulid(),
                'event_id' => $eventId,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'player_id' => $playerId,
                'event_type' => $submission ? 'completed' : 'started',
                'position_ms' => $positionMs,
                'occurred_at' => $occurredAt,
                'received_at' => $receivedAt,
                'counted_now' => $countedNow ? 1 : 0,
                'session_counted' => $counted ? 1 : 0,
                'listened_ms_after' => $listenedMs,
                'play_count_after' => $playCount,
                'status_after' => $status,
            ]);
            $pdo->exec('COMMIT');
            $transactionOpen = false;

            return [];
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }
    }

    /** 与 Web 事件使用相同阈值；未知时长保留 0，并以四分钟作为保守内部会话阈值。 */
    private function thresholdMs(int $durationMs): int
    {
        if ($durationMs === 0) {
            return 240_000;
        }
        $half = (int) ceil($durationMs / 2);

        return $durationMs <= 30_000
            ? max(1_000, $half)
            : max(30_000, min($half, 240_000));
    }

    /** Accepts only one strict stable song ID; collection submissions are intentionally unsupported. */
    private function songId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid('Song ID is invalid.');
        }

        return $value;
    }

    /** Validates Unix milliseconds without accepting future telemetry beyond clock-skew tolerance. */
    private function time(mixed $value, int $nowMs): int
    {
        if (is_int($value)) {
            $time = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,13}$/', $value) === 1) {
            $time = (int) $value;
        } else {
            throw new SubsonicRequestInvalid('Scrobble time is invalid.');
        }
        if ($time < 0 || $time > $nowMs + 300_000) {
            throw new SubsonicRequestInvalid('Scrobble time is outside the accepted clock window.');
        }

        return $time;
    }

    /** Converts epoch milliseconds to the database's fixed-width UTC millisecond representation. */
    private function timestamp(int $epochMs): string
    {
        $seconds = intdiv($epochMs, 1000);
        $milliseconds = $epochMs % 1000;

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $milliseconds);
    }

    /** 从协议 epoch 毫秒构造 UTC 时间，供日界线拆分使用；输入已通过 [time] 校验。 */
    private function dateTimeFromEpochMs(int $epochMs): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', intdiv($epochMs, 1000), ($epochMs % 1000) * 1000),
        );
        if ($value === false) {
            throw new SubsonicRequestInvalid('Scrobble time is invalid.');
        }
        return $value->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Accepts only explicit Subsonic boolean values. */
    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            default => throw new SubsonicRequestInvalid('Scrobble submission flag is invalid.'),
        };
    }

    /** Enforces global play capability before media visibility or personal writes. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic scrobble is not authorized.');
        }
    }
}
