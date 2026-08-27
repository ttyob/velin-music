<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** Signals that no currently valid revocable Web session exists for an operation. */
final class AuthenticationRequired extends RuntimeException
{
}
