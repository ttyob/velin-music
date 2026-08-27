<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Parses a bounded M3U/M3U8 upload without resolving or opening any referenced locator.
 *
 * The parser accepts UTF-8 (with optional BOM), BOM-marked UTF-16 LE/BE, and a strict best-effort
 * legacy GB18030/Windows-1252 conversion for `.m3u`. It never performs network requests, filesystem
 * lookups, environment expansion, or URL decoding. Consumers must treat locators as untrusted text
 * and apply current-user catalog authorization before matching. Blank/comment lines are ignored;
 * EXTINF labels are attached only to the immediately following media line.
 *
 * Limits protect a synchronous Web request: at most 1 MiB, 5,000 logical lines, 1,000 media entries,
 * 4,096 bytes per locator, and 300 Unicode characters per display label. Failure returns no partial
 * document, allowing callers to keep playlist creation atomic.
 */
final class M3uParser
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_LINES = 5_000;
    public const MAX_ENTRIES = 1_000;

    /**
     * Converts and parses one complete upload held in memory under MAX_BYTES.
     *
     * @return list<M3uEntry> Ordered entries including intentional duplicates.
     * @throws PlaylistImportInvalid For size, encoding, control-character, or structural violations.
     */
    public function parse(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new PlaylistImportInvalid('invalid_size');
        }
        $text = $this->decode($bytes);
        if (str_contains($text, "\0")) {
            throw new PlaylistImportInvalid('invalid_control_character');
        }
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if (!is_array($lines) || count($lines) > self::MAX_LINES) {
            throw new PlaylistImportInvalid('too_many_lines');
        }

        $entries = [];
        $pendingLabel = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $pendingLabel = $this->label($line);
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (strlen($line) > 4_096 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line) === 1) {
                throw new PlaylistImportInvalid('invalid_locator');
            }
            if (count($entries) >= self::MAX_ENTRIES) {
                throw new PlaylistImportInvalid('too_many_entries');
            }
            $entries[] = new M3uEntry(count($entries) + 1, $line, $pendingLabel);
            $pendingLabel = null;
        }
        if ($entries === []) {
            throw new PlaylistImportInvalid('empty_playlist');
        }

        return $entries;
    }

    /** Converts recognized byte encodings to validated UTF-8 without lossy replacement. */
    private function decode(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } elseif (!mb_check_encoding($bytes, 'UTF-8')) {
            $encoding = mb_detect_encoding($bytes, ['GB18030', 'Windows-1252'], true);
            if ($encoding === false) {
                throw new PlaylistImportInvalid('invalid_encoding');
            }
            $bytes = mb_convert_encoding($bytes, 'UTF-8', $encoding);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            throw new PlaylistImportInvalid('invalid_encoding');
        }

        return $bytes;
    }

    /** Extracts and bounds an optional EXTINF display label without retaining duration metadata. */
    private function label(string $line): ?string
    {
        $comma = strpos($line, ',');
        if ($comma === false) {
            return null;
        }
        $label = trim(substr($line, $comma + 1));
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label, 'UTF-8') > 300
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $label) === 1) {
            throw new PlaylistImportInvalid('invalid_label');
        }

        return $label;
    }
}
