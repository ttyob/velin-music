<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** Signals that an authenticated principal lacks a required capability or object boundary. */
final class AuthorizationDenied extends RuntimeException
{
}
