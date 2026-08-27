<?php

declare(strict_types=1);

namespace app\application\Scan;

/**
 * Immutable path-level observation emitted by the discovery phase.
 *
 * Paths have already been resolved beneath the registered library root. Device/inode are stored
 * only as diagnostic identity hints; they are not yet the public media PID because hard links may
 * legitimately expose the same inode at multiple logical paths.
 */
final readonly class DiscoveredAudioFile
{
    public function __construct(
        public string $relativePath,
        public string $resolvedPath,
        public string $extension,
        public int $deviceId,
        public int $inode,
        public int $fileSize,
        public int $modifiedAt,
        public ?string $remoteEtag = null,
    ) {
    }
}
