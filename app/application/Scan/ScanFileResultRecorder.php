<?php

declare(strict_types=1);

namespace app\application\Scan;

use app\application\Artwork\AlbumArtworkIndexResult;
use app\application\Lyrics\EmbeddedLyricsExportResult;
use JsonException;
use stdClass;
use support\Db;

/**
 * Captures one path-free recognition snapshot for an administrator's historic scan report.
 *
 * Live catalog tables remain the authority for playback, while this projection deliberately copies
 * only bounded display fields. That separation prevents a later retag or scan from rewriting what
 * an older task reported. Raw tags, directory names, absolute paths, and lyric text never enter this
 * table. Re-recording the same file during a reclaimed job attempt replaces the earlier attempt row.
 */
final class ScanFileResultRecorder
{
    /**
     * Persists one successful/unchanged result after catalog and lyric reconciliation completes.
     *
     * @param 'unchanged'|'indexed' $resultKind
     * @throws JsonException When trusted normalized arrays cannot be encoded.
     */
    public function recordSuccess(
        string $jobId,
        string $inventoryId,
        string $relativePath,
        string $resultKind,
        bool $metadataParsed,
        bool $metadataUpdated,
        EmbeddedLyricsExportResult $export,
        AlbumArtworkIndexResult $artwork,
    ): void {
        $this->persist(
            $jobId,
            $inventoryId,
            $relativePath,
            $resultKind,
            $metadataParsed,
            $metadataUpdated,
            $export,
            $artwork,
            null,
            null,
        );
    }

    /** Persists a sanitized probe failure while retaining any previously known display identity. */
    public function recordFailure(
        string $jobId,
        string $inventoryId,
        string $relativePath,
        string $errorCode,
        string $errorMessage,
    ): void {
        $this->persist(
            $jobId,
            $inventoryId,
            $relativePath,
            'failed',
            false,
            false,
            new EmbeddedLyricsExportResult(false, 'not_found'),
            new AlbumArtworkIndexResult('not_found'),
            $errorCode,
            $errorMessage,
        );
    }

    /**
     * Builds and upserts the normalized snapshot in one short transaction.
     *
     * Artists and lyric facets are selected in deterministic order. The basename is stripped of
     * control characters and capped so malformed media names cannot disrupt the administration UI.
     *
     * @throws JsonException
     */
    private function persist(
        string $jobId,
        string $inventoryId,
        string $relativePath,
        string $resultKind,
        bool $metadataParsed,
        bool $metadataUpdated,
        EmbeddedLyricsExportResult $export,
        AlbumArtworkIndexResult $artwork,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        /** @var stdClass|null $song */
        $song = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->where('songs.inventory_file_id', $inventoryId)
            ->first([
                'songs.id', 'songs.title', 'songs.duration_ms', 'songs.codec_name',
                'songs.sample_rate', 'songs.bitrate', 'albums.title as album_title',
            ]);
        $artists = [];
        $lyricsCount = 0;
        $languages = [];
        $kinds = [];
        if ($song instanceof stdClass) {
            /** @var list<stdClass> $artistRows */
            $artistRows = Db::table('media_song_artists as links')
                ->join('media_artists as artists', 'artists.id', '=', 'links.artist_id')
                ->where('links.song_id', (string) $song->id)
                ->orderBy('links.position')
                ->get(['artists.name'])->all();
            $artists = array_map(static fn (stdClass $row): string => (string) $row->name, $artistRows);

            /** @var list<stdClass> $lyricRows */
            $lyricRows = Db::table('media_lyrics')
                ->where('song_id', (string) $song->id)
                ->orderByDesc('priority')
                ->get(['language', 'lyric_kind'])->all();
            $lyricsCount = count($lyricRows);
            foreach ($lyricRows as $lyric) {
                $language = (string) $lyric->language;
                $kind = (string) $lyric->lyric_kind;
                if (!in_array($language, $languages, true)) {
                    $languages[] = $language;
                }
                if (!in_array($kind, $kinds, true)) {
                    $kinds[] = $kind;
                }
            }
        }

        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $relativePath))) ?? '';
        if ($fileName === '') {
            $fileName = '未知文件';
        }
        $fileName = function_exists('mb_substr') ? mb_substr($fileName, 0, 255, 'UTF-8') : substr($fileName, 0, 255);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $values = [
            'file_name' => $fileName,
            'result_kind' => $resultKind,
            'metadata_parsed' => $metadataParsed ? 1 : 0,
            'metadata_updated' => $metadataUpdated ? 1 : 0,
            'song_id' => $song instanceof stdClass ? (string) $song->id : null,
            'title' => $song instanceof stdClass ? (string) $song->title : null,
            'artists_json' => json_encode($artists, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'album_title' => $song instanceof stdClass ? (string) $song->album_title : null,
            'duration_ms' => $song instanceof stdClass ? (int) $song->duration_ms : null,
            'codec_name' => $song instanceof stdClass && $song->codec_name !== null ? (string) $song->codec_name : null,
            'sample_rate' => $song instanceof stdClass && $song->sample_rate !== null ? (int) $song->sample_rate : null,
            'bitrate' => $song instanceof stdClass && $song->bitrate !== null ? (int) $song->bitrate : null,
            'lyrics_count' => $lyricsCount,
            'lyric_languages_json' => json_encode($languages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'lyric_kinds_json' => json_encode($kinds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'embedded_lyrics_found' => $export->found ? 1 : 0,
            'lyrics_export_status' => $export->status,
            'artwork_status' => $artwork->status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'updated_at' => $now,
        ];

        Db::transaction(function () use ($inventoryId, $jobId, $now, $values): void {
            $exists = Db::table('library_scan_file_results')
                ->where('scan_job_id', $jobId)
                ->where('inventory_file_id', $inventoryId)
                ->exists();
            if ($exists) {
                Db::table('library_scan_file_results')
                    ->where('scan_job_id', $jobId)
                    ->where('inventory_file_id', $inventoryId)
                    ->update($values);
                return;
            }
            Db::table('library_scan_file_results')->insert([
                'scan_job_id' => $jobId,
                'inventory_file_id' => $inventoryId,
                'created_at' => $now,
            ] + $values);
        });
    }
}
