<?php

declare(strict_types=1);

namespace app\application\Preference;

use RuntimeException;

/** Indicates that expectedVersion no longer matches the user's committed preference version. */
final class UserPreferenceConflict extends RuntimeException
{
}
