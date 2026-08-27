<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** Reports a path-free operational reason after an authorized artwork fails runtime validation. */
final class ArtworkUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Authorized artwork is temporarily unavailable.');
    }
}
