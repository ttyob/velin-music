<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistInvalid;
use app\application\Playlist\PlaylistItemNotFound;
use app\application\Playlist\PlaylistNotFound;
use app\application\Playlist\PlaylistService;
use app\application\Playlist\PlaylistValidator;

/**
 * Adapts Subsonic playlist commands to Velin's owner/version/authorization transaction boundary.
 *
 * 读取复用 PlaylistService 的权限投影，包含所有者私有、服务器公开和公开系统歌单；系统歌单由领域服务置顶。
 * Writes require `create_playlist`, never accept a target username, and transform incremental
 * Subsonic parameters into one complete state committed by replaceAll. Playlist IDs and songs stay
 * stable opaque IDs; filesystem paths and inaccessible stored entries never enter this adapter.
 */
final readonly class SubsonicPlaylistService
{
    private const MAX_UNPAGED_ITEMS = 10_100;

    /** Shares the Web playlist domain service and the canonical ID3 media mapper. */
    public function __construct(
        private PlaylistService $playlists = new PlaylistService(),
        private PlaylistValidator $validator = new PlaylistValidator(),
        private SubsonicCatalogService $catalog = new SubsonicCatalogService(),
    ) {
    }

    /**
     * Lists playlists readable by the authenticated user without allowing username impersonation.
     *
     * The optional protocol username may only equal the current identity, case-insensitively. The
     * unpaged response is assembled in stable bounded pages and capped at 10,100 headers. Song
     * counts/durations already exclude items that the caller can no longer play.
     *
     * @param array<string, mixed> $actor Current principal from SubsonicAuthenticator.
     * @return array{playlists: array{playlist: list<array<string, mixed>>}}
     */
    public function playlists(array $actor, mixed $requestedUsername): array
    {
        $this->requireCapability($actor, 'play');
        $this->validateOptionalUsername($actor, $requestedUsername);
        $items = [];
        for ($offset = 0; $offset <= 10_000 && count($items) < self::MAX_UNPAGED_ITEMS; $offset += 100) {
            $page = $this->playlists->page($actor, 100, $offset);
            foreach ($page['playlists'] as $playlist) {
                $items[] = $this->mapPlaylist($playlist);
            }
            if (count($items) >= $page['total'] || $page['playlists'] === []) {
                break;
            }
        }

        return ['playlists' => ['playlist' => array_slice($items, 0, self::MAX_UNPAGED_ITEMS)]];
    }

    /** Returns one readable playlist and its currently authorized ordered song occurrences. */
    public function playlist(array $actor, mixed $playlistId): array
    {
        $this->requireCapability($actor, 'play');
        $detail = $this->playlistDetail($actor, $this->requiredId($playlistId));

        return ['playlist' => $this->mapPlaylist($detail, true)];
    }

    /**
     * Creates a private playlist with optional repeated song IDs, or replaces one by playlistId.
     *
     * New playlists require `name`; the header and all occurrences commit atomically. The legacy
     * update form (`playlistId` plus repeated `songId`) preserves current metadata and replaces the
     * complete visible song order under optimistic locking. Unauthorized songs fail before writes,
     * and no partially created empty list is left behind.
     *
     * @param array<string, mixed> $parameters Merged and repeated-value-aware protocol parameters.
     * @return array{playlist: array<string, mixed>}
     */
    public function create(array $actor, array $parameters): array
    {
        $this->requireCapability($actor, 'create_playlist');
        $songIds = $this->idList($parameters['songId'] ?? null, 'songId');
        $playlistId = $parameters['playlistId'] ?? null;
        if ($playlistId !== null && $playlistId !== '') {
            $detail = $this->ownedDetail($actor, $this->requiredId($playlistId));
            if (($detail['kind'] ?? 'manual') === 'smart') {
                // Subsonic createPlaylist's playlistId form replaces materialized entries and has no
                // rule language. Treating it as a conversion would silently destroy smart semantics.
                throw new SubsonicRequestInvalid('Smart playlist entries cannot be replaced.');
            }
            $command = $this->completeCommand($detail, $songIds);
            $updated = $this->replaceAll($actor, (string) $detail['id'], $command);

            return ['playlist' => $this->mapPlaylist($updated, true)];
        }

        try {
            $metadata = $this->validator->create([
                'name' => $parameters['name'] ?? null,
                'description' => null,
                'visibility' => 'private',
            ]);
            $created = $this->playlists->createWithItems($actor, $metadata, $songIds);
        } catch (PlaylistInvalid $exception) {
            throw new SubsonicRequestInvalid('Playlist parameters are invalid.', previous: $exception);
        } catch (PlaylistItemNotFound $exception) {
            throw new SubsonicEntityNotFound('A playlist song was not found.', previous: $exception);
        }

        return ['playlist' => $this->mapPlaylist($created, true)];
    }

    /**
     * Applies optional metadata changes plus indexed removals and appended songs atomically.
     *
     * Removal indexes address the exact visible occurrence order returned by `getPlaylist`; all
     * indexes are evaluated against the pre-update snapshot, so duplicates remain distinguishable.
     * Stored entries that are no longer authorized are absent from that snapshot and are discarded
     * when a real update commits. A command with no changes is a read-only no-op and keeps version.
     *
     * @param array<string, mixed> $parameters Merged and repeated-value-aware protocol parameters.
     * @return array<string, mixed> Empty body as required by Subsonic updatePlaylist.
     */
    public function update(array $actor, array $parameters): array
    {
        $this->requireCapability($actor, 'create_playlist');
        $playlistId = $this->requiredId($parameters['playlistId'] ?? null);
        $detail = $this->ownedDetail($actor, $playlistId);
        $adds = $this->idList($parameters['songIdToAdd'] ?? null, 'songIdToAdd');
        $removals = $this->indexList($parameters['songIndexToRemove'] ?? null);
        $hasMetadata = array_key_exists('name', $parameters)
            || array_key_exists('comment', $parameters)
            || array_key_exists('public', $parameters);
        if (!$hasMetadata && $adds === [] && $removals === []) {
            return [];
        }
        $isSmart = ($detail['kind'] ?? 'manual') === 'smart';
        if ($isSmart && ($adds !== [] || $removals !== [])) {
            throw new SubsonicRequestInvalid('Smart playlist entries cannot be changed.');
        }

        $songIds = array_map(
            static fn (array $item): string => (string) $item['song']['id'],
            is_array($detail['songs'] ?? null) ? $detail['songs'] : [],
        );
        foreach ($removals as $index) {
            if (!array_key_exists($index, $songIds)) {
                throw new SubsonicRequestInvalid('A playlist removal index is invalid.');
            }
            unset($songIds[$index]);
        }
        $songIds = [...array_values($songIds), ...$adds];
        if (count($songIds) > 1000) {
            throw new SubsonicRequestInvalid('Playlist item limit was exceeded.');
        }

        $visibility = (string) ($detail['visibility'] ?? 'private');
        if (array_key_exists('public', $parameters)) {
            $visibility = $this->boolean($parameters['public']) ? 'server' : 'private';
        }
        try {
            $metadata = $this->validator->update([
                'expectedVersion' => (int) $detail['version'],
                'name' => array_key_exists('name', $parameters) ? $parameters['name'] : $detail['name'],
                'description' => array_key_exists('comment', $parameters)
                    ? $parameters['comment']
                    : ($detail['description'] ?? null),
                'visibility' => $visibility,
            ]);
            $command = $metadata + ['songIds' => $songIds];
        } catch (PlaylistInvalid $exception) {
            throw new SubsonicRequestInvalid('Playlist parameters are invalid.', previous: $exception);
        }
        if ($isSmart) {
            // Metadata remains editable because it does not materialize result rows. The shared
            // playlist version still serializes this command against concurrent Web rule edits.
            try {
                $this->playlists->update($actor, $playlistId, $metadata);
            } catch (PlaylistNotFound $exception) {
                throw new SubsonicEntityNotFound('Playlist was not found.', previous: $exception);
            } catch (PlaylistConflict $exception) {
                throw new SubsonicRequestInvalid('Playlist changed; retry the command.', previous: $exception);
            }
        } else {
            $this->replaceAll($actor, $playlistId, $command);
        }

        return [];
    }

    /** Deletes only an owned playlist; media files and songs are never changed. */
    public function delete(array $actor, mixed $playlistId): array
    {
        $this->requireCapability($actor, 'create_playlist');
        $playlistId = $this->requiredId($playlistId);
        $detail = $this->ownedDetail($actor, $playlistId);
        try {
            $this->playlists->delete($actor, $playlistId, (int) $detail['version']);
        } catch (PlaylistNotFound $exception) {
            throw new SubsonicEntityNotFound('Playlist was not found.', previous: $exception);
        } catch (PlaylistConflict $exception) {
            throw new SubsonicRequestInvalid('Playlist changed; retry the command.', previous: $exception);
        }

        return [];
    }

    /**
     * 将共享歌单摘要/详情映射为 Subsonic Playlist/PlaylistWithSongs。
     *
     * 只有共享投影确认存在合法自定义封面时才发布 `pl:` 类型化 coverArt ID；该 ID 只是后续图片请求
     * 的稳定定位符，不能绕过 getCoverArt 对歌单 owner/server 可见性的实时复验。没有封面时省略字段，
     * 不以首曲封面或不可读取的虚构 ID 冒充歌单图片。
     */
    private function mapPlaylist(array $playlist, bool $withEntries = false): array
    {
        $owner = is_array($playlist['owner'] ?? null) ? $playlist['owner'] : [];
        $result = [
            'id' => (string) ($playlist['id'] ?? ''),
            'name' => (string) ($playlist['name'] ?? ''),
            'owner' => (string) ($owner['username'] ?? $owner['displayName'] ?? ''),
            'public' => ($playlist['visibility'] ?? null) === 'server',
            'songCount' => (int) ($playlist['songCount'] ?? 0),
            'duration' => intdiv(max(0, (int) ($playlist['durationMs'] ?? 0)), 1000),
            'created' => (string) ($playlist['createdAt'] ?? ''),
            'changed' => (string) ($playlist['updatedAt'] ?? ''),
            'allowed' => ($playlist['canEdit'] ?? false) === true,
        ];
        if (is_string($playlist['description'] ?? null) && $playlist['description'] !== '') {
            $result['comment'] = $playlist['description'];
        }
        if (is_string($playlist['coverUrl'] ?? null) && $playlist['coverUrl'] !== '' && $result['id'] !== '') {
            $result['coverArt'] = 'pl:' . $result['id'];
        }
        if ($withEntries) {
            $result['entry'] = array_map(
                fn (array $item): array => $this->catalog->mapAuthorizedSong($item['song']),
                is_array($playlist['songs'] ?? null) ? $playlist['songs'] : [],
            );
        }

        return $result;
    }

    /** Returns an owned detail or preserves the same not-found boundary as private unreadable lists. */
    private function ownedDetail(array $actor, string $playlistId): array
    {
        $detail = $this->playlistDetail($actor, $playlistId);
        if (($detail['canEdit'] ?? false) !== true) {
            throw new SubsonicEntityNotFound('Playlist was not found.');
        }

        return $detail;
    }

    /** Converts PlaylistService read failures without exposing private playlist existence. */
    private function playlistDetail(array $actor, string $playlistId): array
    {
        try {
            return $this->playlists->detail($actor, $playlistId);
        } catch (PlaylistNotFound $exception) {
            throw new SubsonicEntityNotFound('Playlist was not found.', previous: $exception);
        }
    }

    /** Commits one complete state and maps domain validation/conflict failures to protocol errors. */
    private function replaceAll(array $actor, string $playlistId, array $command): array
    {
        try {
            return $this->playlists->replaceAll($actor, $playlistId, $command);
        } catch (PlaylistNotFound $exception) {
            throw new SubsonicEntityNotFound('Playlist was not found.', previous: $exception);
        } catch (PlaylistItemNotFound $exception) {
            throw new SubsonicEntityNotFound('A playlist song was not found.', previous: $exception);
        } catch (PlaylistConflict $exception) {
            throw new SubsonicRequestInvalid('Playlist changed; retry the command.', previous: $exception);
        }
    }

    /** Builds a complete replacement command from current safe metadata and a new song order. */
    private function completeCommand(array $detail, array $songIds): array
    {
        try {
            return $this->validator->update([
                'expectedVersion' => (int) ($detail['version'] ?? 0),
                'name' => $detail['name'] ?? null,
                'description' => $detail['description'] ?? null,
                'visibility' => $detail['visibility'] ?? null,
            ]) + ['songIds' => $songIds];
        } catch (PlaylistInvalid $exception) {
            throw new SubsonicRequestInvalid('Playlist parameters are invalid.', previous: $exception);
        }
    }

    /** Parses a scalar or repeated stable song-ID parameter while preserving duplicate occurrences. */
    private function idList(mixed $value, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 1000) {
            throw new SubsonicRequestInvalid($name . ' exceeds the playlist item limit.');
        }
        foreach ($values as $item) {
            if (!is_string($item) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $item) !== 1) {
                throw new SubsonicRequestInvalid($name . ' contains an invalid media ID.');
            }
        }

        return $values;
    }

    /** Parses simultaneous zero-based removal indexes without applying order-dependent shifts. */
    private function indexList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 1000) {
            throw new SubsonicRequestInvalid('Too many playlist removal indexes.');
        }
        $indexes = [];
        foreach ($values as $item) {
            if (is_int($item)) {
                $index = $item;
            } elseif (is_string($item) && preg_match('/^\d+$/', $item) === 1) {
                $index = (int) $item;
            } else {
                throw new SubsonicRequestInvalid('A playlist removal index is invalid.');
            }
            if ($index < 0 || $index > 999) {
                throw new SubsonicRequestInvalid('A playlist removal index is invalid.');
            }
            $indexes[$index] = $index;
        }

        return array_values($indexes);
    }

    /** Accepts only canonical Subsonic boolean values and rejects PHP truthiness ambiguity. */
    private function boolean(mixed $value): bool
    {
        if ($value === true || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 'false') {
            return false;
        }

        throw new SubsonicRequestInvalid('A playlist boolean parameter is invalid.');
    }

    /** Rejects malformed playlist IDs before any existence/ownership query. */
    private function requiredId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid('A required playlist ID is missing or invalid.');
        }

        return $value;
    }

    /** Allows getPlaylists to name only the authenticated identity, preventing user enumeration. */
    private function validateOptionalUsername(array $actor, mixed $requestedUsername): void
    {
        if ($requestedUsername === null || $requestedUsername === '') {
            return;
        }
        if (!is_string($requestedUsername) || strlen($requestedUsername) > 254) {
            throw new SubsonicRequestInvalid('Playlist username is invalid.');
        }
        $username = is_string($actor['username'] ?? null) ? $actor['username'] : '';
        if ($username === '' || strcasecmp($username, trim($requestedUsername)) !== 0) {
            throw new SubsonicEntityNotFound('Requested Subsonic user was not found.');
        }
    }

    /** Enforces a current global capability independently from playlist visibility or ownership. */
    private function requireCapability(array $actor, string $capability): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array($capability, $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic playlist operation is not authorized.');
        }
    }
}
