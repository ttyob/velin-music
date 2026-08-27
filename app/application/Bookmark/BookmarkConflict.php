<?php

declare(strict_types=1);

namespace app\application\Bookmark;

use RuntimeException;

/** Signals that a Web bookmark command was based on a stale create/update/delete version. */
final class BookmarkConflict extends RuntimeException
{
}
