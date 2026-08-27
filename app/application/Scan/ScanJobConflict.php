<?php

declare(strict_types=1);

namespace app\application\Scan;

use RuntimeException;

/** Indicates an active job or state transition prevents the requested scan command. */
final class ScanJobConflict extends RuntimeException
{
}
