<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/** Represents an authenticated principal lacking a required Subsonic capability (error 50). */
final class SubsonicAuthorizationDenied extends RuntimeException
{
}
