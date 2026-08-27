<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * Performs bounded, side-effect-free validation of a local raster artwork candidate.
 *
 * Extension and filename selection belong to AlbumArtworkIndexer. This inspector verifies the
 * actual file signature reported by PHP, conservative byte/pixel limits, a stable stat identity,
 * and a streaming SHA-256 digest. SVG, HTML, GIF, active content, and changing files are rejected.
 */
final class ArtworkImageInspector
{
    private const MAX_FILE_BYTES = 20_971_520;
    private const MAX_DIMENSION = 12_000;
    private const MAX_PIXELS = 80_000_000;

    /**
     * @return array{mime: string, width: int, height: int, size: int, mtime: int, dev: int, ino: int, sha256: string}|null
     */
    public function inspect(string $path): ?array
    {
        $before = @stat($path);
        if (!is_array($before) || (int) ($before['size'] ?? 0) <= 0 || (int) $before['size'] > self::MAX_FILE_BYTES) {
            return null;
        }
        $image = @getimagesize($path);
        $hash = @hash_file('sha256', $path);
        $after = @stat($path);
        if (!is_array($image) || !is_string($hash) || !$this->sameFile($before, $after)) {
            return null;
        }
        $mime = $image['mime'] ?? null;
        $width = (int) ($image[0] ?? 0);
        $height = (int) ($image[1] ?? 0);
        if (!is_string($mime)
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $width <= 0 || $height <= 0
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS
        ) {
            return null;
        }

        return [
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'size' => (int) $after['size'],
            'mtime' => max(0, (int) $after['mtime']),
            'dev' => (int) $after['dev'],
            'ino' => (int) $after['ino'],
            'sha256' => $hash,
        ];
    }

    /** Confirms that image bytes and identity remained stable throughout validation. */
    private function sameFile(array $before, array|false $after): bool
    {
        return is_array($after)
            && (int) ($before['dev'] ?? -1) === (int) ($after['dev'] ?? -2)
            && (int) ($before['ino'] ?? -1) === (int) ($after['ino'] ?? -2)
            && (int) ($before['size'] ?? -1) === (int) ($after['size'] ?? -2)
            && (int) ($before['mtime'] ?? -1) === (int) ($after['mtime'] ?? -2);
    }
}
