<?php

declare(strict_types=1);

namespace app\application\Scrape;

use RuntimeException;

/** Indicates a corrupt or unsupported internal scrape metadata snapshot. */
final class ScrapeMetadataInvalid extends RuntimeException
{
}
