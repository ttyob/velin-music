<?php

declare(strict_types=1);

namespace app\application\Notification;

use RuntimeException;

/** Hides absent, expired, and another user's notification behind one non-enumerable result. */
final class NotificationNotFound extends RuntimeException
{
}
