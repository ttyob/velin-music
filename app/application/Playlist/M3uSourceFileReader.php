<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Library\LibraryPathInspector;
use app\application\Library\LibraryPathInvalid;
use stdClass;
use support\Db;

/**
 * Reopens a scanner-registered M3U source under its immutable library-root and stat constraints.
 *
 * This class accepts only an opaque source ULID. It reconstructs the candidate from the canonical
 * registered root plus scanner-owned relative path, resolves it, checks containment, checks that the
 * resolved path still equals the scanner observation, then verifies device/inode/size/mtime both on
 * the opened descriptor and after reading. At most `M3uParser::MAX_BYTES + 1` bytes are read. A path
 * swap, mount replacement, oversized file, short read, or concurrent write fails closed without
 * returning/logging any path. No lock is held in SQLite while filesystem I/O occurs.
 */
final readonly class M3uSourceFileReader
{
    public function __construct(private LibraryPathInspector $paths = new LibraryPathInspector())
    {
    }

    /**
     * Returns bounded source bytes and SHA-256 only when all scan-time identity fields still match.
     *
     * @throws M3uSourceInvalid Missing, stale, unsafe, unreadable, empty, or oversized source.
     */
    public function read(string $sourceId): M3uSourceFile
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $sourceId) !== 1) {
            throw new M3uSourceInvalid('source_id_invalid');
        }
        /** @var stdClass|null $row */
        $row = Db::table('library_m3u_sources as sources')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sources.library_id')
            ->where('sources.id', $sourceId)
            ->where('sources.status', 'available')
            ->where('libraries.status', 'active')
            ->first([
                'sources.relative_path', 'sources.resolved_path', 'sources.device_id', 'sources.inode',
                'sources.file_size', 'sources.modified_at', 'libraries.root_path', 'libraries.resolved_root_path',
            ]);
        if (!$row instanceof stdClass) {
            throw new M3uSourceInvalid('source_unavailable');
        }

        try {
            $root = $this->paths->resolve((string) $row->root_path);
        } catch (LibraryPathInvalid) {
            throw new M3uSourceInvalid('library_root_changed');
        }
        if (!hash_equals((string) $row->resolved_root_path, $root)) {
            throw new M3uSourceInvalid('library_root_changed');
        }
        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, (string) $row->relative_path);
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '..' . DIRECTORY_SEPARATOR)) {
            throw new M3uSourceInvalid('source_path_invalid');
        }
        $candidate = $root . DIRECTORY_SEPARATOR . $relativePath;
        $resolved = realpath($candidate);
        if (!is_string($resolved)
            || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            || !hash_equals((string) $row->resolved_path, $resolved)
            || !is_file($resolved)) {
            throw new M3uSourceInvalid('source_identity_changed');
        }

        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            throw new M3uSourceInvalid('source_read_failed');
        }
        try {
            $before = fstat($handle);
            if (!$this->identityMatches($before, $row)) {
                throw new M3uSourceInvalid('source_identity_changed');
            }
            $bytes = stream_get_contents($handle, M3uParser::MAX_BYTES + 1);
            $after = fstat($handle);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > M3uParser::MAX_BYTES) {
                throw new M3uSourceInvalid('source_size_invalid');
            }
            if (!$this->identityMatches($after, $row)) {
                throw new M3uSourceInvalid('source_changed_while_reading');
            }
        } finally {
            fclose($handle);
        }

        return new M3uSourceFile($bytes, hash('sha256', $bytes));
    }

    /** Confirms the open descriptor is exactly the file observed by the completed library scan. */
    private function identityMatches(array|false $stat, stdClass $row): bool
    {
        return is_array($stat)
            && (int) ($stat['dev'] ?? -1) === (int) $row->device_id
            && (int) ($stat['ino'] ?? -1) === (int) $row->inode
            && (int) ($stat['size'] ?? -1) === (int) $row->file_size
            && (int) ($stat['mtime'] ?? -1) === (int) $row->modified_at;
    }
}
