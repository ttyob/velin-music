<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\MediaQueryService;
use app\application\Scrobble\ScrobbleOutbox;
use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\application\ResourcePlugin\PluginEventPublisher;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 持久化幂等播放事件，并派生当前账号的私有历史和播放次数。
 *
 * 服务在 SQLite 加锁前证明媒体授权，并在用户/播放器范围内去重。有效收听只累计正向位置差，且受服务端
 * 经过时间、五秒投递容差和单事件 30 秒上限共同限制，避免拖到结尾直接计次。时长未知时保留合法位置并
 * 使用四分钟保守阈值；事务内不读取媒体，也不请求外部 scrobble 服务，失败整体回滚。
 */
final class PlaybackEventService
{
    public function __construct(
        private readonly MediaQueryService $media = new MediaQueryService(),
        private readonly ScrobbleOutbox $scrobbleOutbox = new ScrobbleOutbox(),
        private readonly PlaybackPrefetchJobService $prefetch = new PlaybackPrefetchJobService(),
        private readonly ListeningTimeService $listeningTime = new ListeningTimeService(),
        private readonly PluginEventPublisher $pluginEvents = new PluginEventPublisher(),
    ) {
    }

    /**
     * Records one command or returns the exact stored result for a duplicate event ID.
     *
     * 已知普通曲目使用 `max(30秒, min(曲长50%, 4分钟))`，短曲使用一秒下限；未知时长使用四分钟。
     * 同一 playbackId 最多增加一次聚合计数，复用于其他歌曲时失败并回滚会话、事件和统计的全部写入。
     *
     * @param array<string, mixed> $actor 具有 play 能力且仍需实时收敛音乐库授权的身份。
     * @return array<string, mixed> 使用毫秒单位的幂等事件结果。
     */
    public function record(array $actor, PlaybackEventInput $input): array
    {
        $songs = $this->media->songsByIds($actor, [$input->songId]);
        $song = $songs[$input->songId] ?? null;
        if (!is_array($song)) {
            throw new PlaybackEventSongNotFound('Playback song not found.');
        }
        // songsByIds 兼容旧 key，但投影 ID 始终是重新授权后的规范目标。后续会话、统计和预取都使用
        // 该 ID，避免旧客户端在已隐藏来源上重新产生当前个人状态；历史已提交事件不做追溯改写。
        $songId = (string) ($song['id'] ?? '');
        if ($songId === '') throw new PlaybackEventSongNotFound('Playback song not found.');
        $durationMs = max(0, (int) ($song['durationMs'] ?? 0));
        $thresholdMs = $this->thresholdMs($durationMs);
        $userId = (string) $actor['id'];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $receivedAt = $now->format('Y-m-d\TH:i:s.v\Z');
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try {
            /** @var stdClass|null $duplicate */
            $duplicate = Db::table('playback_events')
                ->where('user_id', $userId)->where('player_id', $input->playerId)
                ->where('event_id', $input->eventId)
                ->first(['event_id', 'session_id', 'position_ms', 'counted_now', 'session_counted', 'listened_ms_after', 'play_count_after', 'status_after']);
            if ($duplicate instanceof stdClass) {
                /** @var stdClass $session */
                $session = Db::table('playback_sessions')->where('id', (string) $duplicate->session_id)->first(['playback_id']);
                $pdo->exec('COMMIT');
                $transactionOpen = false;

                return $this->resultFromStored($duplicate, (string) $session->playback_id, true);
            }

            /** @var stdClass|null $session */
            $session = Db::table('playback_sessions')
                ->where('user_id', $userId)->where('player_id', $input->playerId)
                ->where('playback_id', $input->playbackId)->first();
            if ($session instanceof stdClass && (string) $session->song_id !== $songId) {
                throw new PlaybackEventInvalid('playbackId is already bound to another song.');
            }

            $status = $this->statusFor($input->type);
            $occurredAt = $input->occurredAt->format('Y-m-d\TH:i:s.v\Z');
            $listenedMs = 0;
            $listenedDeltaMs = 0;
            $sessionId = (string) new Ulid();
            $counted = false;
            if ($session instanceof stdClass) {
                $sessionId = (string) $session->id;
                $elapsedMs = max(0, ($now->getTimestamp() - (new DateTimeImmutable((string) $session->last_received_at))->getTimestamp()) * 1000);
                $positionDelta = max(0, $input->positionMs - (int) $session->last_position_ms);
                // 倍速播放时媒体时间自然快于墙钟时间；仍保留单事件 30 秒上限，防止暂停后跳转
                // 或伪造极高倍速把一次短请求变成完整播放。只有上一状态确实在播放时才使用经过
                // 时间；starting/paused/stopped 期间的等待不能成为有效收听。ignoreScrobble 事件只
                // 推进时间线锚点，后续事件也不能追溯累计被明确忽略的区间。
                $previousState = is_string($session->reported_state ?? null)
                    ? (string) $session->reported_state
                    : (string) $session->status;
                $playingElapsedMs = $previousState === 'playing' ? $elapsedMs : 0;
                $deliveryWindowMs = (int) floor($playingElapsedMs * $input->playbackRate) + 5_000;
                if ($input->allowPlayCount) {
                    $listenedDeltaMs = min($positionDelta, $deliveryWindowMs, 30_000);
                    $listenedMs = (int) $session->listened_ms + $listenedDeltaMs;
                } else {
                    $listenedMs = (int) $session->listened_ms;
                }
                $counted = $session->counted_at !== null;
            }
            $countedNow = $input->allowPlayCount && !$counted && $listenedMs >= $thresholdMs;
            $counted = $counted || $countedNow;
            $countedAt = $countedNow ? $receivedAt : ($session?->counted_at === null ? null : (string) $session->counted_at);

            if ($session instanceof stdClass) {
                Db::table('playback_sessions')->where('id', $sessionId)->update([
                    'queue_version' => $input->queueVersion,
                    'last_occurred_at' => $occurredAt,
                    'last_received_at' => $receivedAt,
                    'last_position_ms' => $this->boundedPosition($input->positionMs, $durationMs),
                    'listened_ms' => $listenedMs,
                    'threshold_ms' => $thresholdMs,
                    'status' => $status,
                    'reported_state' => $input->reportedState ?? $status,
                    'playback_rate' => $input->playbackRate,
                    'counted_at' => $countedAt,
                    'updated_at' => $receivedAt,
                ]);
            } else {
                Db::table('playback_sessions')->insert([
                    'id' => $sessionId,
                    'playback_id' => $input->playbackId,
                    'user_id' => $userId,
                    'player_id' => $input->playerId,
                    'song_id' => $songId,
                    'queue_version' => $input->queueVersion,
                    'started_at' => $occurredAt,
                    'last_occurred_at' => $occurredAt,
                    'last_received_at' => $receivedAt,
                    'start_position_ms' => $this->boundedPosition($input->positionMs, $durationMs),
                    'last_position_ms' => $this->boundedPosition($input->positionMs, $durationMs),
                    'listened_ms' => 0,
                    'threshold_ms' => $thresholdMs,
                    'status' => $status,
                    'reported_state' => $input->reportedState ?? $status,
                    'playback_rate' => $input->playbackRate,
                    'counted_at' => null,
                    'cleared_at' => null,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ]);
            }

            /** @var stdClass|null $stats */
            $stats = Db::table('user_song_play_stats')->where('user_id', $userId)
                ->where('song_id', $songId)->first();
            $playCount = (int) ($stats?->play_count ?? 0) + ($countedNow ? 1 : 0);
            if ($input->allowPlayCount) {
                $statValues = [
                    'play_count' => $playCount,
                    'last_played_at' => $countedNow ? $receivedAt : ($stats?->last_played_at ?? null),
                    'last_activity_at' => $receivedAt,
                    'last_position_ms' => $this->boundedPosition($input->positionMs, $durationMs),
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
            }

            // 日统计复用本事件已经通过反跳播限制的正增量，不能用累计会话值重复相加。它与会话、
            // 播放次数和事件处于同一写事务；eventId 重试会在事务开头返回，因此不会重复累计。
            $this->listeningTime->addInterval(
                $userId,
                (string) ($actor['timezone'] ?? 'UTC'),
                $now,
                $listenedDeltaMs,
                $receivedAt,
            );

            $event = [
                'id' => (string) new Ulid(),
                'event_id' => $input->eventId,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'player_id' => $input->playerId,
                'event_type' => $input->type,
                'position_ms' => $this->boundedPosition($input->positionMs, $durationMs),
                'occurred_at' => $occurredAt,
                'received_at' => $receivedAt,
                'counted_now' => $countedNow ? 1 : 0,
                'session_counted' => $counted ? 1 : 0,
                'listened_ms_after' => $listenedMs,
                'play_count_after' => $playCount,
                'status_after' => $status,
            ];
            Db::table('playback_events')->insert($event);
            // 客户端是否有内存/磁盘预加载能力不影响服务端缓存：started 只在同一短事务内覆盖一条不透明
            // 任务，下一首解析、实时授权和远端下载均由提交后的独立 Worker 完成。重复 eventId 在上方直接
            // 返回存储结果，不会重置正在执行的同一任务。
            if ($input->type === 'started') {
                $this->prefetch->enqueue($userId, $input->playerId, $songId);
            }
            // 外部投递与内部播放事实使用同一事务：started 只更新 Now Playing，达到账号级计数阈值的首个
            // 事件才产生正式 scrobble。outbox 冻结无路径元数据且通过连接+事件唯一键去重，网络请求由
            // 独立 Worker 在提交后执行，因此第三方故障既不能回滚内部播放统计，也不会阻塞播放器心跳。
            if ($input->type === 'started') {
                $this->scrobbleOutbox->enqueue(
                    $userId, 'web:' . (string) $event['id'], 'now_playing', $song, $occurredAt,
                );
            }
            if ($countedNow) {
                $this->scrobbleOutbox->enqueue(
                    $userId, 'web:' . (string) $event['id'], 'scrobble', $song, $occurredAt,
                );
            }
            $pdo->exec('COMMIT');
            $transactionOpen = false;

            if ($countedNow) {
                // 只对首次达到服务端有效播放阈值的事件通知插件；客户端 completed 但未达到阈值不伪造完成。
                // Redis 写发生在 COMMIT 之后，失败不会回滚播放统计或 Scrobble outbox。
                $this->pluginEvents->publish(
                    PluginDomainEvent::PLAYBACK_COMPLETED,
                    'song',
                    $songId,
                    'user',
                    $userId,
                    [
                        'playbackId' => $input->playbackId,
                        'positionMs' => (int) $event['position_ms'],
                        'listenedMs' => $listenedMs,
                        'playCount' => $playCount,
                    ],
                );
            }

            return $this->resultFromStored((object) $event, $input->playbackId, false);
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }
    }

    /**
     * 计算播放计次阈值；未知时长固定使用四分钟的保守阈值。
     *
     * 这样 `filename_only` 歌曲不会因时长被错误收敛为 1ms 而立即计次；首次实际媒体读取补全成功后，
     * 后续事件自然采用真实曲长。补全始终失败时，仍只有累计四分钟有效收听才能计次。
     */
    private function thresholdMs(int $durationMs): int
    {
        if ($durationMs === 0) {
            return 240_000;
        }
        $half = (int) ceil($durationMs / 2);
        if ($durationMs <= 30_000) {
            return max(1_000, $half);
        }

        return max(30_000, min($half, 240_000));
    }

    /** 已知时长限制到结尾；未知时长保留已经过协议校验的非负播放位置。 */
    private function boundedPosition(int $positionMs, int $durationMs): int
    {
        return $durationMs === 0 ? $positionMs : min($positionMs, $durationMs);
    }

    /** Maps wire event types to the current session state stored for history/current-playing views. */
    private function statusFor(string $type): string
    {
        return match ($type) {
            'started', 'progress' => 'playing',
            'paused' => 'paused',
            'completed' => 'completed',
            'stopped' => 'stopped',
            default => throw new PlaybackEventInvalid('Unsupported event type.'),
        };
    }

    /** @return array<string, mixed> Reconstructs the exact persisted idempotency result. */
    private function resultFromStored(stdClass $event, string $playbackId, bool $duplicate): array
    {
        return [
            'eventId' => (string) $event->event_id,
            'playbackId' => $playbackId,
            'duplicate' => $duplicate,
            'positionMs' => (int) $event->position_ms,
            'listenedMs' => (int) $event->listened_ms_after,
            'countedNow' => (bool) $event->counted_now,
            'playCounted' => (bool) $event->session_counted,
            'playCount' => (int) $event->play_count_after,
            'status' => (string) $event->status_after,
        ];
    }
}
