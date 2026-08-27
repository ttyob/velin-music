<?php

declare(strict_types=1);

namespace app\application\Playback;

/**
 * Validates an untrusted full-queue replacement before any transaction or authorization query.
 *
 * The 500-item bound limits JSON, SQL IN-list, and SQLite write work. Syntax validation does not
 * prove media visibility; PlayQueueService repeats live song and library authorization while its
 * write transaction is held so a revoked grant cannot be persisted through a stale browser list.
 */
final class PlayQueueValidator
{
    private const MAX_ITEMS = 500;
    private const MAX_POSITION_MS = 2_592_000_000;

    /**
     * Maps strict JSON scalar types to a command and rejects inconsistent empty/current state.
     *
     * @param array<string, mixed> $payload Parsed request object; unknown keys are ignored.
     */
    public function validate(array $payload): PlayQueueValidationResult
    {
        $errors = [];
        $expectedVersion = $payload['expectedVersion'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 0) {
            $errors['expectedVersion'][] = '队列版本必须是非负整数。';
        }

        $rawSongIds = $payload['songIds'] ?? null;
        $songIds = [];
        if (!is_array($rawSongIds) || !array_is_list($rawSongIds)) {
            $errors['songIds'][] = '歌曲队列必须是数组。';
        } elseif (count($rawSongIds) > self::MAX_ITEMS) {
            $errors['songIds'][] = '播放队列最多包含 500 首歌曲。';
        } else {
            foreach ($rawSongIds as $songId) {
                if (!is_string($songId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
                    $errors['songIds'][] = '播放队列包含无效的歌曲标识。';
                    break;
                }
                $songIds[] = $songId;
            }
        }

        $currentIndex = $payload['currentIndex'] ?? null;
        if ($songIds === []) {
            if ($currentIndex !== null) {
                $errors['currentIndex'][] = '空队列不能包含当前歌曲位置。';
            }
        } elseif (!is_int($currentIndex) || $currentIndex < 0 || $currentIndex >= count($songIds)) {
            $errors['currentIndex'][] = '当前歌曲位置超出队列范围。';
        }

        $positionMs = $payload['positionMs'] ?? null;
        if (!is_int($positionMs) || $positionMs < 0 || $positionMs > self::MAX_POSITION_MS) {
            $errors['positionMs'][] = '播放进度必须是有效的毫秒整数。';
        }

        $repeatMode = $payload['repeatMode'] ?? null;
        if (!is_string($repeatMode) || !in_array($repeatMode, ['off', 'all', 'one'], true)) {
            $errors['repeatMode'][] = '循环模式无效。';
        }

        $shuffleEnabled = $payload['shuffleEnabled'] ?? null;
        if (!is_bool($shuffleEnabled)) {
            $errors['shuffleEnabled'][] = '随机播放状态必须是布尔值。';
        }

        if ($errors !== []) {
            return new PlayQueueValidationResult(null, $errors);
        }

        return new PlayQueueValidationResult(new PlayQueueInput(
            expectedVersion: $expectedVersion,
            songIds: $songIds,
            currentIndex: $currentIndex,
            positionMs: $songIds === [] ? 0 : $positionMs,
            repeatMode: $repeatMode,
            shuffleEnabled: $shuffleEnabled,
        ), []);
    }
}
