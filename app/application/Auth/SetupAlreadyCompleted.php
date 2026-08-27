<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** Signals that the one-time setup transaction has already been permanently closed. */
final class SetupAlreadyCompleted extends RuntimeException
{
}
