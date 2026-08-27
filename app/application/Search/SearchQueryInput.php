<?php

declare(strict_types=1);

namespace app\application\Search;

/**
 * Immutable, bounded search command accepted by the authorized catalog query boundary.
 *
 * `query` is the trimmed user-facing value returned to the client; `normalizedQuery` is the
 * database key and must never be logged as an analytics event. Offset is temporarily retained for
 * the Alpha catalog contract and capped at 10,000 until the public cursor format is frozen.
 */
final readonly class SearchQueryInput
{
    /** @param 'all'|'songs'|'albums'|'artists' $type */
    public function __construct(
        public string $query,
        public string $normalizedQuery,
        public string $type,
        public ?string $libraryId,
        public int $limit,
        public int $offset,
    ) {
    }
}
