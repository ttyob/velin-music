<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** Signals that a library is absent or outside the actor's management scope. */
final class LibraryNotFound extends RuntimeException
{
}
