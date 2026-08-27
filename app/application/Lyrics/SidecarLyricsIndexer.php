<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use FilesystemIterator;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use Throwable;

/**
 * Discovers and reconciles same-name local lyric files for one already indexed audio inventory.
 *
 * The indexer runs only in the scan Worker. It never follows a lyric symlink, never reads outside
 * the canonical library root, caps each file before reading, and rechecks its stat tuple after the
 * read. Database identity uses a digest of the library-relative locator, so physical paths remain
 * server-only and never enter API projections. A directory inspection failure retains all previous
 * records; a parse failure retains that candidate's previous record while allowing other valid
 * candidates to update. No source file is written, renamed, or deleted.
 */
final class SidecarLyricsIndexer
{
    private const MAX_FILE_BYTES = 1_048_576;

    public function __construct(private readonly LyricsParser $parser = new LyricsParser())
    {
    }

    /**
     * Updates `.lrc`/`.txt` sources for a song and removes only safely observed missing sidecars.
     *
     * @param string $audioPath Canonical, readable audio path revalidated by metadata indexing.
     * @param string $relativeAudioPath Library-relative audio locator used only to derive digests.
     * 数据库错误向上抛出，使持久扫描任务重试而不是报告虚假的成功索引。
     */
    public function index(
        string $libraryId,
        string $inventoryId,
        string $resolvedRoot,
        string $audioPath,
        string $relativeAudioPath,
    ): void {
        $songId = Db::table('media_songs')
            ->where('library_id', $libraryId)
            ->where('inventory_file_id', $inventoryId)
            ->value('id');
        if (!is_string($songId)) {
            return;
        }

        $directory = dirname($audioPath);
        $audioStem = pathinfo($audioPath, PATHINFO_FILENAME);
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativeDirectory = dirname(str_replace('\\', '/', $relativeAudioPath));
        $relativeDirectory = $relativeDirectory === '.' ? '' : trim($relativeDirectory, '/');
        $foundDigests = [];
        $candidates = [];

        try {
            $entries = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach ($entries as $entry) {
                $identity = $this->candidateIdentity($entry->getFilename(), $audioStem);
                if ($identity === null || $entry->isLink()) {
                    continue;
                }
                $resolved = realpath($entry->getPathname());
                if ($resolved === false
                    || !str_starts_with($resolved, $rootPrefix)
                    || !is_file($resolved)
                    || !is_readable($resolved)
                ) {
                    continue;
                }
                $relativeLocator = ltrim($relativeDirectory . '/' . $entry->getFilename(), '/');
                $locatorDigest = hash('sha256', str_replace('\\', '/', $relativeLocator));
                $foundDigests[] = $locatorDigest;
                $candidates[] = [
                    'path' => $resolved,
                    'relativeLocator' => $relativeLocator,
                    'digest' => $locatorDigest,
                    'language' => $identity['language'],
                    'format' => $identity['format'],
                ];
            }
        } catch (Throwable) {
            // An incomplete directory view is not evidence that a previously indexed lyric vanished.
            return;
        }

        foreach ($candidates as $candidate) {
            $statBefore = @stat($candidate['path']);
            if (!is_array($statBefore)
                || (int) ($statBefore['size'] ?? -1) <= 0
                || (int) ($statBefore['size'] ?? 0) > self::MAX_FILE_BYTES
            ) {
                continue;
            }
            $bytes = @file_get_contents($candidate['path']);
            $statAfter = @stat($candidate['path']);
            if (!is_string($bytes) || !$this->sameFile($statBefore, $statAfter)) {
                continue;
            }
            try {
                $parsed = $this->parser->parse($bytes);
            } catch (LyricsParseFailed) {
                $this->recordParseFailure(
                    $songId,
                    $candidate['digest'],
                    $candidate['language'],
                    $candidate['format'],
                    (int) $statAfter['size'],
                    max(0, (int) $statAfter['mtime']),
                );
                continue;
            }
            $this->upsert(
                songId: $songId,
                locatorDigest: $candidate['digest'],
                storageLocator: $candidate['relativeLocator'],
                language: $candidate['language'],
                format: $candidate['format'],
                parsed: $parsed,
                sourceSize: (int) $statAfter['size'],
                sourceModifiedAt: max(0, (int) $statAfter['mtime']),
                contentHash: hash('sha256', $bytes),
            );
        }

        Db::transaction(function () use ($foundDigests, $songId): void {
            $query = Db::table('media_lyrics')->where('song_id', $songId)->where('source_kind', 'sidecar');
            if ($foundDigests !== []) {
                $query->whereNotIn('source_locator_digest', array_values(array_unique($foundDigests)));
            }
            $query->delete();
            $diagnostics = Db::table('media_lyrics_parse_diagnostics')->where('song_id', $songId);
            if ($foundDigests !== []) {
                $diagnostics->whereNotIn('source_locator_digest', array_values(array_unique($foundDigests)));
            }
            $diagnostics->delete();
        });
    }

