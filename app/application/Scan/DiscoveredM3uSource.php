<?php

declare(strict_types=1);

namespace app\application\Scan;

/**
 * Immutable scan-time identity for an M3U/M3U8 file found beneath a registered library root.
 *
 * The value is persisted only by the scan Worker. Relative/resolved paths never cross an API or audit
 * boundary; `displayName` is the bounded final component used in the owner's source picker. The source
 * bytes are deliberately not read here: synchronization reopens the registered file later and repeats
 * containment plus the complete stat identity check before parsing at most 1 MiB.
 */
final readonly class DiscoveredM3uSource
{
    public function __construct(
        public string $relativePath,
        public string $resolvedPath,
        public string $displayName,
        public string $extension,
        public int $deviceId,
        public int $inode,
        public int $fileSize,
        public int $modifiedAt,
    ) {
    }
}
