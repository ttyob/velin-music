<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** Represents one sanitized, retryable-or-file-specific media probe failure. */
final class MediaProbeFailed extends RuntimeException
{
    /** Creates a failure with a stable internal code and a path-free operator message. */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
