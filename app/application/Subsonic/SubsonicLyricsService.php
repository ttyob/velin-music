<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Lyrics\LyricsQueryService;
use app\application\Media\MediaQueryService;
use JsonException;

/**
 * Adapts authorized Velin lyric versions to legacy Subsonic and OpenSubsonic songLyrics responses.
 *
 * MediaQueryService and LyricsQueryService remain the authorization boundaries: this adapter never
 * reads physical paths, provider payloads, or unscoped lyric rows. Legacy artist/title searches are
 * exact and return an empty successful lyric on no match so they cannot reveal hidden songs. The
 * ID-based endpoint distinguishes malformed input from a valid but absent/unauthorized song using
 * the controller's standard protocol errors 10 and 70.
 *
 * The service is read-only. LRC offsets are already applied when Velin parses lyrics, so responses
 * omit the optional OpenSubsonic offset instead of making clients apply it twice.
 */
final readonly class SubsonicLyricsService
{
    /** Uses shared path-free media and structured-lyrics query services. */
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private LyricsQueryService $lyrics = new LyricsQueryService(),
    ) {
    }

    /**
     * 兼容传统 artist/title 查询以及常见客户端使用歌曲 `id` 的 `getLyrics` 调用。
     *
     * ID 模式优先并只接受歌曲 ULID，随后通过 MediaQueryService 重新应用实时授权；artist/title 模式仍做
     * 精确规范化查询，原始查询词不记录、不持久化。两种模式都只展平最高优先级歌词并移除时间信息；
     * 无匹配、已撤权或无歌词返回成功空值，保持传统端点不可枚举语义。需要结构化时间轴的客户端继续
     * 使用 `getLyricsBySongId`，本方法不修改歌词文件、选择或缓存。
     *
     * @param array<string, mixed> $actor 已认证且仍需实时复验音乐库授权的账号。
     * @param array<string, mixed> $parameters 合并后的查询或表单协议参数。
     * @return array{lyrics: array{artist?: string, title?: string, value: string}}
     */
    public function legacy(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $artist = $this->optionalText($parameters['artist'] ?? null, 'Artist');
        $title = $this->optionalText($parameters['title'] ?? null, 'Title');
        $songId = $parameters['id'] ?? null;
        if ($songId !== null && $songId !== '') {
            $songId = $this->requiredId($songId);
            $song = $this->media->songsByIds($actor, [$songId])[$songId] ?? null;
        } else {
            $song = $this->media->firstSongByExactArtistAndTitle($actor, $artist, $title);
        }
        if ($song === null) {
            return ['lyrics' => array_filter([
                'artist' => $artist,
                'title' => $title,
                'value' => '',
            ], static fn (mixed $value): bool => $value !== null)];
        }

        $songId = is_string($song['id'] ?? null) ? $song['id'] : '';
        $projection = $songId === '' ? null : $this->lyrics->forSong($actor, $songId);
        $versions = is_array($projection['lyrics'] ?? null) ? $projection['lyrics'] : [];
        $primary = is_array($versions[0] ?? null) ? $versions[0] : null;

        return ['lyrics' => [
            'artist' => $this->displayArtist($song),
            'title' => is_string($song['title'] ?? null) ? $song['title'] : ($title ?? ''),
            'value' => $primary === null ? '' : $this->plainText($primary),
        ]];
    }

    /**
     * Implements OpenSubsonic `getLyricsBySongId` songLyrics versions 1 and 2.
     *
     * Version 1 is the default and returns line-level, multilingual entries. `enhanced=true` opts in
     * to version 2 fields: every current Velin track is classified as `main`, while word-level rows
     * that carry validated `words` arrays additionally map to cueLine/cue timing. Velin does not yet
     * persist translation/pronunciation relationships or vocal agents, so those optional v2 shapes
     * are never guessed. An authorized song without lyrics returns an empty list.
     *
     * @param array<string, mixed> $actor Authenticated principal with current grants.
     * @return array{lyricsList: array{structuredLyrics: list<array<string, mixed>>}}
     * @throws JsonException Persisted lyric line/cue data violates the application storage contract.
     */
    public function bySongId(array $actor, mixed $songId, mixed $enhanced = null): array
    {
        $this->requirePlay($actor);
        $songId = $this->requiredId($songId);
        $includeEnhanced = $this->optionalBoolean($enhanced);
        $songs = $this->media->songsByIds($actor, [$songId]);
        $song = $songs[$songId] ?? null;
        if (!is_array($song)) {
            throw new SubsonicEntityNotFound('Lyrics song was not found.');
        }
        $songId = is_string($song['id'] ?? null) ? $song['id'] : '';
        if ($songId === '') throw new SubsonicEntityNotFound('Lyrics song was not found.');
        $projection = $this->lyrics->forSong($actor, $songId);
        if ($projection === null) {
            throw new SubsonicEntityNotFound('Lyrics song was not found.');
        }

        $structured = [];
        foreach (is_array($projection['lyrics'] ?? null) ? $projection['lyrics'] : [] as $version) {
            if (is_array($version)) {
                $structured[] = $this->structured($song, $version, $includeEnhanced);
            }
        }

        return ['lyricsList' => ['structuredLyrics' => $structured]];
    }

    /**
     * Maps one internal version to the version-selected OpenSubsonic representation.
     *
     * @param array<string, mixed> $song Already-authorized, path-free song projection.
     * @param array<string, mixed> $version Structured lyric projection from LyricsQueryService.
     * @return array<string, mixed>
     * @throws JsonException A line is missing required storage fields or carries invalid word timing.
     */
    private function structured(array $song, array $version, bool $enhanced): array
    {
        $kind = is_string($version['kind'] ?? null) ? $version['kind'] : '';
        $synced = $kind === 'line' || $kind === 'word';
        $lines = is_array($version['lines'] ?? null) ? $version['lines'] : null;
        if ($lines === null || !array_is_list($lines)) {
            throw new JsonException('Persisted lyrics lines are not a list.');
        }

        $mappedLines = [];
        $cueLines = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line) || !is_string($line['text'] ?? null)) {
                throw new JsonException('Persisted lyrics line is invalid.');
            }
            $mapped = ['value' => $line['text']];
            if ($synced) {
                if (!is_int($line['startMs'] ?? null) || $line['startMs'] < 0) {
                    throw new JsonException('Persisted synchronized lyrics start is invalid.');
                }
                $mapped['start'] = $line['startMs'];
            }
            $mappedLines[] = $mapped;
            if ($enhanced && $kind === 'word' && array_key_exists('words', $line)) {
                $cueLines[] = $this->cueLine($line, $index);
            }
        }

        $result = [
            'displayArtist' => $this->displayArtist($song),
            'displayTitle' => is_string($song['title'] ?? null) ? $song['title'] : '',
            'lang' => is_string($version['language'] ?? null) && $version['language'] !== ''
                ? $version['language']
                : 'und',
            'synced' => $synced,
            'line' => $mappedLines,
        ];
        if ($enhanced) {
            $result['kind'] = 'main';
            if ($cueLines !== []) {
                $result['cueLine'] = $cueLines;
            }
        }

        return $result;
    }

    /**
     * Maps validated internal word timing to one self-contained enhanced cue line.
     *
     * Words use millisecond `startMs` and optional all-or-none `endMs`. UTF-8 byte offsets are
     * derived from each word's first exact occurrence at or after the previous word, so repeated
     * text remains deterministic. A word sequence that cannot be located in the final line text is
     * rejected as corrupt persisted data rather than returning offsets that point into other bytes.
     *
     * @param array<string, mixed> $line Persisted word-level line.
     * @return array<string, mixed>
     * @throws JsonException Word shape, timing, end-time consistency, or text alignment is invalid.
     */
    private function cueLine(array $line, int $index): array
    {
        $words = $line['words'];
        if (!is_array($words) || !array_is_list($words) || $words === []) {
            throw new JsonException('Persisted word lyrics are invalid.');
        }
        $value = (string) $line['text'];
        $cursor = 0;
        $hasEnd = null;
        $cues = [];
        foreach ($words as $word) {
            if (!is_array($word) || !is_string($word['text'] ?? null) || $word['text'] === ''
                || !is_int($word['startMs'] ?? null) || $word['startMs'] < 0) {
                throw new JsonException('Persisted lyric cue is invalid.');
            }
            $position = strpos($value, $word['text'], $cursor);
            if ($position === false) {
                throw new JsonException('Persisted lyric cue does not align with its line.');
            }
            $wordHasEnd = array_key_exists('endMs', $word) && $word['endMs'] !== null;
            $hasEnd ??= $wordHasEnd;
            if ($hasEnd !== $wordHasEnd || ($wordHasEnd
                && (!is_int($word['endMs']) || $word['endMs'] < $word['startMs']))) {
                throw new JsonException('Persisted lyric cue end timing is inconsistent.');
            }
            $cue = [
                'start' => $word['startMs'],
                'byteStart' => $position,
                'byteEnd' => $position + strlen($word['text']) - 1,
                'value' => $word['text'],
            ];
            if ($wordHasEnd) {
                $cue['end'] = $word['endMs'];
            }
            $cues[] = $cue;
            $cursor = $position + strlen($word['text']);
        }

        $result = ['index' => $index, 'value' => $value, 'cue' => $cues];
        if (is_int($line['startMs'] ?? null)) {
            $result['start'] = $line['startMs'];
        }
        if (is_int($line['endMs'] ?? null) && $line['endMs'] >= ($line['startMs'] ?? 0)) {
            $result['end'] = $line['endMs'];
        }

        return $result;
    }

    /** Flattens one structured version without retaining timestamps or cue metadata. */
    private function plainText(array $version): string
    {
        $lines = is_array($version['lines'] ?? null) ? $version['lines'] : [];
        $values = [];
        foreach ($lines as $line) {
            if (is_array($line) && is_string($line['text'] ?? null)) {
                $values[] = $line['text'];
            }
        }

        return implode("\n", $values);
    }

    /** Returns the stable display artist already attached to an authorized song projection. */
    private function displayArtist(array $song): string
    {
        $names = [];
        foreach (is_array($song['artists'] ?? null) ? $song['artists'] : [] as $artist) {
            if (is_array($artist) && is_string($artist['name'] ?? null) && $artist['name'] !== '') {
                $names[] = $artist['name'];
            }
        }

        return $names === [] ? 'Unknown Artist' : implode(', ', $names);
    }

    /** Accepts an absent optional legacy search field or one non-empty bounded UTF-8 string. */
    private function optionalText(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > 255) {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }

        return $value;
    }

    /** Requires one canonical Velin song ULID without coercing opaque IDs to integers. */
    private function requiredId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid('Song ID is missing or invalid.');
        }

        return $value;
    }

    /** Parses the optional version-2 opt-in without accepting truthy ambiguous strings. */
    private function optionalBoolean(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if ($value === true || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 'false') {
            return false;
        }

        throw new SubsonicRequestInvalid('Enhanced lyrics flag is invalid.');
    }

    /** Enforces global playback capability before any song or lyric lookup. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic lyrics require play capability.');
        }
    }
}
