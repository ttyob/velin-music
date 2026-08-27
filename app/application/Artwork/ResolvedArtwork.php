<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * Internal immutable file response approved by live album authorization and identity validation.
 *
 * The path remains confined to ArtworkController and must never enter JSON, logs, browser state, or
 * headers. The ETag is a quoted content digest and reveals no storage coordinates.
 */
final readonly class ResolvedArtwork
{
    public function __construct(
        public string $albumId,
        public string $path,
        public string $mimeType,
        public int $fileSize,
        public int $modifiedAt,
        public string $etag,
    ) {
    }
}
