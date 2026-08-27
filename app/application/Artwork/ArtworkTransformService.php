<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * Creates bounded same-format album thumbnails in an isolated private runtime cache.
 *
 * Authorization and source identity must be resolved by ArtworkService before this class is called.
 * Cache keys contain only source content identity and requested size, never paths or user IDs. The
 * cache itself grants no access: every response still re-runs album authorization first. JPEG, PNG,
 * and WebP are decoded with GD under explicit source-pixel and target-size limits.
 */
final class ArtworkTransformService
{
    private const MAX_SOURCE_PIXELS = 40_000_000;

    /**
     * Returns the original when no downscale is needed, otherwise an atomically cached thumbnail.
     *
     * The requested size is the maximum width/height in pixels and must be 16-2048. Aspect ratio is
     * preserved and images are never upscaled. A per-key advisory lock coordinates Webman workers;
     * generation writes a unique 0600 temp file, validates it, then renames atomically. Cancellation
     * or failure before rename leaves the previous cache untouched and removes the temp file.
     *
     * @throws ArtworkTransformFailed Invalid dimensions, unsupported GD codec, cache IO, or encoding failure.
     */
    public function resize(ResolvedArtwork $source, int $size): ResolvedArtwork
    {
        if ($size < 16 || $size > 2048) {
            throw new ArtworkTransformFailed('ARTWORK_SIZE_INVALID');
        }
        $dimensions = @getimagesize($source->path);
        if (!is_array($dimensions)) {
            throw new ArtworkTransformFailed('ARTWORK_DIMENSIONS_UNAVAILABLE');
        }
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new ArtworkTransformFailed('ARTWORK_DIMENSIONS_UNSAFE');
        }
        if ($width <= $size && $height <= $size) {
            return $source;
        }
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        [$extension, $decoder, $encoder] = $this->codec($source->mimeType);
        $cacheRoot = $this->cacheRoot();
        $key = hash('sha256', $source->etag . '|' . $size . '|' . $source->mimeType);
        $path = $cacheRoot . DIRECTORY_SEPARATOR . $key . '.' . $extension;
        $etag = '"' . $key . '"';
        if ($this->validCacheFile($path)) {
            return $this->cached($source, $path, $etag);
        }

        $lockPath = $cacheRoot . DIRECTORY_SEPARATOR . $key . '.lock';
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ArtworkTransformFailed('ARTWORK_CACHE_LOCK_FAILED');
        }
        $temporary = '';
        try {
            if (!$this->validCacheFile($path)) {
                $sourceImage = @$decoder($source->path);
                if (!$sourceImage instanceof \GdImage) {
                    throw new ArtworkTransformFailed('ARTWORK_DECODE_FAILED');
                }
                $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$targetImage instanceof \GdImage) {
                    imagedestroy($sourceImage);
                    throw new ArtworkTransformFailed('ARTWORK_ALLOCATE_FAILED');
                }
                if ($source->mimeType !== 'image/jpeg') {
                    imagealphablending($targetImage, false);
                    imagesavealpha($targetImage, true);
                    $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
                    imagefill($targetImage, 0, 0, $transparent);
                }
                $resampled = imagecopyresampled(
                    $targetImage,
                    $sourceImage,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $width,
                    $height,
                );
                imagedestroy($sourceImage);
                if (!$resampled) {
                    imagedestroy($targetImage);
                    throw new ArtworkTransformFailed('ARTWORK_RESAMPLE_FAILED');
                }
                $temporary = $cacheRoot . DIRECTORY_SEPARATOR . '.' . $key . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $encoded = @$encoder($targetImage, $temporary);
                imagedestroy($targetImage);
                if (!$encoded || !$this->validCacheFile($temporary) || !@chmod($temporary, 0600)) {
                    throw new ArtworkTransformFailed('ARTWORK_ENCODE_FAILED');
                }
                if (!@rename($temporary, $path)) {
                    throw new ArtworkTransformFailed('ARTWORK_CACHE_PUBLISH_FAILED');
                }
                $temporary = '';
            }
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->cached($source, $path, $etag);
    }

    /** Returns closed decoder/encoder callables for scanner-supported raster formats. */
    private function codec(string $mimeType): array
    {
        return match ($mimeType) {
            'image/jpeg' => [
                'jpg',
                static fn (string $path): \GdImage|false => imagecreatefromjpeg($path),
                static fn (\GdImage $image, string $path): bool => imagejpeg($image, $path, 88),
            ],
            'image/png' => [
                'png',
                static fn (string $path): \GdImage|false => imagecreatefrompng($path),
                static fn (\GdImage $image, string $path): bool => imagepng($image, $path, 6),
            ],
            'image/webp' => [
                'webp',
                static fn (string $path): \GdImage|false => imagecreatefromwebp($path),
                static fn (\GdImage $image, string $path): bool => imagewebp($image, $path, 88),
            ],
            default => throw new ArtworkTransformFailed('ARTWORK_CODEC_UNSUPPORTED'),
        };
    }

    /** Creates/validates the fixed private cache root without following a pre-existing symlink. */
    private function cacheRoot(): string
    {
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $root = rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artwork-cache';
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ArtworkTransformFailed('ARTWORK_CACHE_CREATE_FAILED');
        }
        if (is_link($root) || !is_writable($root)) {
            throw new ArtworkTransformFailed('ARTWORK_CACHE_UNSAFE');
        }
        @chmod($root, 0700);

        return $root;
    }

    /** Accepts only a regular non-symlink cache file with positive bounded size. */
    private function validCacheFile(string $path): bool
    {
        return is_file($path)
            && !is_link($path)
            && is_readable($path)
            && ($size = filesize($path)) !== false
            && $size > 0
            && $size <= 32 * 1024 * 1024;
    }

    /** Builds a response descriptor from a verified private cache file. */
    private function cached(ResolvedArtwork $source, string $path, string $etag): ResolvedArtwork
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        $modifiedAt = filemtime($path);
        if ($size === false || $modifiedAt === false || $size <= 0) {
            throw new ArtworkTransformFailed('ARTWORK_CACHE_IDENTITY_FAILED');
        }

        return new ResolvedArtwork(
            albumId: $source->albumId,
            path: $path,
            mimeType: $source->mimeType,
            fileSize: $size,
            modifiedAt: $modifiedAt,
            etag: $etag,
        );
    }
}
