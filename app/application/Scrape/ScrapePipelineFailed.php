<?php

declare(strict_types=1);

namespace app\application\Scrape;

use RuntimeException;

/** Carries a stable path-free failure code for discovery and organization state. */
final class ScrapePipelineFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
