<?php

declare(strict_types=1);

namespace app\application\Preference;

use RuntimeException;

/** Indicates that a self-service user preference command failed closed vocabulary validation. */
final class UserPreferenceInvalid extends RuntimeException
{
}
