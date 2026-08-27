<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Carries bounded bytes and digest read from one revalidated registered M3U source.
 *
 * No physical or relative path is retained, which makes accidental API/log serialization harmless.
 */
final readonly class M3uSourceFile
{
    public function __construct(public string $bytes, public string $digest)
    {
    }
}
