<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use Throwable;

/**
 * 把已验证的音频内嵌歌词非覆盖发布为同名 `.lrc`。
 *
 * 本写入器只由扫描 Worker 使用。它先确认音频规范路径仍位于不可变音乐库根内，再解析有界标签正文，
 * 并通过统一序列化器保留逐行或逐字时间轴。内容写入同目录独占临时文件、刷新后使用硬链接原子发布，
 * 因而并发创建的目标不会被覆盖。任意失败都会清理本任务临时文件，不调用 shell、不使用标签标题构造
 * 文件名，也不修改音频文件或既有 sidecar。
 */
final class EmbeddedLyricsExporter
{
    public function __construct(
        private readonly LyricsParser $parser = new LyricsParser(),
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
    ) {
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
            $content = $this->serializer->serialize($parsed->kind, $parsed->lines);
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
