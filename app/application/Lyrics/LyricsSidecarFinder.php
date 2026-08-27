<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use FilesystemIterator;
use Throwable;

/**
 * Finds bounded, direct-child lyric sidecars without platform-specific glob extensions.
 *
 * An accepted file is a readable, non-link LRC/TXT no larger than 1 MiB whose basename is either
 * identical to the audio basename or adds one `.language` suffix. The finder never follows a link,
 * recurses, reads lyric content, or changes the filesystem. At most 4096 directory entries are
 * inspected and the deterministic result is capped by the caller's bounded limit.
 *
 * Filesystem errors return an empty list because sidecars are optional; audio organization/import
 * must continue without lyrics. Callers remain responsible for checking the returned paths against
 * their registered root immediately before copying or moving them.
 */
final class LyricsSidecarFinder
{
    /**
     * @return list<string> Sorted filesystem paths containing no links or unvalidated extensions.
     */
    public function find(string $audioPath, int $limit = 16): array
    {
        $limit = max(1, min(64, $limit));
        $audioBase = pathinfo($audioPath, PATHINFO_FILENAME);
        $matches = [];
        try {
            // GLOB_BRACE does not exist on Alpine/musl, and glob treats brackets in legitimate
            // directory names as pattern syntax. FilesystemIterator keeps every path segment data.
            $iterator = new FilesystemIterator(dirname($audioPath), FilesystemIterator::SKIP_DOTS);
            $inspected = 0;
            foreach ($iterator as $entry) {
                if (++$inspected > 4096) {
                    break;
                }
                if ($entry->isLink() || !$entry->isFile() || !$entry->isReadable() || $entry->getSize() > 1_048_576) {
                    continue;
                }
                $extension = strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
                $candidateBase = pathinfo($entry->getFilename(), PATHINFO_FILENAME);
                if (!in_array($extension, ['lrc', 'txt'], true) || !str_starts_with($candidateBase, $audioBase)) {
                    continue;
                }
                $suffix = substr($candidateBase, strlen($audioBase));
                if ($suffix !== '' && preg_match('/^\.[A-Za-z0-9-]{2,35}$/', $suffix) !== 1) {
                    continue;
                }
                $matches[] = $entry->getPathname();
            }
        } catch (Throwable) {
            return [];
        }
        sort($matches, SORT_STRING);
        return array_slice($matches, 0, $limit);
    }
}
