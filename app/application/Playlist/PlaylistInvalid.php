<?php

declare(strict_types=1);

namespace app\application\Playlist;

use InvalidArgumentException;

/**
 * Indicates malformed playlist metadata, IDs, versions, item commands, or constrained rule input.
 *
 * Specialized validation failures may extend this boundary so HTTP and Subsonic adapters can retain
 * one non-enumerating 422/error-10 mapping while still distinguishing import, smart-rule, and future
 * playlist formats internally. It carries no raw payload, filesystem path, or database diagnostic.
 */
class PlaylistInvalid extends InvalidArgumentException
{
}
