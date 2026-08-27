<?php

declare(strict_types=1);

namespace app\application\Scan;

use FilesystemIterator;
use Generator;
use Throwable;

/**
 * Discovers supported audio paths and M3U source identities without parsing content or mutating files.
 *
 * Traversal runs only in the dedicated scan Worker. Every directory realpath must remain beneath
 * the immutable registered root; symlinks are ignored unless `within_root` is configured, and a
 * visited-real-directory set prevents link loops. Hidden entries and files outside the audio/M3U
 * allowlists are ignored. Per-entry stat failures are counted and skipped so one damaged path cannot abort a
 * whole library, while an unreadable root is rejected by the Worker before this class is called.
 */
final class LibraryFileDiscovery
{
    /** @var array<string, true> */
    private const SUPPORTED_EXTENSIONS = [
        'mp3' => true,
        'flac' => true,
        'aac' => true,
        'm4a' => true,
        'm4b' => true,
        'alac' => true,
        'ogg' => true,
        'oga' => true,
        'opus' => true,
        'wav' => true,
        'aiff' => true,
        'aif' => true,
        'wma' => true,
        'ape' => true,
    ];

    /** M3U files share traversal with audio discovery but enter a separate path-hidden registry. */
    private const PLAYLIST_EXTENSIONS = ['m3u' => true, 'm3u8' => true];

    /**
     * 判断一个不含点号的扩展名是否属于扫描器支持的音频类型。
     *
     * 刮削和待入库 Worker 在回收目录前必须复用与扫描器完全相同的白名单；否则新增格式后，清理
     * 逻辑可能把仍含可处理音频的目录误判为空闲。输入统一转为小写，调用方无需重复标准化。
     */
    public static function supportsAudioExtension(string $extension): bool
    {
        return isset(self::SUPPORTED_EXTENSIONS[strtolower($extension)]);
    }

    /**
     * Streams bounded observations and returns traversal counters when exhausted.
     *
     * @param callable(int): void $checkpoint Called initially and every 128 visited entries. The
     *        Worker uses it for heartbeat, cancellation, and graceful-stop checks; it may throw
     *        ScanExecutionCancelled, after which no reconciliation may run.
     * @return Generator<int, DiscoveredAudioFile|DiscoveredM3uSource, mixed, array{processedEntries: int, ignoredEntries: int, failedEntries: int}>
     */
    public function files(string $root, string $symlinkPolicy, callable $checkpoint): Generator
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $stack = [[$root, $root]];
        $visitedDirectories = [$root => true];
        $visitedFiles = [];
        $processedEntries = 0;
        $ignoredEntries = 0;
        $failedEntries = 0;
        $checkpoint(0);

        while ($stack !== []) {
            [$logicalDirectory, $resolvedDirectory] = array_pop($stack);
            if (!$this->isWithinRoot($resolvedDirectory, $root)) {
                ++$ignoredEntries;
                continue;
            }

            try {
                $entries = new FilesystemIterator($logicalDirectory, FilesystemIterator::SKIP_DOTS);
            } catch (Throwable) {
                ++$failedEntries;
                continue;
            }

            foreach ($entries as $entry) {
                ++$processedEntries;
                if ($processedEntries % 128 === 0) {
                    $checkpoint($processedEntries);
                }

                $name = $entry->getFilename();
                if ($name === '' || str_starts_with($name, '.')) {
                    ++$ignoredEntries;
                    continue;
                }

                $logicalPath = $entry->getPathname();
                $isLink = $entry->isLink();
                if ($isLink && $symlinkPolicy !== 'within_root') {
                    ++$ignoredEntries;
                    continue;
                }

                $resolvedPath = realpath($logicalPath);
                if ($resolvedPath === false || !$this->isWithinRoot($resolvedPath, $root)) {
                    ++$ignoredEntries;
                    continue;
                }
                if (is_dir($resolvedPath)) {
                    if (isset($visitedDirectories[$resolvedPath])) {
                        ++$ignoredEntries;
                        continue;
                    }
                    $visitedDirectories[$resolvedPath] = true;
                    $stack[] = [$logicalPath, $resolvedPath];
                    continue;
                }
                if (!is_file($resolvedPath)) {
                    ++$ignoredEntries;
                    continue;
                }

                if (isset($visitedFiles[$resolvedPath])) {
                    ++$ignoredEntries;
                    continue;
                }
                $visitedFiles[$resolvedPath] = true;

                $extension = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
                if (!isset(self::SUPPORTED_EXTENSIONS[$extension]) && !isset(self::PLAYLIST_EXTENSIONS[$extension])) {
                    ++$ignoredEntries;
                    continue;
                }
                $stat = @stat($resolvedPath);
                if (!is_array($stat)) {
                    ++$failedEntries;
                    continue;
                }

                // Canonical relative paths keep an in-root symlink alias from changing identity.
                $relativePath = substr($resolvedPath, strlen($root) + 1);
                if ($relativePath === false || $relativePath === '') {
                    ++$failedEntries;
                    continue;
                }

                $observation = isset(self::PLAYLIST_EXTENSIONS[$extension])
                    ? new DiscoveredM3uSource(
                        relativePath: str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                        resolvedPath: $resolvedPath,
                        displayName: mb_substr($name, 0, 255),
                        extension: $extension,
                        deviceId: max(0, (int) ($stat['dev'] ?? 0)),
                        inode: max(0, (int) ($stat['ino'] ?? 0)),
                        fileSize: max(0, (int) ($stat['size'] ?? 0)),
                        modifiedAt: max(0, (int) ($stat['mtime'] ?? 0)),
                    )
                    : new DiscoveredAudioFile(
                    relativePath: str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                    resolvedPath: $resolvedPath,
                    extension: $extension,
                    deviceId: max(0, (int) ($stat['dev'] ?? 0)),
                    inode: max(0, (int) ($stat['ino'] ?? 0)),
                    fileSize: max(0, (int) ($stat['size'] ?? 0)),
                    modifiedAt: max(0, (int) ($stat['mtime'] ?? 0)),
                );
                yield $observation;
            }
        }

        $checkpoint($processedEntries);

        return [
            'processedEntries' => $processedEntries,
            'ignoredEntries' => $ignoredEntries,
            'failedEntries' => $failedEntries,
        ];
    }

    /** Confirms a real path is equal to or contained by the canonical library root. */
    private function isWithinRoot(string $path, string $root): bool
    {
        return $path === $root
            || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
