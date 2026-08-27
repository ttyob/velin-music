<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\MediaQueryService;
use PDO;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * Owns the current user's versioned Web queue and its authorization-safe projection.
 *
 * Queue rows store stable song IDs only, never paths. Every read resolves items through the live
 * media scope, so revoked grants, disabled libraries, missing files, and failed metadata disappear
 * immediately. Every replacement rechecks that same scope while holding SQLite's short write lock;
 * browser state is never trusted as proof that a song remains playable.
 */
final class PlayQueueService
{
    public function __construct(private readonly MediaQueryService $media = new MediaQueryService())
    {
    }

    /**
     * Returns the user's current queue after filtering every item through live media authorization.
     *
     * Inaccessible items are omitted without exposing how many were removed. If the persisted
     * current item became inaccessible, the projection advances to the next visible item (or wraps
     * to the first) with zero progress. GET never rewrites the queue; a later user command replaces
     * the sanitized projection under optimistic version control.
     *
     * @param array<string, mixed> $actor Authenticated principal with global play capability.
     * @return array<string, mixed> Path-free queue snapshot; version zero means never persisted.
     */
    public function snapshot(array $actor): array
    {
        /** @var stdClass|null $queue */
        $queue = Db::table('play_queues')->where('user_id', (string) $actor['id'])->first([
            'id', 'current_index', 'position_ms', 'repeat_mode', 'shuffle_enabled',
            'version', 'updated_at',
        ]);
        if (!$queue instanceof stdClass) {
            return $this->emptySnapshot();
        }

        /** @var list<stdClass> $rows */
        $rows = Db::table('play_queue_items')->where('queue_id', (string) $queue->id)
            ->orderBy('position')->get(['position', 'song_id'])->all();
        $songs = $this->media->songsByIds(
            $actor,
            array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows),
        );

