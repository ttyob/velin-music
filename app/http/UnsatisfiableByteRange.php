<?php

declare(strict_types=1);

namespace app\http;

use RuntimeException;

/** Signals malformed, multiple, overflowing, or out-of-bounds byte ranges. */
final class UnsatisfiableByteRange extends RuntimeException
{
}
