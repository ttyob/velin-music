<?php

declare(strict_types=1);

namespace app\application\Job;

use RuntimeException;

/** Indicates an unsupported unified task type, status, identifier, or pagination value. */
final class JobCenterInvalid extends RuntimeException
{
}
