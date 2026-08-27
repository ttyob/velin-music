<?php

declare(strict_types=1);

namespace app\application\User;

use RuntimeException;

/** Signals that the target user cannot be resolved within the management operation. */
final class UserNotFound extends RuntimeException
{
}
