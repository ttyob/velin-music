<?php

declare(strict_types=1);

namespace app\application\Preference;

use InvalidArgumentException;

/** Indicates an unsupported media type or malformed media ID. */
final class MediaPreferenceInvalid extends InvalidArgumentException
{
}
