<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** Signals a safe, user-correctable root-directory validation failure. */
final class LibraryPathInvalid extends RuntimeException
{
}
