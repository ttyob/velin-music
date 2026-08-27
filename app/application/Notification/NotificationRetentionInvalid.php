<?php

declare(strict_types=1);

namespace app\application\Notification;

use InvalidArgumentException;

/** Raised when an internal retention boundary receives a non-canonical UTC cutoff or batch size. */
final class NotificationRetentionInvalid extends InvalidArgumentException
{
}
