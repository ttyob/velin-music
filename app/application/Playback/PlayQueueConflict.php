<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** Indicates that expectedVersion no longer matches the user's persisted queue version. */
final class PlayQueueConflict extends RuntimeException
{
}
