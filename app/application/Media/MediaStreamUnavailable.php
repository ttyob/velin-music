<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** Reports a safe operational reason after an authorized inventory row fails runtime validation. */
final class MediaStreamUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Authorized media is temporarily unavailable.');
    }
}
