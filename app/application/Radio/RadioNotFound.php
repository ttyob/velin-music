<?php

declare(strict_types=1);

namespace app\application\Radio;

use RuntimeException;

/** Merges absent and inaccessible/disabled station identity at the application boundary. */
final class RadioNotFound extends RuntimeException
{
}
