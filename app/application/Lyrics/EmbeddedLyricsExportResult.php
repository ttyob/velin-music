<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/**
 * Immutable outcome of one bounded attempt to materialize an embedded lyric tag as a sidecar.
 *
 * Status is intentionally safe for persistence and UI display. It carries neither a filesystem
 * path nor lyric content, so the scan report can expose it without leaking storage layout or text.
 */
final readonly class EmbeddedLyricsExportResult
{
    /**
     * @param 'not_found'|'created'|'already_exists'|'disabled'|'invalid'|'not_writable'|'failed' $status
     * @param 'plain'|'line'|'word'|null $kind Parsed synchronization granularity when recognized.
     */
    public function __construct(
        public bool $found,
        public string $status,
        public ?string $kind = null,
    ) {
    }
}