    /**
     * Maps an exact audio stem plus optional BCP-47-like suffix to a candidate identity.
     *
     * Extension matching is case-insensitive, but the stem remains exact so a case-sensitive
     * filesystem does not associate `song.lrc` with `Song.mp3` accidentally.
     *
     * @return array{language: string, format: string}|null
     */
    private function candidateIdentity(string $filename, string $audioStem): ?array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['lrc', 'txt'], true)) {
            return null;
        }
        $withoutExtension = substr($filename, 0, -(strlen($extension) + 1));
        if ($withoutExtension === $audioStem) {
            return ['language' => 'und', 'format' => $extension];
        }
        $prefix = $audioStem . '.';
        if (!str_starts_with($withoutExtension, $prefix)) {
            return null;
        }
        $language = substr($withoutExtension, strlen($prefix));
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            return null;
        }

        return ['language' => $this->normalizeLanguage($language), 'format' => $extension];
    }

    /** Normalizes common BCP-47 casing without claiming full language-tag validation. */
    private function normalizeLanguage(string $language): string
    {
        $parts = explode('-', str_replace('_', '-', $language));
        foreach ($parts as $index => $part) {
            $parts[$index] = match (true) {
                $index === 0 => strtolower($part),
                strlen($part) === 4 => ucfirst(strtolower($part)),
                strlen($part) === 2 || (strlen($part) === 3 && ctype_digit($part)) => strtoupper($part),
                default => strtolower($part),
            };
        }

        return implode('-', $parts);
    }

    /** Confirms bytes came from the exact stat identity observed before reading. */
    private function sameFile(array $before, array|false $after): bool
    {
        return is_array($after)
            && (int) ($before['dev'] ?? -1) === (int) ($after['dev'] ?? -2)
            && (int) ($before['ino'] ?? -1) === (int) ($after['ino'] ?? -2)
            && (int) ($before['size'] ?? -1) === (int) ($after['size'] ?? -2)
            && (int) ($before['mtime'] ?? -1) === (int) ($after['mtime'] ?? -2);
    }

    /** Atomically inserts or version-updates one normalized local source without storing its path. */
    private function upsert(
        string $songId,
        string $locatorDigest,
        string $storageLocator,
        string $language,
        string $format,
        ParsedLyrics $parsed,
        int $sourceSize,
        int $sourceModifiedAt,
        string $contentHash,
    ): void {
        $syncPriority = match ($parsed->kind) {
            'word' => 40,
            'line' => 20,
            default => 0,
        };
        $priority = $syncPriority + ($format === 'lrc' ? 200 : 100);
        Db::transaction(function () use (
            $contentHash,
            $format,
            $language,
            $locatorDigest,
            $parsed,
            $priority,
            $songId,
            $sourceModifiedAt,
            $sourceSize,
            $storageLocator,
        ): void {
            /** @var stdClass|null $existing */
            $existing = Db::table('media_lyrics')
                ->where('song_id', $songId)
                ->where('source_kind', 'sidecar')
                ->where('source_locator_digest', $locatorDigest)
                ->first(['id', 'content_sha256', 'storage_kind', 'storage_locator', 'language', 'lyric_kind',
                    'source_format', 'source_size_bytes', 'source_modified_at']);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $values = [
                'language' => $language,
                'lyric_kind' => $parsed->kind,
                'source_format' => $format,
                'storage_kind' => 'adjacent',
                'storage_locator' => $storageLocator,
                'content_sha256' => $contentHash,
                'priority' => $priority,
                'match_score' => 1.0,
                'license_policy' => 'local_controlled',
                'source_size_bytes' => $sourceSize,
                'source_modified_at' => $sourceModifiedAt,
                'updated_at' => $now,
            ];
            if (!$existing instanceof stdClass) {
                Db::table('media_lyrics')->insert([
                    'id' => (string) new Ulid(),
                    'song_id' => $songId,
                    'source_kind' => 'sidecar',
                    'source_locator_digest' => $locatorDigest,
                    'version' => 1,
                    'created_at' => $now,
                ] + $values);
                Db::table('media_lyrics_parse_diagnostics')->where('song_id', $songId)
                    ->where('source_locator_digest', $locatorDigest)->delete();
                return;
            }
            $changed = (string) $existing->content_sha256 !== $contentHash
                || (string) $existing->storage_kind !== 'adjacent'
                || (string) $existing->storage_locator !== $storageLocator
                || (string) $existing->language !== $language
                || (string) $existing->lyric_kind !== $parsed->kind
                || (string) $existing->source_format !== $format
                || (int) $existing->source_size_bytes !== $sourceSize
                || (int) $existing->source_modified_at !== $sourceModifiedAt;
            if ($changed) {
                Db::table('media_lyrics')->where('id', (string) $existing->id)->update([
                    ...$values,
                    'version' => Db::raw('version + 1'),
                ]);
            }
            Db::table('media_lyrics_parse_diagnostics')->where('song_id', $songId)
                ->where('source_locator_digest', $locatorDigest)->delete();
        });
    }

    /**
     * 记录固定错误码和文件身份，不保存路径、正文或解析异常消息。
     *
     * 同一未变化候选重复扫描只刷新尝试时间；旧有效歌词保持可读。事务失败向上抛出，让扫描任务重试，
     * 不能在数据库失败时伪装成已记录诊断。
     */
    private function recordParseFailure(string $songId, string $locatorDigest, string $language, string $format, int $size, int $modifiedAt): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($format, $language, $locatorDigest, $modifiedAt, $now, $size, $songId): void {
            $query = Db::table('media_lyrics_parse_diagnostics')->where('song_id', $songId)
                ->where('source_locator_digest', $locatorDigest);
            $values = [
                'language' => $language, 'source_format' => $format,
                'error_code' => 'LYRICS_PARSE_FAILED', 'source_size_bytes' => $size,
                'source_modified_at' => $modifiedAt, 'last_attempt_at' => $now, 'updated_at' => $now,
            ];
            if ($query->exists()) {
                $query->update($values);
            } else {
                Db::table('media_lyrics_parse_diagnostics')->insert([
                    'song_id' => $songId, 'source_locator_digest' => $locatorDigest, 'created_at' => $now,
                ] + $values);
            }
        });
    }
}
