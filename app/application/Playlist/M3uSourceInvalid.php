<?php

declare(strict_types=1);

namespace app\application\Playlist;

use RuntimeException;

/** Reports an unavailable or identity-changed registered source without exposing its server path. */
final class M3uSourceInvalid extends RuntimeException
{
}
