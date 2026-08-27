<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Playback\PlayQueueConflict;
use app\application\Playback\PlayQueueInput;
use app\application\Playback\PlayQueueItemNotFound;
use app\application\Playback\PlayQueueService;
use RuntimeException;

/**
 * Adapts Subsonic's ID-based queue contract to Velin's index-based versioned personal queue.
 *
 * Reads inherit PlayQueueService's live authorization filtering. Writes preserve duplicate IDs and
 * choose the first matching occurrence for Subsonic's ambiguous `current` string. Since the legacy
 * protocol carries no optimistic version or repeat/shuffle fields, save retries a bounded conflict
 * window with last-command-wins semantics while preserving the latest internal playback modes.
 */
final readonly class SubsonicQueueService
{
    public function __construct(
        private PlayQueueService $queues = new PlayQueueService(),
        private SubsonicCatalogService $catalog = new SubsonicCatalogService(),
    ) {
    }

    /** Returns the authenticated user's current queue with `current` kept as an opaque string ID. */
    public function get(array $actor): array
    {
        $this->requirePlay($actor);

        return ['playQueue' => $this->mapSnapshot($actor, $this->queues->snapshot($actor), false)];
    }

    /** 返回 OpenSubsonic v1 队列，并以零基索引精确表示当前重复歌曲出现位置。 */
    public function getByIndex(array $actor): array
    {
        $this->requirePlay($actor);

        return ['playQueueByIndex' => $this->mapSnapshot($actor, $this->queues->snapshot($actor), true)];
    }

    /**
     * Replaces IDs, current occurrence, and millisecond position, then returns an empty success body.
     *
     * Up to 500 repeated `id` fields are accepted and duplicates remain ordered. Missing current on a
     * non-empty queue selects index zero for clients that omit the optional field; a supplied current
     * must occur in the submitted IDs. A conflict is retried twice from a fresh version/mode snapshot,
     * preventing an infinite hot loop while matching Subsonic's lack of version preconditions.
     *
     * @param array<string, mixed> $parameters Merged query/form parameters.
     */
    public function save(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $songIds = $this->ids($parameters['id'] ?? null);
        $current = array_key_exists('current', $parameters)
            ? $this->singleId($parameters['current'], 'Current song')
            : null;
        if ($songIds === [] && $current !== null) {
            throw new SubsonicRequestInvalid('An empty queue cannot have a current song.');
        }
        $currentIndex = null;
        if ($songIds !== []) {
            $currentIndex = $current === null ? 0 : array_search($current, $songIds, true);
            if ($currentIndex === false) {
                throw new SubsonicRequestInvalid('Current song is not present in the queue.');
            }
        }
        $position = array_key_exists('position', $parameters)
            ? $this->integer($parameters['position'], 0, 2_592_000_000, 'Queue position')
            : 0;

        return $this->replace($actor, $songIds, $currentIndex, $position);
    }

    /**
     * 按 OpenSubsonic `currentIndex` 保存完整队列，重复歌曲不再产生 ID 歧义。
     *
     * 空队列不得携带 currentIndex；非空队列必须显式提供且落在提交数组范围内。position 缺省为
     * 零。与旧端点一样，repeat/shuffle 从最新内部快照保留，协议不能意外覆盖 Web 播放模式。
     */
    public function saveByIndex(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $songIds = $this->ids($parameters['id'] ?? null);
        $hasCurrent = array_key_exists('currentIndex', $parameters);
        if ($songIds === []) {
            if ($hasCurrent) throw new SubsonicRequestInvalid('An empty queue cannot have a current index.');
            $currentIndex = null;
        } else {
            if (!$hasCurrent) throw new SubsonicRequestInvalid('Current index is required for a non-empty queue.');
            $currentIndex = $this->integer(
                $parameters['currentIndex'],
                0,
                count($songIds) - 1,
                'Current queue index',
            );
        }
        $position = array_key_exists('position', $parameters)
            ? $this->integer($parameters['position'], 0, 2_592_000_000, 'Queue position')
            : 0;

        return $this->replace($actor, $songIds, $currentIndex, $position);
    }

    /**
     * 用当前版本和播放模式提交一次完整替换；协议没有版本字段，因此最多重读重试两次。
     *
     * @param list<string> $songIds 保留重复出现位置的歌曲 ID。
     */
    private function replace(array $actor, array $songIds, ?int $currentIndex, int $position): array
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $snapshot = $this->queues->snapshot($actor);
            $input = new PlayQueueInput(
                expectedVersion: (int) $snapshot['version'],
                songIds: $songIds,
                currentIndex: $currentIndex,
                positionMs: $position,
                repeatMode: (string) $snapshot['repeatMode'],
                shuffleEnabled: (bool) $snapshot['shuffleEnabled'],
            );
            try {
                $this->queues->replace($actor, $input);
                return [];
            } catch (PlayQueueConflict) {
                // The protocol has no expectedVersion. Re-read a bounded number of times, then fail.
            } catch (PlayQueueItemNotFound $exception) {
                throw new SubsonicEntityNotFound('A queue song was not found.', previous: $exception);
            }
        }

        throw new RuntimeException('SUBSONIC_QUEUE_CONFLICT_RETRY_EXHAUSTED');
    }

    /** @return array<string, mixed> Maps only already-authorized snapshot song projections. */
    private function mapSnapshot(array $actor, array $snapshot, bool $byIndex): array
    {
        $entries = [];
        foreach (is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [] as $item) {
            if (!is_array($item) || !is_array($item['song'] ?? null)) {
                continue;
            }
            $entries[] = $this->catalog->mapAuthorizedSong($item['song']);
        }
        $result = [
            'entry' => $entries,
            'position' => max(0, (int) ($snapshot['positionMs'] ?? 0)),
            'username' => (string) ($actor['username'] ?? ''),
            'changed' => is_string($snapshot['updatedAt'] ?? null)
                ? $snapshot['updatedAt']
                : '1970-01-01T00:00:00Z',
            'changedBy' => 'Velin Music',
        ];
        $currentIndex = $snapshot['currentIndex'] ?? null;
        if (is_int($currentIndex) && isset($entries[$currentIndex]['id'])) {
            if ($byIndex) $result['currentIndex'] = $currentIndex;
            else $result['current'] = (string) $entries[$currentIndex]['id'];
        }

        return $result;
    }

    /** @return list<string> Parses absent-as-empty or at most 500 repeated queue IDs. */
    private function ids(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 500) {
            throw new SubsonicRequestInvalid('Play queue contains too many songs.');
        }

        return array_map(fn (mixed $item): string => $this->singleId($item, 'Queue song'), $values);
    }

    /** Accepts one canonical opaque media ID without integer conversion. */
    private function singleId(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid($label . ' ID is invalid.');
        }

        return $value;
    }

    /** Parses strict canonical decimal queue progress in milliseconds. */
    private function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new SubsonicRequestInvalid($label . ' is outside the supported range.');
        }

        return $integer;
    }

    /** Enforces global play capability before reading or replacing personal queue state. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic queue operation is not authorized.');
        }
    }
}
