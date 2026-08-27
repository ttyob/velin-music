<?php

declare(strict_types=1);

namespace app\application\Transcode;

use RuntimeException;

/** Raised when every configured cross-process FFmpeg slot is already owned. */
final class TranscodeCapacityExceeded extends RuntimeException
{
}