        $items = [];
        $visibleIndexByRawPosition = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) {
                continue;
            }
            $visibleIndex = count($items);
            $visibleIndexByRawPosition[(int) $row->position] = $visibleIndex;
            $items[] = ['index' => $visibleIndex, 'song' => $songs[$songId]];
        }

        $rawCurrentIndex = $queue->current_index === null ? null : (int) $queue->current_index;
        $currentIndex = null;
        $positionMs = 0;
        if ($items !== []) {
            if ($rawCurrentIndex !== null && isset($visibleIndexByRawPosition[$rawCurrentIndex])) {
                $currentIndex = $visibleIndexByRawPosition[$rawCurrentIndex];
                $positionMs = (int) $queue->position_ms;
            } else {
                $currentIndex = $this->nextVisibleIndex($visibleIndexByRawPosition, $rawCurrentIndex);
            }
        }

        return [
            'id' => (string) $queue->id,
            'items' => $items,
            'currentIndex' => $currentIndex,
            'positionMs' => $positionMs,
            'repeatMode' => (string) $queue->repeat_mode,
            'shuffleEnabled' => (int) $queue->shuffle_enabled === 1,
            'version' => (int) $queue->version,
            'updatedAt' => (string) $queue->updated_at,
        ];
    }

    /**
     * Atomically replaces the entire personal queue after version and live-scope validation.
     *
     * BEGIN IMMEDIATE serializes competing SQLite writers before reading the current version. The
     * transaction contains bounded SQL only: no media bytes, FFprobe, network, Session, or audit I/O.
     * Failure rolls back header and items together, so clients never observe a partially reordered
     * queue. Repeating a stale command returns PlayQueueConflict instead of silently overwriting a
     * newer browser or device snapshot.
     *
     * @param array<string, mixed> $actor Authenticated principal whose user ID owns the queue.
     * @throws PlayQueueConflict expectedVersion differs from the committed version.
     * @throws PlayQueueItemNotFound Any unique song is absent from the actor's live media scope.
     * @return array<string, mixed> Newly committed, authorization-filtered queue snapshot.
     * 时长已知时位置限制到结尾；时长为 0 表示未知，此时保留已验证位置而不误清零。
     */
    public function replace(array $actor, PlayQueueInput $input): array
    {
        $userId = (string) $actor['id'];
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;

        try {
            /** @var stdClass|null $existing */
            $existing = Db::table('play_queues')->where('user_id', $userId)
                ->first(['id', 'version']);
            $currentVersion = $existing instanceof stdClass ? (int) $existing->version : 0;
            if ($currentVersion !== $input->expectedVersion) {
                throw new PlayQueueConflict('播放队列已在其他页面更新，请同步后重试。');
            }

            $uniqueSongIds = array_values(array_unique($input->songIds));
            $songs = $this->media->songsByIds($actor, $uniqueSongIds);
            if (count($songs) !== count($uniqueSongIds)) {
                throw new PlayQueueItemNotFound('One or more queue songs are unavailable.');
            }
            // songsByIds 为旧来源 ID 保留请求 key，但投影 ID 是重新授权后的规范目标。按出现位置替换 ID，
            // 不折叠重复项也不移动 currentIndex；旧客户端保存队列不会重新产生指向隐藏来源的引用。
            $songIds = array_map(
                static fn (string $songId): string => (string) $songs[$songId]['id'],
                $input->songIds,
            );

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $queueId = $existing instanceof stdClass ? (string) $existing->id : (string) new Ulid();
            $nextVersion = $currentVersion + 1;
            $positionMs = $this->boundedPosition($input, $songs);
            $header = [
                'current_index' => $input->currentIndex,
                'position_ms' => $positionMs,
                'repeat_mode' => $input->repeatMode,
                'shuffle_enabled' => $input->shuffleEnabled ? 1 : 0,
                'version' => $nextVersion,
                'updated_at' => $now,
            ];

            if ($existing instanceof stdClass) {
                $changed = Db::table('play_queues')->where('id', $queueId)
                    ->where('version', $currentVersion)->update($header);
                if ($changed !== 1) {
                    throw new PlayQueueConflict('播放队列已在其他页面更新，请同步后重试。');
                }
                Db::table('play_queue_items')->where('queue_id', $queueId)->delete();
            } else {
                Db::table('play_queues')->insert([
                    'id' => $queueId,
                    'user_id' => $userId,
                    ...$header,
                    'created_at' => $now,
                ]);
            }

            if ($songIds !== []) {
                $itemRows = [];
                foreach ($songIds as $position => $songId) {
                    $itemRows[] = [
                        'queue_id' => $queueId,
                        'position' => $position,
                        'song_id' => $songId,
                        'added_at' => $now,
                    ];
                }
                Db::table('play_queue_items')->insert($itemRows);
            }

            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return $this->snapshot($actor);
    }

    /** @return array<string, mixed> Stable empty state used before the first persisted command. */
    private function emptySnapshot(): array
    {
        return [
            'id' => null,
            'items' => [],
            'currentIndex' => null,
            'positionMs' => 0,
            'repeatMode' => 'off',
            'shuffleEnabled' => false,
            'version' => 0,
            'updatedAt' => null,
        ];
    }

    /**
     * Selects the next visible raw position after a removed current item, wrapping to index zero.
     *
     * @param array<int, int> $visibleIndexByRawPosition Raw persisted position to projected index.
     */
    private function nextVisibleIndex(array $visibleIndexByRawPosition, ?int $rawCurrentIndex): int
    {
        if ($rawCurrentIndex !== null) {
            foreach ($visibleIndexByRawPosition as $rawPosition => $visibleIndex) {
                if ($rawPosition > $rawCurrentIndex) {
                    return $visibleIndex;
                }
            }
        }

        return 0;
    }

    /**
     * 将恢复位置限制在已知歌曲时长内；未知时长保留已通过输入校验的位置。
     *
     * `filename_only` 网络歌曲在首次媒体读取前时长为 0，这不表示歌曲长度为零。此时清空恢复位置会
     * 破坏刷新续播；待播放补全真实时长后，后续队列写入再恢复常规上限校验。
     *
     * @param array<string, array<string, mixed>> $songs Authorized projections keyed by song ID.
     */
    private function boundedPosition(PlayQueueInput $input, array $songs): int
    {
        if ($input->currentIndex === null || $input->songIds === []) {
            return 0;
        }
        $song = $songs[$input->songIds[$input->currentIndex]];
        $durationMs = max(0, (int) ($song['durationMs'] ?? 0));

        return $durationMs === 0 ? $input->positionMs : min($input->positionMs, $durationMs);
    }
}
