<?php

declare(strict_types=1);

namespace app\application\Scan;

use RuntimeException;

/** Requests lease release when Workerman is reloading or shutting down at a safe checkpoint. */
final class ScanExecutionInterrupted extends RuntimeException
{
}
