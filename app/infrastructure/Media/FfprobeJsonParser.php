<?php

declare(strict_types=1);

namespace app\infrastructure\Media;

use app\application\Media\MediaMetadata;
use app\application\Media\MediaProbeFailed;
use JsonException;

/**
 * Converts FFprobe JSON into Velin Music's normalized, path-free metadata contract.
 *
 * Tag keys are matched case-insensitively with punctuation normalized because containers expose the
 * same logical fields under different spellings. Unknown tags stay in the raw snapshot. The parser
 * does not infer data from directories; only title receives the caller-provided filename fallback.
 */
final class FfprobeJsonParser
{
    public const PARSER_VERSION = 1;

    /**
     * Parses bounded UTF-8 JSON produced by the configured FFprobe command.
     *
     * @throws MediaProbeFailed When JSON shape is invalid or no audio stream is present.
     */
    public function parse(string $json, string $fallbackTitle): MediaMetadata
    {
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MediaProbeFailed('FFPROBE_INVALID_JSON', '媒体探测器返回了无效数据。');
        }
        if (!is_array($payload)) {
            throw new MediaProbeFailed('FFPROBE_INVALID_JSON', '媒体探测器返回了无效数据。');
        }

        $format = is_array($payload['format'] ?? null) ? $payload['format'] : [];
        $audioStream = $this->firstAudioStream($payload['streams'] ?? null);
        $formatTags = $this->stringTags($format['tags'] ?? null);
        $streamTags = $this->stringTags($audioStream['tags'] ?? null);
        // Format tags win because FFprobe presents container-level canonical tags there for most files.
        $tags = array_replace($streamTags, $formatTags);

        [$trackNumber, $trackTotal] = $this->fraction($this->tag($tags, 'track', 'tracknumber'));
        [$discNumber, $discTotal] = $this->fraction($this->tag($tags, 'disc', 'discnumber'));
        $releaseDate = $this->nullable($this->tag($tags, 'date', 'year', 'originaldate', 'original_year'));
        $year = $releaseDate !== null && preg_match('/(?<!\d)(\d{4})(?!\d)/', $releaseDate, $match) === 1
            ? (int) $match[1]
            : null;
        $artists = $this->listTag($this->tag($tags, 'artist'));
        if ($artists === []) {
            $artists = ['未知艺术家'];
        }
        $albumArtists = $this->listTag($this->tag($tags, 'album_artist', 'albumartist'));
        if ($albumArtists === []) {
            $albumArtists = $artists;
        }
        $albumTag = $this->nullable($this->tag($tags, 'album'));

        return new MediaMetadata(
            title: $this->nullable($this->tag($tags, 'title')) ?? $this->safeFallback($fallbackTitle),
            sortTitle: $this->nullable($this->tag($tags, 'titlesort', 'title_sort', 'sort_title')),
            artists: $artists,
            albumArtists: $albumArtists,
            albumTitle: $albumTag ?? '单曲',
            albumSortTitle: $this->nullable($this->tag($tags, 'albumsort', 'album_sort', 'sort_album')),
            hasTaggedAlbum: $albumTag !== null,
            trackNumber: $trackNumber,
            trackTotal: $trackTotal ?? $this->positiveInt($this->tag($tags, 'tracktotal', 'totaltracks')),
            discNumber: $discNumber,
            discTotal: $discTotal ?? $this->positiveInt($this->tag($tags, 'disctotal', 'totaldiscs')),
            genres: $this->listTag($this->tag($tags, 'genre')),
            releaseDate: $releaseDate,
            releaseYear: $year,
            composer: $this->nullable($this->tag($tags, 'composer')),
            comment: $this->nullable($this->tag($tags, 'comment', 'description')),
            bpm: $this->floatValue($this->tag($tags, 'bpm', 'tbpm')),
            isrc: $this->nullable($this->tag($tags, 'isrc')),
            musicbrainzTrackId: $this->nullable($this->tag($tags, 'musicbrainz_trackid', 'musicbrainz_recordingid')),
            musicbrainzArtistId: $this->nullable($this->tag($tags, 'musicbrainz_artistid')),
            musicbrainzReleaseId: $this->nullable($this->tag($tags, 'musicbrainz_albumid', 'musicbrainz_releaseid')),
            musicbrainzReleaseGroupId: $this->nullable($this->tag($tags, 'musicbrainz_releasegroupid')),
            durationMs: max(0, (int) round($this->numeric($format['duration'] ?? null) * 1000)),
            codecName: $this->nullable($audioStream['codec_name'] ?? null),
            containerName: $this->nullable($format['format_name'] ?? null),
            bitrate: $this->nonNegativeInt($format['bit_rate'] ?? $audioStream['bit_rate'] ?? null),
            bitDepth: $this->nonNegativeInt($audioStream['bits_per_raw_sample'] ?? $audioStream['bits_per_sample'] ?? null),
            sampleRate: $this->nonNegativeInt($audioStream['sample_rate'] ?? null),
            channels: $this->nonNegativeInt($audioStream['channels'] ?? null),
            replaygainTrackGain: $this->gain($this->tag($tags, 'replaygain_track_gain')),
            replaygainTrackPeak: $this->floatValue($this->tag($tags, 'replaygain_track_peak')),
            replaygainAlbumGain: $this->gain($this->tag($tags, 'replaygain_album_gain')),
            replaygainAlbumPeak: $this->floatValue($this->tag($tags, 'replaygain_album_peak')),
            rawTags: ['format' => $formatTags, 'audioStream' => $streamTags],
        );
    }

    /** @return array<string, mixed> */
    private function firstAudioStream(mixed $streams): array
    {
        if (is_array($streams)) {
            foreach ($streams as $stream) {
                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'audio') {
                    return $stream;
                }
            }
        }

        throw new MediaProbeFailed('FFPROBE_NO_AUDIO_STREAM', '文件中没有可识别的音频流。');
    }

    /** @return array<string, string> */
    private function stringTags(mixed $source): array
    {
        if (!is_array($source)) {
            return [];
        }
        $tags = [];
        foreach ($source as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $normalizedKey = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($key), -1));
                $tags[trim($normalizedKey, '_')] = trim((string) $value);
            }
        }

        return $tags;
    }

    private function tag(array $tags, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $tags)) {
                return $tags[$key];
            }
        }

        return null;
    }

    /** @return array{?int, ?int} */
    private function fraction(?string $value): array
    {
        if ($value === null || preg_match('/^\s*(\d+)(?:\s*\/\s*(\d+))?/', $value, $match) !== 1) {
            return [null, null];
        }

        return [(int) $match[1], isset($match[2]) ? (int) $match[2] : null];
    }

    /** @return list<string> */
    private function listTag(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $items = preg_split('/\x00|\s*;\s*|\s+\/\s+/', $value) ?: [];
        $result = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function nullable(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function safeFallback(string $fallback): string
    {
        $fallback = trim($fallback);

        return $fallback === '' ? '未知曲目' : $fallback;
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function positiveInt(mixed $value): ?int
    {
        $parsed = $this->nonNegativeInt($value);

        return $parsed !== null && $parsed > 0 ? $parsed : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value >= 0 ? (int) $value : null;
    }

    private function floatValue(mixed $value): ?float
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        if (preg_match('/[-+]?\d+(?:\.\d+)?/', (string) $value, $match) !== 1) {
            return null;
        }

        return (float) $match[0];
    }

    private function gain(?string $value): ?float
    {
        return $this->floatValue($value);
    }
}
