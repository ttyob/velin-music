<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use Throwable;

/**
 * Materializes a validated embedded lyric tag as a non-overwriting sibling `.lrc` file.
 *
 * This narrow writer is used only by the scan Worker. It first confirms the audio's canonical path
 * remains inside the immutable library root, then parses and normalizes bounded tag text. Content is
 * written and fsynced to an exclusive temporary file in the same directory. A hard-link publish is
 * used as an atomic no-replace operation: unlike rename(), it can never overwrite a target created
 * by another process between the existence check and publication. The temporary link is removed in
 * every exit path. No shell command is used and no caller-controlled filename is constructed.
 */
final class EmbeddedLyricsExporter
{
    public function __construct(private readonly LyricsParser $parser = new LyricsParser())
    {
    }

    /**
     * Finds a known lyric tag and exports it to the audio basename plus `.lrc` when policy permits.
     *
     * @param array<string, mixed> $rawTags Validated FFprobe tag snapshot with format/audioStream maps.
     */
    public function export(array $rawTags, string $audioPath, string $resolvedRoot): EmbeddedLyricsExportResult
    {
        $embedded = $this->findEmbeddedText($rawTags);
        if ($embedded === null) {
            return new EmbeddedLyricsExportResult(false, 'not_found');
        }
        try {
            $parsed = $this->parser->parse($embedded);
        } catch (LyricsParseFailed) {
            return new EmbeddedLyricsExportResult(true, 'invalid');
        }
        if (!$this->enabled()) {
            return new EmbeddedLyricsExportResult(true, 'disabled', $parsed->kind);
        }

        $canonicalAudio = realpath($audioPath);
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($canonicalAudio === false
            || !str_starts_with($canonicalAudio, $rootPrefix)
            || !is_file($canonicalAudio)
            || !is_readable($canonicalAudio)
        ) {
            return new EmbeddedLyricsExportResult(true, 'failed', $parsed->kind);
        }
        $audioStat = @stat($canonicalAudio);
        $directory = dirname($canonicalAudio);
        if (!is_array($audioStat) || !is_dir($directory) || !is_writable($directory)) {
            return new EmbeddedLyricsExportResult(true, 'not_writable', $parsed->kind);
        }

        $target = $directory . DIRECTORY_SEPARATOR . pathinfo($canonicalAudio, PATHINFO_FILENAME) . '.lrc';
        if (file_exists($target) || is_link($target)) {
            return new EmbeddedLyricsExportResult(true, 'already_exists', $parsed->kind);
        }
        $temporary = '';
        $handle = null;
        try {
            $temporary = $directory . DIRECTORY_SEPARATOR . '.velin-lyrics-' . bin2hex(random_bytes(12)) . '.tmp';
            $handle = @fopen($temporary, 'x+b');
            if (!is_resource($handle)) {
                return new EmbeddedLyricsExportResult(true, 'not_writable', $parsed->kind);
            }
            $content = $this->serialize($parsed);
            $offset = 0;
            while ($offset < strlen($content)) {
                $written = fwrite($handle, substr($content, $offset));
                if (!is_int($written) || $written <= 0) {
                    return new EmbeddedLyricsExportResult(true, 'failed', $parsed->kind);
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                return new EmbeddedLyricsExportResult(true, 'failed', $parsed->kind);
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0644);

            // Reject an audio replacement that occurred while the derived file was being written.
            $afterStat = @stat($canonicalAudio);
            if (!$this->sameFile($audioStat, $afterStat) || realpath($canonicalAudio) !== $canonicalAudio) {
                return new EmbeddedLyricsExportResult(true, 'failed', $parsed->kind);
            }
            if (!@link($temporary, $target)) {
                return new EmbeddedLyricsExportResult(
                    true,
                    file_exists($target) || is_link($target) ? 'already_exists' : 'failed',
                    $parsed->kind,
                );
            }

            return new EmbeddedLyricsExportResult(true, 'created', $parsed->kind);
        } catch (Throwable) {
            return new EmbeddedLyricsExportResult(true, 'failed', $parsed->kind);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($temporary !== '' && (file_exists($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
        }
    }

    /** Finds the first non-empty recognized tag without accepting arbitrary metadata as lyrics. */
    private function findEmbeddedText(array $rawTags): ?string
    {
        foreach (['format', 'audioStream'] as $scope) {
            $tags = $rawTags[$scope] ?? null;
            if (!is_array($tags)) {
                continue;
            }
            foreach ($tags as $key => $value) {
                if (!is_string($key) || !is_string($value) || trim($value) === '') {
                    continue;
                }
                $known = in_array($key, [
                    'lyrics', 'unsyncedlyrics', 'unsynchronizedlyrics', 'syncedlyrics', 'uslt', 'sylt',
                ], true) || str_starts_with($key, 'lyrics_');
                if ($known) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Serializes normalized lines into deterministic UTF-8/LF content suitable for `.lrc`. */
    private function serialize(ParsedLyrics $parsed): string
    {
        $lines = [];
        foreach ($parsed->lines as $line) {
            if ($line['startMs'] === null) {
                $lines[] = $line['text'];
                continue;
            }
            $milliseconds = (int) $line['startMs'];
            $minutes = intdiv($milliseconds, 60_000);
            $seconds = intdiv($milliseconds % 60_000, 1_000);
            $fraction = $milliseconds % 1_000;
            $lines[] = sprintf('[%02d:%02d.%03d]%s', $minutes, $seconds, $fraction, $line['text']);
        }

        return implode("\n", $lines) . "\n";
    }

    /** Allows deployments to disable library writes while preserving embedded recognition status. */
    private function enabled(): bool
    {
        $value = strtolower(trim((string) (getenv('VELIN_EXPORT_EMBEDDED_LYRICS') ?: 'true')));

        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    /** Confirms the audio inode, device, size, and mtime remained stable throughout export. */
    private function sameFile(array $before, array|false $after): bool
    {
        return is_array($after)
            && (int) ($before['dev'] ?? -1) === (int) ($after['dev'] ?? -2)
            && (int) ($before['ino'] ?? -1) === (int) ($after['ino'] ?? -2)
            && (int) ($before['size'] ?? -1) === (int) ($after['size'] ?? -2)
            && (int) ($before['mtime'] ?? -1) === (int) ($after['mtime'] ?? -2);
    }
}
