<?php

declare(strict_types=1);

namespace app\application\Playlist;

use RuntimeException;

/** Hides one or more missing, unavailable, or unauthorized replacement songs. */
final class PlaylistItemNotFound extends RuntimeException
{
}
