<?php

declare(strict_types=1);

namespace app\application\Realtime;

use InvalidArgumentException;

/** Raised when a replay cursor is malformed or outside the supported integer sequence domain. */
final class RealtimeEventInvalid extends InvalidArgumentException
{
}
