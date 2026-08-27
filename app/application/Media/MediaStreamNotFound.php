<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** Hides invalid, unknown, unavailable-to-scope, and ungranted song IDs behind one result. */
final class MediaStreamNotFound extends RuntimeException
{
}
