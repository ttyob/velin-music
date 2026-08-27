<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Serializes one currently readable playlist as a path-free UTF-8 extended M3U document.
 *
 * PlaylistService reapplies header visibility and per-song live library authorization first. Exported
 * entries use root-relative authenticated Velin stream URLs, never media paths, public-share tokens,
 * Session IDs, Subsonic credentials, or an origin derived from Host headers. Consumers outside the
 * authenticated Web origin may need to re-import the file rather than play it directly. Reads do not
 * change the playlist version, access statistics, playback history, or filesystem.
 */
final readonly class M3uExportService
{
    public function __construct(private PlaylistService $playlists = new PlaylistService())
    {
    }

    /**
     * Returns safe response metadata and a complete LF-terminated M3U8 body.
     *
     * @param array<string, mixed> $actor Authenticated reader; server-visible foreign lists are allowed.
     * @return array{filename: string, content: string}
     * @throws PlaylistNotFound When the header is private/absent or no longer readable.
     */
    public function export(array $actor, string $playlistId): array
    {
        $playlist = $this->playlists->detail($actor, $playlistId);
        $lines = ['#EXTM3U', '#PLAYLIST:' . $this->singleLine((string) $playlist['name'])];
        foreach ($playlist['songs'] as $item) {
            $song = $item['song'];
            $artists = array_values(array_filter(array_map(
                static fn (array $artist): string => trim((string) ($artist['name'] ?? '')),
                is_array($song['artists'] ?? null) ? $song['artists'] : [],
            )));
            $artist = $artists === [] ? 'Unknown Artist' : implode(', ', $artists);
            $label = $this->singleLine($artist . ' - ' . (string) $song['title']);
            $lines[] = '#EXTINF:' . max(0, intdiv((int) $song['durationMs'], 1000)) . ',' . $label;
            $lines[] = '/api/v1/streams/' . rawurlencode((string) $song['id']);
        }

        return [
            'filename' => $this->filename((string) $playlist['name']),
            'content' => implode("\n", $lines) . "\n",
        ];
    }

    /** Removes line/control injection while retaining a useful bounded Unicode label. */
    private function singleLine(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if (mb_strlen($value, 'UTF-8') > 300) {
            $value = mb_substr($value, 0, 300, 'UTF-8');
        }

        return $value === '' ? 'Untitled' : $value;
    }

    /** Produces a Unicode download name later encoded with RFC 5987 by the controller. */
    private function filename(string $name): string
    {
        $name = $this->singleLine($name);
        $name = trim((string) preg_replace('~[\\/:*?"<>|]+~u', '_', $name), ' ._');
        if ($name === '') {
            $name = 'playlist';
        }
        if (mb_strlen($name, 'UTF-8') > 100) {
            $name = mb_substr($name, 0, 100, 'UTF-8');
        }

        return $name . '.m3u8';
    }
}
