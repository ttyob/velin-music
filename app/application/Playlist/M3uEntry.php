<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Represents one ordered, untrusted locator parsed from an M3U/M3U8 document.
 *
 * `ordinal` counts media entries rather than physical lines so reports remain useful without echoing
 * uploaded paths. `label` comes only from the nearest preceding EXTINF and is display evidence, never
 * an authorization key. `locator` remains internal to matching and must not enter API responses,
 * logs, audit metadata, or database reports because it may contain a user's local directory layout.
 */
final readonly class M3uEntry
{
    public function __construct(
        public int $ordinal,
        public string $locator,
        public ?string $label,
    ) {
    }
}
