<?php

declare(strict_types=1);

namespace app\application\Bookmark;

use RuntimeException;

/** Hides absent, unavailable, and unauthorized bookmark songs behind one object boundary. */
final class BookmarkNotFound extends RuntimeException
{
}
