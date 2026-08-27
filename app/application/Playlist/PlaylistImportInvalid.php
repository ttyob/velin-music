<?php

declare(strict_types=1);

namespace app\application\Playlist;

use RuntimeException;
use Throwable;

/**
 * Reports a safe M3U upload/encoding/structure failure before playlist persistence starts.
 *
 * The reason code is stable for controller mapping and tests; the exception message is diagnostic
 * only and must not include uploaded lines, client paths, song IDs, or server paths. Invalid imports
 * create no playlist, idempotency row, audit entry, task, or file-system side effect.
 */
final class PlaylistImportInvalid extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'Playlist import is invalid.',
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }
}
