<?php

declare(strict_types=1);

namespace app\application\Playback;

use InvalidArgumentException;

/** Indicates a malformed playback event or a reused playback ID bound to another song. */
final class PlaybackEventInvalid extends InvalidArgumentException
{
}
