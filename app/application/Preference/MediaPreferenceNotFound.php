<?php

declare(strict_types=1);

namespace app\application\Preference;

use RuntimeException;

/** Hides missing, unavailable, and unauthorized media behind one mutation failure. */
final class MediaPreferenceNotFound extends RuntimeException
{
}
