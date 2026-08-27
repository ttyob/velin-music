<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Media\MediaQueryService;
use app\http\RequestContext;
use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * Matches untrusted M3U locators only against the authenticated user's live playable catalog.
 *
 * The service never opens a locator, resolves DNS, sends HTTP, reads a referenced local path, or
 * trusts EXTINF metadata as identity. Stable Velin stream/song URIs are checked through
 * MediaQueryService. Path-like locators select only authorized inventory rows by basename, then rank
 * exact relative-path, longest suffix, and unique basename matches. A tie at the best rank is always
 * ambiguous; table order never selects a winner. This prevents both SSRF and cross-library guessing.
 *
 * Paths are used transiently inside this process and never returned, logged, audited, or persisted.
 * Current library grants, active library status, available files, and ready metadata are applied in
 * SQL before candidates exist. Final playlist persistence must reauthorize `songIds` to cover changes
 * between planning and the write transaction.
 */
final readonly class M3uImportService
{
    public function __construct(
        private M3uParser $parser = new M3uParser(),
        private MediaQueryService $media = new MediaQueryService(),
        private PlaylistService $playlists = new PlaylistService(),
    ) {
    }

    /**
     * Parses and resolves one upload into an immutable order and privacy-reduced report.
     *
     * @param array<string, mixed> $actor Authenticated principal with current library grants.
     * @throws PlaylistImportInvalid When the document itself violates parser constraints.
     */
    public function plan(array $actor, string $bytes): M3uImportPlan
    {
        return $this->planWithinLibrary($actor, $bytes, null);
    }

    /**
     * Resolves a scanner-registered M3U only against the library that owns that source.
     *
     * Stable Velin song locators are also confined to the source library. This prevents an automatic
     * sync file from silently pulling same-named or explicitly addressed songs from another library
     * merely because its owner happens to have access to both. Live grants are still applied normally.
     */
    public function planForLibrary(array $actor, string $bytes, string $libraryId): M3uImportPlan
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
            throw new PlaylistImportInvalid('invalid_library_id');
        }

        return $this->planWithinLibrary($actor, $bytes, $libraryId);
    }

    /** Builds the shared path-free plan, optionally confined to one registered source library. */
    private function planWithinLibrary(array $actor, string $bytes, ?string $libraryId): M3uImportPlan
    {
        $entries = $this->parser->parse($bytes);
        $locators = [];
        $stableIds = [];
        $basenames = [];
        foreach ($entries as $entry) {
            $locator = $this->classify($entry->locator);
            $locators[$entry->ordinal] = $locator;
            if ($locator['kind'] === 'stable') {
                $stableIds[] = $locator['songId'];
            } elseif ($locator['kind'] === 'path') {
                $basenames[] = $locator['basename'];
            }
        }

        $stableSongs = $this->authorizedSongs($actor, $stableIds, $libraryId);
        $pathCandidates = $this->pathCandidates($actor, array_values(array_unique($basenames)), $libraryId);
        $candidateSongs = $this->authorizedSongs($actor, array_map(
            static fn (array $candidate): string => $candidate['songId'],
            array_merge(...array_values($pathCandidates ?: [[]])),
        ), $libraryId);

        $songIds = [];
        $reportEntries = [];
        $importEntries = [];
        $counts = ['matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'unsupported' => 0];
        foreach ($entries as $entry) {
            $locator = $locators[$entry->ordinal];
            if ($locator['kind'] === 'unsupported') {
                $counts['unsupported']++;
                $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'unsupported'];
                $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'unsupported', 'reasonCode' => 'unsupported_locator'];
                continue;
            }
            if ($locator['kind'] === 'stable') {
                $song = $stableSongs[$locator['songId']] ?? null;
                if (!is_array($song)) {
                    $counts['unmatched']++;
                    $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'unmatched'];
                    $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'unmatched', 'reasonCode' => 'song_unavailable'];
                    continue;
                }
                $songIds[] = $locator['songId'];
                $counts['matched']++;
                $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'matched', 'song' => $song];
                $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'matched', 'songId' => $locator['songId']];
                continue;
            }

            $ranked = $this->rank($locator['path'], $pathCandidates[$locator['basename']] ?? []);
            if (count($ranked) === 0) {
                $counts['unmatched']++;
                $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'unmatched'];
                $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'unmatched', 'reasonCode' => 'song_unavailable'];
                continue;
            }
            if (count($ranked) > 1) {
                $counts['ambiguous']++;
                $reportEntries[] = [
                    'ordinal' => $entry->ordinal,
                    'status' => 'ambiguous',
                    'candidateCount' => min(100, count($ranked)),
                ];
                $importEntries[] = [
                    'position' => $entry->ordinal - 1,
                    'status' => 'ambiguous',
                    'candidateCount' => min(100, count($ranked)),
                    'reasonCode' => 'multiple_candidates',
                ];
                continue;
            }
            $songId = $ranked[0]['songId'];
            $song = $candidateSongs[$songId] ?? null;
            if (!is_array($song)) {
                // A grant/file status change between the candidate query and projection must be
                // indistinguishable from absence and cannot leave a now-unauthorized ID in the plan.
                $counts['unmatched']++;
                $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'unmatched'];
                $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'unmatched', 'reasonCode' => 'song_unavailable'];
                continue;
            }
            $songIds[] = $songId;
            $counts['matched']++;
            $reportEntries[] = ['ordinal' => $entry->ordinal, 'status' => 'matched', 'song' => $song];
            $importEntries[] = ['position' => $entry->ordinal - 1, 'status' => 'matched', 'songId' => $songId];
        }

        // EXTINF 标签是唯一允许持久化的 M3U 显示证据；locator 及其 basename 仍不得进入数据库。
        $importEntries = array_map(static function (array $importEntry) use ($entries): array {
            $sourceEntry = $entries[$importEntry['position']];
            return ['sourceTitle' => $sourceEntry->label] + $importEntry;
        }, $importEntries);

        return new M3uImportPlan($songIds, [
            'summary' => ['total' => count($entries)] + $counts,
            'entries' => $reportEntries,
        ], $importEntries);
    }

    /**
     * Creates one ordinary playlist from a plan under owner-scoped 24-hour request idempotency.
     *
     * The raw key is validated, domain-separated, and converted to an HMAC digest before SQLite. The
     * request digest contains only a SHA-256 content digest plus canonical metadata; neither uploaded
     * bytes nor locators enter persistence. PlaylistService reauthorizes every matched song and owns
     * the single transaction for playlist, items, report, retry row, and redacted audit.
     *
     * @param array{name: string, description: string|null, visibility: string} $metadata
     * @return array{playlist: array<string, mixed>, report: array<string, mixed>, replayed: bool}
     */
    public function import(
        array $actor,
        string $bytes,
        array $metadata,
        string $idempotencyKey,
        string $requestId,
        string $scope = 'user',
    ): array {
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 200
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw new PlaylistImportInvalid('invalid_idempotency_key');
        }
        $plan = $this->plan($actor, $bytes);
        $keyDigest = hash_hmac(
            'sha256',
            "velin-playlist-import-idempotency-v1\0" . $idempotencyKey,
            RequestContext::authenticationHashKey(),
        );
        $requestDigest = hash('sha256', json_encode([
            'format' => 'm3u-v1',
            'contentDigest' => hash('sha256', $bytes),
            'metadata' => $metadata,
            'scope' => $scope,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->playlists->createImported(
            $actor,
            $metadata,
            $plan->songIds,
            $plan->entries,
            $plan->report,
            $keyDigest,
            $requestDigest,
            $requestId,
            $scope,
        );
    }

    /**
     * Classifies a locator without dereferencing it or accepting arbitrary schemes.
     *
     * @return array{kind: 'stable', songId: string}|array{kind: 'path', path: string, basename: string}|array{kind: 'unsupported'}
     */
    private function classify(string $raw): array
    {
        if (preg_match('#^(?:velin://song/|/api/v1/streams/)([0-9A-HJKMNP-TV-Z]{26})$#', $raw, $match) === 1) {
            return ['kind' => 'stable', 'songId' => $match[1]];
        }
        // `parse_url('C:\\Music\\song.flac')` reports `C` as a scheme. A drive prefix is only
        // path evidence and remains inert; it must be classified before URL schemes so Windows M3U
        // exports can suffix-match registered library-relative paths without opening the client path.
        $windowsDrivePath = preg_match('/^[A-Za-z]:[\\\\\/]/', $raw) === 1;
        $scheme = $windowsDrivePath ? null : parse_url($raw, PHP_URL_SCHEME);
        if (is_string($scheme) && strtolower($scheme) !== 'file') {
            return ['kind' => 'unsupported'];
        }
        if (is_string($scheme)) {
            $path = parse_url($raw, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                return ['kind' => 'unsupported'];
            }
            $raw = rawurldecode($path);
        }
        $path = $this->normalizePath($raw);
        if ($path === null) {
            return ['kind' => 'unsupported'];
        }
        $segments = explode('/', $path);

        return ['kind' => 'path', 'path' => $path, 'basename' => end($segments) ?: $path];
    }

    /** Normalizes cross-platform separators/case while rejecting parent traversal and empty names. */
    private function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $segments[] = mb_strtolower($segment, 'UTF-8');
        }
        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * Reads only live, actor-authorized rows whose final path component may match an import entry.
     *
     * LIKE patterns are escaped and processed in batches to keep SQLite parameter/expression limits
     * bounded. Returned relative paths are an internal matching capability and must never cross this
     * service boundary. Future MySQL migration replaces SQLite's default ASCII-insensitive LIKE with
     * an explicit portable collation or a maintained normalized basename column.
     *
     * @param list<string> $basenames Normalized final components, at most 1,000 from the parser.
     * @return array<string, list<array{songId: string, relativePath: string}>>
     */
    private function pathCandidates(array $actor, array $basenames, ?string $libraryId = null): array
    {
        $result = [];
        foreach (array_chunk($basenames, 40) as $batch) {
            $query = Db::table('media_songs as songs')
                ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
                ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
                ->where('libraries.status', 'active')
                ->where('files.status', 'available')
                ->where('files.metadata_status', 'ready')
                ->where(function (Builder $paths) use ($batch): void {
                    foreach ($batch as $basename) {
                        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $basename);
                        $paths->orWhereRaw("LOWER(REPLACE(files.relative_path, '\\', '/')) = ?", [$basename])
                            ->orWhereRaw("LOWER(REPLACE(files.relative_path, '\\', '/')) LIKE ? ESCAPE '\\'", ['%/' . $escaped]);
                    }
                });
            if ($libraryId !== null) {
                $query->where('songs.library_id', $libraryId);
            }
            if (!($actor['isSuperAdmin'] ?? false)) {
                $query->join('library_user_grants as import_grant', function ($join) use ($actor): void {
                    $join->on('import_grant.library_id', '=', 'songs.library_id')
                        ->where('import_grant.user_id', '=', (string) $actor['id']);
                });
            }
            /** @var list<stdClass> $rows */
            $rows = $query->orderBy('songs.id')->get(['songs.id', 'files.relative_path'])->all();
            foreach ($rows as $row) {
                $relativePath = $this->normalizePath((string) $row->relative_path);
                if ($relativePath === null) {
                    continue;
                }
                $segments = explode('/', $relativePath);
                $basename = end($segments) ?: $relativePath;
                $result[$basename][] = ['songId' => (string) $row->id, 'relativePath' => $relativePath];
            }
        }

        return $result;
    }

    /**
     * Selects every candidate tied at the best path specificity, leaving tie handling to the caller.
     *
     * @param list<array{songId: string, relativePath: string}> $candidates
     * @return list<array{songId: string, relativePath: string}>
     */
    private function rank(string $path, array $candidates): array
    {
        $best = [];
        $bestRank = -1;
        foreach ($candidates as $candidate) {
            $relative = $candidate['relativePath'];
            $rank = $path === $relative ? 1_000_000 + strlen($relative)
                : (str_ends_with($path, '/' . $relative) ? 500_000 + strlen($relative) : strlen(basename($relative)));
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = [$candidate];
            } elseif ($rank === $bestRank) {
                $best[] = $candidate;
            }
        }

        return $best;
    }

    /** @param list<string> $songIds @return array<string, array<string, mixed>> */
    private function authorizedSongs(array $actor, array $songIds, ?string $libraryId = null): array
    {
        if ($libraryId !== null && $songIds !== []) {
            $songIds = Db::table('media_songs')->where('library_id', $libraryId)
                ->whereIn('id', array_values(array_unique($songIds)))->pluck('id')->map(
                    static fn (mixed $id): string => (string) $id,
                )->all();
        }
        $songs = [];
        foreach (array_chunk(array_values(array_unique($songIds)), 500) as $batch) {
            $songs += $this->media->songsByIds($actor, $batch);
        }

        return $songs;
    }
}
