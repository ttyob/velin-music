<?php

declare(strict_types=1);

namespace app\application\Playlist;

use RuntimeException;

/** Indicates expectedVersion no longer matches the owner's committed playlist version. */
final class PlaylistConflict extends RuntimeException
{
}
