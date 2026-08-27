<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/** Represents an absent or deliberately undisclosed Subsonic resource using standard error 70. */
final class SubsonicEntityNotFound extends RuntimeException
{
}
