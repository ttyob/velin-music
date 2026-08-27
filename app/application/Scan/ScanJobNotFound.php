<?php

declare(strict_types=1);

namespace app\application\Scan;

use RuntimeException;

/** Hides missing and out-of-scope jobs behind the same object-not-found boundary. */
final class ScanJobNotFound extends RuntimeException
{
}
