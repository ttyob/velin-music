<?php

declare(strict_types=1);

namespace app\application\Playback;

/**
 * Immutable, validated replacement command for one user's current Web playback queue.
 *
 * Song IDs retain order and duplicates. currentIndex addresses that exact array rather than a song
 * ID, which avoids ambiguity when a track occurs more than once. All units are explicit: position
 * is milliseconds and expectedVersion is zero only before the user's first persisted queue exists.
 */
final readonly class PlayQueueInput
{
    /** @param list<string> $songIds Ordered opaque media IDs, including intentional duplicates. */
    public function __construct(
        public int $expectedVersion,
        public array $songIds,
        public ?int $currentIndex,
        public int $positionMs,
        public string $repeatMode,
        public bool $shuffleEnabled,
    ) {
    }
}
