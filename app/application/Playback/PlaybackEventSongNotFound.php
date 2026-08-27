<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** Hides missing, unavailable, and unauthorized event songs behind one public failure. */
final class PlaybackEventSongNotFound extends RuntimeException
{
}
