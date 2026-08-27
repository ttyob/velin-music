<?php

declare(strict_types=1);

namespace app\application\Artwork;

use FilesystemIterator;
use Throwable;

/**
 * Selects one safe raster image from a single media directory without recursively crossing scopes.
 *
 * Reserved basenames always outrank arbitrary filenames. When no reserved candidate exists, valid
 * JPG/PNG/WebP files are ordered by modification time (newest first) and then by a stable relative
 * locator hash. Filesystem birth time is intentionally not used because it is unavailable or has
 * incompatible semantics on common Linux filesystems. Every candidate is still verified by actual
 * image signature, bounded dimensions/bytes, stable stat identity, and canonical-root containment.
 */
final readonly class LocalArtworkCandidateFinder
{
    public function __construct(private ArtworkImageInspector $inspector = new ArtworkImageInspector())
    {
    }

    /**
     * @param array<string, int> $reservedPriorities Lowercase basename to positive priority.
     * @return array{match: array{path:string,fileName:string,priority:int,selectionKey:string,image:array{mime:string,width:int,height:int,size:int,mtime:int,dev:int,ino:int,sha256:string}}|null, hadCandidates:bool}
     */
    public function find(
        string $directory,
        string $resolvedRoot,
        string $relativeDirectory,
        array $reservedPriorities,
    ): array {
        $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
        $candidates = [];
        try {
            $entries = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach ($entries as $entry) {
                if ($entry->isLink()) {
                    continue;
                }
                $extension = strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    continue;
                }
                $resolved = realpath($entry->getPathname());
                if ($resolved === false || !str_starts_with($resolved, $rootPrefix)
                    || !is_file($resolved) || !is_readable($resolved)) {
                    continue;
                }
                $basename = strtolower(pathinfo($entry->getFilename(), PATHINFO_FILENAME));
                $relativeLocator = ltrim($relativeDirectory . '/' . $entry->getFilename(), '/');
                $candidates[] = [
                    'path' => $resolved,
                    'fileName' => $entry->getFilename(),
                    'priority' => $reservedPriorities[$basename] ?? 0,
                    'modifiedAt' => max(0, (int) (@filemtime($resolved) ?: 0)),
                    'selectionKey' => hash('sha256', $relativeLocator),
                ];
            }
        } catch (Throwable) {
            return ['match' => null, 'hadCandidates' => true];
        }

        usort($candidates, static function (array $left, array $right): int {
            $priority = $right['priority'] <=> $left['priority'];
            if ($priority !== 0) {
                return $priority;
            }
            // Reserved candidates with equal semantic priority retain path-stable selection.
            if ($left['priority'] > 0) {
                return $left['selectionKey'] <=> $right['selectionKey'];
            }
            return $right['modifiedAt'] <=> $left['modifiedAt']
                ?: $left['selectionKey'] <=> $right['selectionKey'];
        });
        foreach ($candidates as $candidate) {
            $image = $this->inspector->inspect($candidate['path']);
            if ($image !== null) {
                unset($candidate['modifiedAt']);
                return ['match' => $candidate + ['image' => $image], 'hadCandidates' => true];
            }
        }

        return ['match' => null, 'hadCandidates' => $candidates !== []];
    }
}
