<?php

declare(strict_types=1);

namespace app\application\Artwork;

use stdClass;
use support\Db;

/**
 * Indexes artist-root images and the explicit albumless-single fallback during a normal media scan.
 *
 * The expected layout is `Artist/artist.jpg` beside album directories. Reserved `artist` and
 * `artist-cover` basenames win; otherwise the newest valid direct-child image in the artist directory
 * is selected. Only when the catalog release is the system `单曲` group and the artist root contains no
 * usable image may an image beside that single become the artist image. No source image is modified.
 */
final readonly class ArtistArtworkIndexer
{
    public function __construct(private LocalArtworkCandidateFinder $finder = new LocalArtworkCandidateFinder())
    {
    }

    /** Updates every album artist connected to the scanned audio and returns whether any image exists. */
    public function index(
        string $jobId,
        string $libraryId,
        string $inventoryId,
        string $resolvedRoot,
        string $audioPath,
        string $relativeAudioPath,
    ): bool {
        /** @var stdClass|null $song */
        $song = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->where('songs.library_id', $libraryId)->where('songs.inventory_file_id', $inventoryId)
            ->first(['songs.album_id', 'albums.title as album_title']);
        if (!$song instanceof stdClass) {
            return false;
        }
        $artistIds = Db::table('media_album_artists')->where('album_id', (string) $song->album_id)
            ->orderBy('position')->pluck('artist_id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($artistIds === []) {
            return false;
        }

        [$artistDirectory, $relativeArtistDirectory] = $this->artistDirectory($audioPath, $relativeAudioPath);
        $search = $this->finder->find($artistDirectory, $resolvedRoot, $relativeArtistDirectory, [
            'artist' => 500,
            'artist-cover' => 400,
        ]);
        $levelsUp = 1;
        $sourceKind = 'artist_sidecar';
        if ($search['match'] !== null && $search['match']['priority'] === 0) {
            // Any artist-root image still outranks a single-release image.
            $search['match']['priority'] = 300;
        }
        if ($search['match'] === null && in_array((string) $song->album_title, ['单曲', '未知专辑'], true)) {
            $singleDirectory = dirname($audioPath);
            $relativeSingleDirectory = dirname(str_replace('\\', '/', $relativeAudioPath));
            $relativeSingleDirectory = $relativeSingleDirectory === '.' ? '' : trim($relativeSingleDirectory, '/');
            $search = $this->finder->find($singleDirectory, $resolvedRoot, $relativeSingleDirectory, [
                'cover' => 200,
                'folder' => 150,
                'front' => 100,
            ]);
            $levelsUp = 0;
            $sourceKind = 'single_sidecar';
        }

        $found = false;
        foreach ($artistIds as $artistId) {
            if ($search['match'] === null) {
                Db::table('media_artist_artworks')->where('artist_id', $artistId)
                    ->where('library_id', $libraryId)->where('source_inventory_file_id', $inventoryId)->delete();
                continue;
            }
            $found = true;
            $this->select(
                $jobId,
                $artistId,
                $libraryId,
                $inventoryId,
                $levelsUp,
                $sourceKind,
                $search['match'],
            );
        }

        return $found;
    }

    /** @return array{string,string} Canonical artist directory and library-relative locator. */
    private function artistDirectory(string $audioPath, string $relativeAudioPath): array
    {
        $audioDirectory = dirname($audioPath);
        $relativeAudioDirectory = dirname(str_replace('\\', '/', $relativeAudioPath));
        if ($relativeAudioDirectory === '.' || !str_contains($relativeAudioDirectory, '/')) {
            return [$audioDirectory, $relativeAudioDirectory === '.' ? '' : trim($relativeAudioDirectory, '/')];
        }

        return [dirname($audioDirectory), trim(dirname($relativeAudioDirectory), '/')];
    }

    /** Applies deterministic cross-directory selection for one library-scoped artist. */
    private function select(
        string $jobId,
        string $artistId,
        string $libraryId,
        string $inventoryId,
        int $levelsUp,
        string $sourceKind,
        array $candidate,
    ): void {
        $image = $candidate['image'];
        /** @var stdClass|null $existing */
        $existing = Db::table('media_artist_artworks as artwork')
            ->leftJoin('library_file_inventory as source', 'source.id', '=', 'artwork.source_inventory_file_id')
            ->where('artwork.artist_id', $artistId)->where('artwork.library_id', $libraryId)
            ->first(['artwork.*', 'source.last_seen_scan_job_id as source_last_seen_scan_job_id']);
        if ($existing instanceof stdClass && (string) $existing->source_inventory_file_id !== $inventoryId) {
            $sourceIsCurrent = (string) ($existing->source_last_seen_scan_job_id ?? '') === $jobId;
            $existingWins = $sourceIsCurrent && (
                (int) $existing->selection_priority > $candidate['priority']
                || ((int) $existing->selection_priority === $candidate['priority']
                    && ((int) $existing->modified_at > $image['mtime']
                        || ((int) $existing->modified_at === $image['mtime']
                            && (string) $existing->selection_key <= $candidate['selectionKey'])))
            );
            if ($existingWins) {
                return;
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $values = [
            'source_inventory_file_id' => $inventoryId,
            'source_file_name' => $candidate['fileName'],
            'directory_levels_up' => $levelsUp,
            'source_kind' => $sourceKind,
            'mime_type' => $image['mime'],
            'width' => $image['width'],
            'height' => $image['height'],
            'file_size' => $image['size'],
            'modified_at' => $image['mtime'],
            'device_id' => $image['dev'],
            'inode' => $image['ino'],
            'content_sha256' => $image['sha256'],
            'selection_priority' => $candidate['priority'],
            'selection_key' => $candidate['selectionKey'],
            'updated_at' => $now,
        ];
        if ($existing instanceof stdClass) {
            Db::table('media_artist_artworks')->where('artist_id', $artistId)
                ->where('library_id', $libraryId)->update($values);
        } else {
            Db::table('media_artist_artworks')->insert([
                'artist_id' => $artistId,
                'library_id' => $libraryId,
                'created_at' => $now,
            ] + $values);
        }
    }
}
