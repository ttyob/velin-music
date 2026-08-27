<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use RuntimeException;

/** Represents a bounded, path-free lyrics decoding or syntax failure safe for scan diagnostics. */
final class LyricsParseFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
