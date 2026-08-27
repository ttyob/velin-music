<?php

declare(strict_types=1);

namespace app\application\Bookmark;

use RuntimeException;

/** Represents malformed bookmark IDs, positions, comments, versions, or pagination inputs. */
final class BookmarkInvalid extends RuntimeException
{
}
