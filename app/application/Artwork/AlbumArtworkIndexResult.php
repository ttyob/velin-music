<?php

declare(strict_types=1);

namespace app\application\Artwork;

/** Path-free result recorded for one audio file's sibling artwork inspection. */
final readonly class AlbumArtworkIndexResult
{
    /** @param 'not_found'|'indexed'|'unchanged'|'invalid' $status */
    public function __construct(public string $status)
    {
    }
}
