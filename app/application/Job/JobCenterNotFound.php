<?php

declare(strict_types=1);

namespace app\application\Job;

use RuntimeException;

/** Represents an unknown or currently unauthorized task across all enabled adapters. */
final class JobCenterNotFound extends RuntimeException
{
}
