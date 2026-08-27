<?php

declare(strict_types=1);

namespace app\application\Playlist;

use RuntimeException;

/** Hides absent, private, non-owned, and otherwise inaccessible playlists behind one failure. */
final class PlaylistNotFound extends RuntimeException
{
}
