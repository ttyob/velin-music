<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/** Represents malformed required protocol parameters without retaining their submitted values. */
final class SubsonicRequestInvalid extends RuntimeException
{
}
