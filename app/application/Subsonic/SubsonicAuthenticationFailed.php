<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/** Represents every missing, disabled, unprovisioned, or invalid Subsonic credential uniformly. */
final class SubsonicAuthenticationFailed extends RuntimeException
{
}
