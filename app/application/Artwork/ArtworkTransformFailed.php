<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** Indicates a bounded, authorized artwork transformation could not produce a safe cache file. */
final class ArtworkTransformFailed extends RuntimeException
{
}
