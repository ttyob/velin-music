<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** Represents a name, path-overlap, state, or grant conflict mapped to HTTP 409. */
final class LibraryConflict extends RuntimeException
{
}
