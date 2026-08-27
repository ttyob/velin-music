<?php

declare(strict_types=1);

namespace app\application\Bookmark;

/**
 * Immutable command for one user's bookmark position and optional comment.
 *
 * Position is milliseconds and has already passed syntax/range validation. `expectedVersion` is
 * zero for Web creation, positive for Web replacement, and null only for legacy Subsonic overwrite
 * semantics. The service still revalidates song authorization and duration inside its transaction.
 */
final readonly class BookmarkInput
{
    public function __construct(
        public string $songId,
        public int $positionMs,
        public ?string $comment,
        public ?int $expectedVersion,
    ) {
    }
}
