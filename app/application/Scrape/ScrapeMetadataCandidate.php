<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\application\Media\MediaMetadata;
use JsonException;

/**
 * Carries one path-free metadata decision between probe, provider, preview, and organization phases.
 *
 * Confidence is an explainable admission score, not a probability. Values below 85 must not trigger
 * automatic publication. Evidence contains short machine keys only and is safe for administration
 * projections; provider bodies and physical paths never enter this object.
 */
final readonly class ScrapeMetadataCandidate
{
    /**
     * @param array<string, mixed> $metadata Validated normalized descriptive fields.
     * @param list<string> $evidence Stable evidence keys used to explain the score.
     */
    public function __construct(
        public array $metadata,
        public int $confidence,
        public string $source,
        public array $evidence,
    ) {
    }

    /** Builds the scrape projection while excluding codec details and raw source tags. */
    public static function fromMediaMetadata(MediaMetadata $metadata, int $confidence, string $source, array $evidence): self
    {
        return new self([
            'title' => $metadata->title,
            'artists' => $metadata->artists,
            'albumArtists' => $metadata->albumArtists,
            'albumTitle' => $metadata->albumTitle,
            'trackNumber' => $metadata->trackNumber,
            'trackTotal' => $metadata->trackTotal,
            'discNumber' => $metadata->discNumber,
            'discTotal' => $metadata->discTotal,
            'genres' => $metadata->genres,
            'releaseDate' => $metadata->releaseDate,
            'releaseYear' => $metadata->releaseYear,
            'composer' => $metadata->composer,
            'isrc' => $metadata->isrc,
            'musicbrainzTrackId' => $metadata->musicbrainzTrackId,
            'musicbrainzArtistId' => $metadata->musicbrainzArtistId,
            'musicbrainzReleaseId' => $metadata->musicbrainzReleaseId,
            'musicbrainzReleaseGroupId' => $metadata->musicbrainzReleaseGroupId,
            'durationMs' => $metadata->durationMs,
        ], max(0, min(100, $confidence)), $source, array_values(array_unique($evidence)));
    }

    /** Encodes a bounded immutable snapshot for durable jobs and managed-result overlays. */
    public function toJson(): string
    {
        return json_encode([
            'version' => 1,
            'metadata' => $this->metadata,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'evidence' => $this->evidence,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Restores only snapshots created by toJson; malformed historic data fails closed.
     *
     * @throws JsonException When JSON is invalid.
     * @throws ScrapeMetadataInvalid When required fields or bounds are invalid.
     */
    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['version'] ?? null) !== 1
            || !is_array($payload['metadata'] ?? null)
            || !is_int($payload['confidence'] ?? null)
            || !is_string($payload['source'] ?? null)
            || !is_array($payload['evidence'] ?? null)
        ) {
            throw new ScrapeMetadataInvalid('刮削元数据快照无效。');
        }
        $metadata = $payload['metadata'];
        if (!is_string($metadata['title'] ?? null) || trim($metadata['title']) === ''
            || !is_string($metadata['albumTitle'] ?? null) || trim($metadata['albumTitle']) === ''
            || !self::stringList($metadata['artists'] ?? null)
            || !self::stringList($metadata['albumArtists'] ?? null)
            || $payload['confidence'] < 0 || $payload['confidence'] > 100
        ) {
            throw new ScrapeMetadataInvalid('刮削元数据快照缺少必要字段。');
        }
        $evidence = array_values(array_filter(
            $payload['evidence'],
            static fn (mixed $value): bool => is_string($value) && $value !== '' && strlen($value) <= 80,
        ));

        return new self($metadata, $payload['confidence'], $payload['source'], $evidence);
    }

    /** @return bool True only for a non-empty bounded string list. */
    private static function stringList(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || count($value) > 32) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '' || strlen($item) > 1024) {
                return false;
            }
        }

        return true;
    }
}
