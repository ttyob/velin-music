<?php

declare(strict_types=1);

namespace app\application\Scan;

use RuntimeException;

/** Signals that a Worker reached a cancellation or graceful-shutdown safety point. */
final class ScanExecutionCancelled extends RuntimeException
{
}
