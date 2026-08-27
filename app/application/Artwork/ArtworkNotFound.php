<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** Hides absent, unauthorized, unavailable, and syntactically invalid album artwork IDs. */
final class ArtworkNotFound extends RuntimeException
{
}
