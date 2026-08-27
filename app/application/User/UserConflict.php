<?php

declare(strict_types=1);

namespace app\application\User;

use RuntimeException;

/** Represents a safe user-management conflict that maps to HTTP 409. */
final class UserConflict extends RuntimeException
{
}
