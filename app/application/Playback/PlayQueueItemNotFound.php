<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** Hides whether a submitted song is absent, unavailable, or outside current library grants. */
final class PlayQueueItemNotFound extends RuntimeException
{
}
