<?php

declare(strict_types=1);

namespace app\application\Notification;

use RuntimeException;

/** Indicates that a notification filter, identifier, or preference command is outside its allowlist. */
final class NotificationInvalid extends RuntimeException
{
}
