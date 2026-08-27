<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Bookmark\BookmarkInput;
use app\application\Bookmark\BookmarkInvalid;
use app\application\Bookmark\BookmarkNotFound;
use app\application\Bookmark\BookmarkService;
use app\application\Bookmark\BookmarkValidator;

/**
 * Adapts personal Velin song bookmarks to Subsonic's unversioned bookmark contract.
 *
 * BookmarkService remains the only persistence and authorization boundary. Reads therefore omit
 * bookmarks whose media is no longer visible, while deletion can still clean up the caller's stale
 * row without revealing its former song. Subsonic carries no expected version, so create/update is
 * deliberately last-command-wins and increments the internal Web version. No filesystem operation,
 * playback event, scan, or queue mutation is performed by these endpoints.
 */
final readonly class SubsonicBookmarkService
{
    private const MAX_BOOKMARKS = 10_100;

    public function __construct(
        private BookmarkService $bookmarks = new BookmarkService(),
        private BookmarkValidator $validator = new BookmarkValidator(),
        private SubsonicCatalogService $catalog = new SubsonicCatalogService(),
    ) {
    }

    /** Returns all currently authorized bookmarks in recent-first order under a bounded ceiling. */
    public function get(array $actor): array
    {
        $this->requirePlay($actor);
        $items = [];
        for ($offset = 0; $offset <= 10_000 && count($items) < self::MAX_BOOKMARKS; $offset += 100) {
            $page = $this->bookmarks->list($actor, 100, $offset);
            foreach ($page['bookmarks'] as $bookmark) {
                $items[] = $this->mapBookmark($actor, $bookmark);
            }
            if ($page['bookmarks'] === [] || count($items) >= $page['total']) {
                break;
            }
        }

        return ['bookmarks' => ['bookmark' => array_slice($items, 0, self::MAX_BOOKMARKS)]];
    }

    /**
     * Creates or overwrites one bookmark using strict millisecond and comment validation.
     *
     * The protocol command returns an empty success body. Missing/malformed fields map to error 10;
     * a well-formed but absent, unavailable, or unauthorized song maps to error 70. Position bounds
     * are rechecked against the indexed song duration inside BookmarkService's write transaction.
     *
     * @param array<string, mixed> $parameters Merged Subsonic query/form parameters.
     */
    public function create(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        try {
            $input = $this->validator->input(
                $parameters['id'] ?? null,
                $parameters['position'] ?? null,
                $parameters['comment'] ?? null,
                null,
                true,
            );
            $this->bookmarks->save($actor, new BookmarkInput(
                $input->songId,
                $input->positionMs,
                $input->comment,
                null,
            ));
        } catch (BookmarkInvalid $exception) {
            throw new SubsonicRequestInvalid('Bookmark fields are invalid.', previous: $exception);
        } catch (BookmarkNotFound $exception) {
            throw new SubsonicEntityNotFound('Bookmark song was not found.', previous: $exception);
        }

        return [];
    }

    /**
     * Idempotently removes the authenticated user's bookmark without enumerating media visibility.
     *
     * A malformed opaque ID is error 10. A canonical ID with no current row is still successful,
     * matching Subsonic's command semantics and allowing cleanup after a library grant is revoked.
     */
    public function delete(array $actor, mixed $songId): array
    {
        $this->requirePlay($actor);
        try {
            $songId = $this->validator->songId($songId);
        } catch (BookmarkInvalid $exception) {
            throw new SubsonicRequestInvalid('Bookmark song ID is invalid.', previous: $exception);
        }
        $this->bookmarks->delete($actor, $songId, null);

        return [];
    }

    /** @return array<string, mixed> Maps only a live-authorized Web bookmark projection. */
    private function mapBookmark(array $actor, array $bookmark): array
    {
        $song = is_array($bookmark['song'] ?? null) ? $bookmark['song'] : [];
        $entry = $this->catalog->mapAuthorizedSong($song);
        $entry['bookmarkPosition'] = max(0, (int) ($bookmark['positionMs'] ?? 0));
        $result = [
            'position' => max(0, (int) ($bookmark['positionMs'] ?? 0)),
            'username' => (string) ($actor['username'] ?? ''),
            'created' => (string) ($bookmark['createdAt'] ?? '1970-01-01T00:00:00Z'),
            'changed' => (string) ($bookmark['updatedAt'] ?? '1970-01-01T00:00:00Z'),
            'entry' => $entry,
        ];
        if (is_string($bookmark['comment'] ?? null) && $bookmark['comment'] !== '') {
            $result['comment'] = $bookmark['comment'];
        }

        return $result;
    }

    /** Enforces global playback permission before any personal bookmark read or command. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic bookmark operation is not authorized.');
        }
    }
}
