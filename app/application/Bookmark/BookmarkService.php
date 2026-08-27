<?php

declare(strict_types=1);

namespace app\application\Bookmark;

use app\application\Media\MediaQueryService;
use app\application\Media\SongDuplicateRedirectResolver;
use stdClass;
use support\Db;

/**
 * Owns isolated song bookmarks shared by the Web UI and Subsonic compatibility layer.
 *
 * Every read that returns song metadata applies MediaQueryService's live library/file scope before
 * pagination. Saves re-prove the song inside the write transaction, use one row per user/song, and
 * never store paths. Web callers use optimistic versions; legacy Subsonic callers intentionally use
 * last-command-wins because that protocol has no version precondition. Deletes can remove the
 * current user's stale hidden bookmark without proving media access and return no media metadata.
 */
final readonly class BookmarkService
{
    public function __construct(private MediaQueryService $media = new MediaQueryService())
    {
    }

    /**
     * Returns one recent-first page after authorization filtering and before public song projection.
     *
     * @param array<string, mixed> $actor Authenticated principal with global play capability.
     * @return array{bookmarks: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function list(array $actor, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('user_song_bookmarks as bookmarks')
            ->where('bookmarks.user_id', (string) $actor['id']);
        $this->media->constrainToVisibleSongs($query, $actor, 'bookmarks.song_id');
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('bookmarks.updated_at')->orderBy('bookmarks.song_id')
            ->offset($offset)->limit($limit)
            ->get([
                'bookmarks.song_id', 'bookmarks.position_ms', 'bookmarks.comment',
                'bookmarks.version', 'bookmarks.created_at', 'bookmarks.updated_at',
            ])->all();

        return [
            'bookmarks' => $this->mapRows($actor, $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Returns the current user's authorized bookmark or null for absent, hidden, or invalid media.
     *
     * This non-enumerating shape lets the player obtain expectedVersion=0 before creating a bookmark
     * without learning whether an inaccessible song exists. It performs no writes.
     *
     * @param array<string, mixed> $actor Authenticated principal with current grants.
     * @return array<string, mixed>|null
     */
    public function one(array $actor, string $songId): ?array
    {
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        /** @var stdClass|null $row */
        $row = Db::table('user_song_bookmarks')->where('user_id', (string) $actor['id'])
            ->where('song_id', $songId)
            ->first(['song_id', 'position_ms', 'comment', 'version', 'created_at', 'updated_at']);
        if (!$row instanceof stdClass) {
            return null;
        }

        return $this->mapRows($actor, [$row])[0] ?? null;
    }

    /**
     * Creates or updates one bookmark under Web versioning or legacy overwrite semantics.
     *
     * Authorization, duration bounds, version comparison, and mutation occur in one transaction.
     * `expectedVersion=0` requires absence; a positive value must equal the current row. Null is
     * reserved for Subsonic and increments whichever current version exists. Position may equal a
     * known song duration but never exceed it; duration zero means unknown and only the validated
     * non-negative protocol bound applies. The operation is idempotent in observable state; a repeated
     * legacy write still advances version because legacy clients never observe that internal field.
     *
     * @param array<string, mixed> $actor Authenticated principal whose user ID owns the row.
     * @return array<string, mixed> Complete authorized bookmark projection after commit.
     */
    public function save(array $actor, BookmarkInput $input): array
    {
        $userId = (string) $actor['id'];
        $songId = (new SongDuplicateRedirectResolver())->resolve($input->songId);
        Db::transaction(function () use ($actor, $input, $songId, $userId): void {
            $song = $this->media->songsByIds($actor, [$songId])[$songId] ?? null;
            if (!is_array($song)) {
                throw new BookmarkNotFound('Bookmark song was not found.');
            }
            $durationMs = max(0, (int) ($song['durationMs'] ?? 0));
            if ($durationMs > 0 && $input->positionMs > $durationMs) {
                throw new BookmarkInvalid('Bookmark position exceeds song duration.');
            }
            /** @var stdClass|null $current */
            $current = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->first(['version']);
            $currentVersion = $current instanceof stdClass ? (int) $current->version : 0;
            if ($input->expectedVersion !== null && $input->expectedVersion !== $currentVersion) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
            $now = gmdate('c');
            if (!$current instanceof stdClass) {
                Db::table('user_song_bookmarks')->insert([
                    'user_id' => $userId,
                    'song_id' => $songId,
                    'position_ms' => $input->positionMs,
                    'comment' => $input->comment,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return;
            }
            $updated = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->where('version', $currentVersion)->update([
                    'position_ms' => $input->positionMs,
                    'comment' => $input->comment,
                    'version' => $currentVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                // SQLite serializes writers today, while the future MySQL repository may observe
                // a competing version between read and update. The predicate is the final arbiter.
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
        });

        return $this->one($actor, $songId)
            ?? throw new BookmarkNotFound('Saved bookmark is no longer authorized.');
    }

    /**
     * Deletes only the authenticated user's row, with optional Web optimistic locking.
     *
     * Null expectedVersion is the idempotent Subsonic form: an absent bookmark is successful. Web
     * deletion requires the exact positive version and reports a conflict for absent/stale state.
     * Media authorization is intentionally not required so a user can remove stale personal data
     * after losing library access; no song projection is returned.
     */
    public function delete(array $actor, string $songId, ?int $expectedVersion): void
    {
        $songId = (new SongDuplicateRedirectResolver())->resolve($songId);
        $userId = (string) $actor['id'];
        Db::transaction(function () use ($expectedVersion, $songId, $userId): void {
            /** @var stdClass|null $row */
            $row = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->first(['version']);
            if (!$row instanceof stdClass) {
                if ($expectedVersion !== null) {
                    throw new BookmarkConflict('书签已被删除，请重新加载。');
                }
                return;
            }
            $version = (int) $row->version;
            if ($expectedVersion !== null && $expectedVersion !== $version) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
            $deleted = Db::table('user_song_bookmarks')->where('user_id', $userId)
                ->where('song_id', $songId)->where('version', $version)->delete();
            if ($deleted !== 1) {
                throw new BookmarkConflict('书签已在其他页面或客户端更新，请重新加载。');
            }
        });
    }

    /**
     * Maps rows only after fetching current authorized song projections in one bounded query.
     *
     * @param list<stdClass> $rows Rows already filtered by user ownership; list queries also apply visibility.
     * @return list<array<string, mixed>> Path-free personal bookmark projections.
     */
    private function mapRows(array $actor, array $rows): array
    {
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows);
        $songs = $this->media->songsByIds($actor, $songIds);
        $result = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (!isset($songs[$songId])) {
                continue;
            }
            $result[] = [
                'songId' => $songId,
                'positionMs' => (int) $row->position_ms,
                'comment' => $row->comment === null ? null : (string) $row->comment,
                'version' => (int) $row->version,
                'createdAt' => (string) $row->created_at,
                'updatedAt' => (string) $row->updated_at,
                'song' => $songs[$songId],
            ];
        }

        return $result;
    }
}
