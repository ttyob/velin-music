<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** Represents an invalid, missing, unavailable, or unauthorized catalog detail as one safe state. */
final class MediaDetailNotFound extends RuntimeException
{
}
