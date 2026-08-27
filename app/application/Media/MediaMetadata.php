<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * Immutable normalized metadata produced from one FFprobe response.
 *
 * Durations use milliseconds, bitrate uses bit/s, sample rate uses Hz, and list order preserves the
 * tag order reported by the source file. Empty source tags are replaced only for title, album, and
 * artist so every successfully probed song has a browsable identity. This object contains no path
 * and performs no persistence; MediaCatalogWriter owns stable IDs and database transactions.
 */
final readonly class MediaMetadata
{
    /**
     * @param list<string> $artists Ordered track artists, with `未知艺术家` as the final fallback.
     * @param list<string> $albumArtists Ordered album artists, falling back to track artists.
     * @param list<string> $genres De-duplicated genre labels in source order.
     * @param array<string, mixed> $rawTags Validated tag maps retained for later parser upgrades.
     */
    public function __construct(
        public string $title,
        public ?string $sortTitle,
        public array $artists,
        public array $albumArtists,
        public string $albumTitle,
        public ?string $albumSortTitle,
        public bool $hasTaggedAlbum,
        public ?int $trackNumber,
        public ?int $trackTotal,
        public ?int $discNumber,
        public ?int $discTotal,
        public array $genres,
        public ?string $releaseDate,
        public ?int $releaseYear,
        public ?string $composer,
        public ?string $comment,
        public ?float $bpm,
        public ?string $isrc,
        public ?string $musicbrainzTrackId,
        public ?string $musicbrainzArtistId,
        public ?string $musicbrainzReleaseId,
        public ?string $musicbrainzReleaseGroupId,
        public int $durationMs,
        public ?string $codecName,
        public ?string $containerName,
        public ?int $bitrate,
        public ?int $bitDepth,
        public ?int $sampleRate,
        public ?int $channels,
        public ?float $replaygainTrackGain,
        public ?float $replaygainTrackPeak,
        public ?float $replaygainAlbumGain,
        public ?float $replaygainAlbumPeak,
        public array $rawTags,
    ) {
    }
}
