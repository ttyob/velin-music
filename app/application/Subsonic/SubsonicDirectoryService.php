<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Media\MediaDetailNotFound;
use app\application\Media\MediaQueryService;
use Symfony\Component\Uid\Ulid;

/**
 * Builds the path-free virtual directory tree required by Subsonic folder clients.
 *
 * The tree is synthesized from already authorized MediaQueryService projections, never inventory
 * paths: music library -> artist -> album -> song. Artist and album directory IDs carry only stable
 * opaque catalog IDs plus their virtual parent context, allowing getMusicDirectory to revalidate the
 * exact branch. Song IDs remain canonical media IDs for later stream/getSong calls. Every operation
 * requires current `play`, and forged, stale, revoked, or malformed nodes share the not-found path.
 */
final readonly class SubsonicDirectoryService
{
    private const ALL_LIBRARIES = 'all';
    private const MAX_INDEX_ARTISTS = 10_100;

    /** Injects the shared authorization-aware catalog boundary; no repository is duplicated here. */
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(includeOpenSubsonicFacts: true),
    )
    {
    }

    /**
     * Returns artists grouped under a conservative ASCII initial for legacy clients.
     *
     * `musicFolderId` is optional. When supplied it must name one live library in the principal's
     * resolved grant snapshot; invalid and unauthorized IDs are indistinguishable. Non-ASCII and
     * symbol-leading sort names are grouped under `#` because transliteration is not yet a stable
     * indexed field. Subsonic has no pagination here, so this first release caps the response at
     * 10,100 visible artists to bound process memory while supporting the documented initial scale.
     *
     * @param array<string, mixed> $actor Authenticated principal with live capabilities/libraries.
     * @return array{indexes: array{lastModified: int, ignoredArticles: string, index: list<array{name: string, artist: list<array{id: string, name: string}>}>}}
     * @throws SubsonicAuthorizationDenied Principal lacks global play capability.
     * @throws SubsonicRequestInvalid musicFolderId has an invalid scalar shape.
     * @throws SubsonicEntityNotFound Requested music folder is outside current authorization.
     */
    public function indexes(array $actor, mixed $musicFolderId): array
    {
        $this->requirePlay($actor);
        $scope = $this->libraryScope($actor, $musicFolderId);
        $artists = $this->artists($actor, $scope === self::ALL_LIBRARIES ? null : $scope);
        $groups = [];
        foreach ($artists as $artist) {
            $name = is_string($artist['name'] ?? null) ? $artist['name'] : '';
            $artistId = is_string($artist['id'] ?? null) ? $artist['id'] : '';
            if ($name === '' || !$this->isCatalogId($artistId)) {
                continue;
            }
            $initial = $this->indexInitial(
                is_string($artist['sortName'] ?? null) && $artist['sortName'] !== ''
                    ? $artist['sortName']
                    : $name,
            );
            $entry = [
                'id' => $this->artistNodeId($scope, $artistId),
                'name' => $name,
            ];
            if (is_string($artist['imageUrl'] ?? null)) {
                $entry['coverArt'] = 'ar:' . $artistId;
            }
            $groups[$initial][] = $entry;
        }
        uksort($groups, static fn (string $left, string $right): int => $left === '#'
            ? -1
            : ($right === '#' ? 1 : strcmp($left, $right)));
        $indexes = [];
        foreach ($groups as $name => $groupArtists) {
            $indexes[] = ['name' => $name, 'artist' => $groupArtists];
        }

        return ['indexes' => [
            // Milliseconds match the Subsonic contract. A future catalog revision counter can
            // replace wall time without changing the response shape.
            'lastModified' => time() * 1000,
            'ignoredArticles' => 'The El La Los Las Le Les',
            'index' => $indexes,
        ]];
    }

    /**
     * Resolves one virtual root/artist/album node and returns only authorized child projections.
     *
     * A raw ID is accepted only as one of the principal's library roots. Composite nodes are parsed
     * with an exact grammar and then re-proved through MediaQueryService before any child is mapped.
     * The optional song `path` is label-derived (`Artist/Album/01 - Song.ext`) and therefore remains
     * unrelated to relative/resolved inventory paths. This method is read-only and idempotent.
     *
     * @param array<string, mixed> $actor Authenticated principal with live capabilities/libraries.
     * @throws SubsonicAuthorizationDenied Principal lacks global play capability.
     * @throws SubsonicRequestInvalid Missing, array, or oversized directory ID.
     * @throws SubsonicEntityNotFound Node is malformed, stale, missing, or unauthorized.
     * @return array{directory: array<string, mixed>}
     */
    public function directory(array $actor, mixed $directoryId): array
    {
        $this->requirePlay($actor);
        if (!is_string($directoryId)) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is missing.');
        }
        $directoryId = trim($directoryId);
        if ($directoryId === '' || strlen($directoryId) > 160) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is invalid.');
        }

        $library = $this->findLibrary($actor, $directoryId);
        if ($library !== null) {
            return $this->libraryDirectory($actor, $library);
        }
        if (preg_match('/^vm:artist:(all|[0-9A-HJKMNP-TV-Z]{26}):([0-9A-HJKMNP-TV-Z]{26})$/', $directoryId, $match) === 1) {
            return $this->artistDirectory($actor, $match[1], $match[2]);
        }
        if (preg_match('/^vm:album:(all|[0-9A-HJKMNP-TV-Z]{26}):([0-9A-HJKMNP-TV-Z]{26}):([0-9A-HJKMNP-TV-Z]{26})$/', $directoryId, $match) === 1) {
            return $this->albumDirectory($actor, $match[1], $match[2], $match[3]);
        }

        throw new SubsonicEntityNotFound('Virtual music directory was not found.');
    }

    /** @param array{id: string, name: string, accessLevel: string} $library */
    private function libraryDirectory(array $actor, array $library): array
    {
        $children = [];
        foreach ($this->artists($actor, $library['id']) as $artist) {
            $artistId = is_string($artist['id'] ?? null) ? $artist['id'] : '';
            $name = is_string($artist['name'] ?? null) ? $artist['name'] : '';
            if (!$this->isCatalogId($artistId) || $name === '') {
                continue;
            }
            $child = [
                'id' => $this->artistNodeId($library['id'], $artistId),
                'parent' => $library['id'],
                'isDir' => true,
                'title' => $name,
                'artist' => $name,
                'artistId' => $artistId,
                'type' => 'music',
                'mediaType' => 'artist',
            ];
            if (is_string($artist['imageUrl'] ?? null)) {
                $child['coverArt'] = 'ar:' . $artistId;
            }
            $this->personalFields($child, is_array($artist['preferences'] ?? null)
                ? $artist['preferences'] : []);
            $children[] = $child;
        }

        return ['directory' => [
            'id' => $library['id'],
            'name' => $library['name'],
            'child' => $children,
        ]];
    }

    /** Returns album directory children after proving artist and optional library scope. */
    private function artistDirectory(array $actor, string $scope, string $artistId): array
    {
        $this->assertScope($actor, $scope);
        try {
            $detail = $this->media->artistDetail($actor, $artistId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Virtual music directory was not found.', previous: $exception);
        }
        $artistName = is_string($detail['artist']['name'] ?? null) ? $detail['artist']['name'] : '';
        $parentId = $this->artistNodeId($scope, $artistId);
        $children = [];
        foreach ($detail['albums'] as $album) {
            if (!is_array($album) || !$this->albumMatchesScope($album, $scope)) {
                continue;
            }
            $albumId = is_string($album['id'] ?? null) ? $album['id'] : '';
            $title = is_string($album['title'] ?? null) ? $album['title'] : '';
            if (!$this->isCatalogId($albumId) || $title === '') {
                continue;
            }
            $child = [
                'id' => $this->albumNodeId($scope, $artistId, $albumId),
                'parent' => $parentId,
                'isDir' => true,
                'title' => $title,
                'album' => $title,
                'artist' => $artistName,
                'albumId' => $albumId,
                'artistId' => $artistId,
                'type' => 'music',
                'mediaType' => 'album',
            ];
            if (is_string($album['coverUrl'] ?? null)) {
                $child['coverArt'] = 'al:' . $albumId;
            }
            $this->personalFields($child, is_array($album['preferences'] ?? null)
                ? $album['preferences'] : []);
            $children[] = $child;
        }

        $directory = [
            'id' => $parentId,
            'name' => $artistName,
            'child' => $children,
        ];
        if ($scope !== self::ALL_LIBRARIES) {
            $directory['parent'] = $scope;
        }

        return ['directory' => $directory];
    }

    /** Returns ordered song children after re-proving that the album belongs to this artist branch. */
    private function albumDirectory(array $actor, string $scope, string $artistId, string $albumId): array
    {
        $this->assertScope($actor, $scope);
        try {
            $artist = $this->media->artistDetail($actor, $artistId);
            $album = $this->media->albumDetail($actor, $albumId);
        } catch (MediaDetailNotFound $exception) {
            throw new SubsonicEntityNotFound('Virtual music directory was not found.', previous: $exception);
        }
        $branchAlbum = null;
        foreach ($artist['albums'] as $candidate) {
            if (is_array($candidate)
                && ($candidate['id'] ?? null) === $albumId
                && $this->albumMatchesScope($candidate, $scope)) {
                $branchAlbum = $candidate;
                break;
            }
        }
        if ($branchAlbum === null || !$this->albumMatchesScope($album['album'], $scope)) {
            throw new SubsonicEntityNotFound('Virtual music directory was not found.');
        }

        $nodeId = $this->albumNodeId($scope, $artistId, $albumId);
        $artistName = is_string($artist['artist']['name'] ?? null) ? $artist['artist']['name'] : 'Unknown Artist';
        $albumName = is_string($album['album']['title'] ?? null) ? $album['album']['title'] : 'Unknown Album';
        $children = [];
        foreach ($album['songs'] as $song) {
            if (is_array($song)) {
                $children[] = $this->songChild($song, $nodeId, $artistName, $albumName);
            }
        }

        return ['directory' => [
            'id' => $nodeId,
            'parent' => $this->artistNodeId($scope, $artistId),
            'name' => $albumName,
            'child' => $children,
        ]];
    }

    /**
     * 把路径无关的授权歌曲投影映射为虚拟目录使用的 Subsonic `Child`。
     *
     * 调用前歌曲已经通过专辑、艺人分支和实时 library grant 三重校验；这里固定声明 `isDir=false`、
     * `isVideo=false` 和 `type=music`，使文件夹式客户端不会把音频条目误判为目录或视频。公开 `path`
     * 仅由标签生成，不含库存路径。专辑艺人与个人播放/评分事实已由开启 OpenSubsonic 扩展的共享查询
     * 批量加载；没有真实关系时省略。映射无数据库写入，任一非法歌曲 ID 会让伪造目录分支失败关闭。
     */
    private function songChild(array $song, string $parentId, string $artistName, string $albumName): array
    {
        $songId = is_string($song['id'] ?? null) ? $song['id'] : '';
        if (!$this->isCatalogId($songId)) {
            throw new SubsonicEntityNotFound('Virtual song was not found.');
        }
        $title = is_string($song['title'] ?? null) && $song['title'] !== '' ? $song['title'] : 'Unknown Song';
        $artists = is_array($song['artists'] ?? null) ? $song['artists'] : [];
        $displayArtists = [];
        foreach ($artists as $artist) {
            if (is_array($artist) && is_string($artist['name'] ?? null) && $artist['name'] !== '') {
                $displayArtists[] = $artist['name'];
            }
        }
        $displayArtist = $displayArtists === [] ? $artistName : implode(', ', $displayArtists);
        $audio = is_array($song['audio'] ?? null) ? $song['audio'] : [];
        $suffix = $this->audioSuffix($audio);
        $track = is_int($song['trackNumber'] ?? null) ? $song['trackNumber'] : null;
        $child = [
            'id' => $songId,
            'parent' => $parentId,
            'isDir' => false,
            'isVideo' => false,
            'title' => $title,
            'album' => $albumName,
            'artist' => $displayArtist,
            'contentType' => $this->contentType($suffix),
            'suffix' => $suffix,
            'duration' => intdiv(max(0, (int) ($song['durationMs'] ?? 0)), 1000),
            'path' => $this->virtualPath($artistName, $albumName, $title, $track, $suffix),
            'albumId' => is_string($song['album']['id'] ?? null) ? $song['album']['id'] : '',
            'type' => 'music',
            'mediaType' => 'song',
        ];
        $albumArtists = is_array($song['album']['artists'] ?? null)
            ? $this->artistReferences($song['album']['artists'])
            : [];
        if ($albumArtists !== []) {
            $child['albumArtists'] = $albumArtists;
            $child['displayAlbumArtist'] = implode(', ', array_column($albumArtists, 'name'));
        }
        $firstArtistId = is_array($artists[0] ?? null) && is_string($artists[0]['id'] ?? null)
            ? $artists[0]['id']
            : null;
        $this->optional($child, 'artistId', $firstArtistId);
        $this->optional($child, 'track', $track);
        $this->optional($child, 'year', is_int($song['releaseYear'] ?? null) ? $song['releaseYear'] : null);
        $this->optional($child, 'discNumber', is_int($song['discNumber'] ?? null) ? $song['discNumber'] : null);
        $this->optional($child, 'bitRate', is_int($audio['bitrate'] ?? null) ? intdiv($audio['bitrate'], 1000) : null);
        $this->optional($child, 'bitDepth', is_int($audio['bitDepth'] ?? null) ? $audio['bitDepth'] : null);
        $this->optional($child, 'samplingRate', is_int($audio['sampleRate'] ?? null) ? $audio['sampleRate'] : null);
        $this->optional($child, 'channelCount', is_int($audio['channels'] ?? null) ? $audio['channels'] : null);
        if (Ulid::isValid($songId)) {
            $child['created'] = Ulid::fromString($songId)->getDateTime()
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($song['album']['coverUrl'] ?? null) && $child['albumId'] !== '') {
            $child['coverArt'] = str_starts_with($song['album']['coverUrl'], '/api/v1/songs/')
                ? 'so:' . $songId
                : 'al:' . $child['albumId'];
        }
        $preferences = is_array($song['preferences'] ?? null) ? $song['preferences'] : [];
        $this->personalFields($child, $preferences);

        return $child;
    }

    /**
     * 输出目录对象中真实存在的当前账号个人字段。
     *
     * 输入来自 MediaQueryService 对已授权对象的账号级投影；播放时间、收藏和评分均不能授予可见性。
     * 空状态直接省略，避免把 0 或 false 误当协议事实。该方法只修改响应数组，不刷新任何个人时间戳。
     */
    private function personalFields(array &$target, array $preferences): void
    {
        if (is_string($preferences['playedAt'] ?? null) && $preferences['playedAt'] !== '') {
            $target['played'] = $preferences['playedAt'];
        }
        if (($preferences['favorite'] ?? false) === true && is_string($preferences['favoritedAt'] ?? null)) {
            $target['starred'] = $preferences['favoritedAt'];
        }
        if (is_int($preferences['rating'] ?? null)
            && $preferences['rating'] >= 1 && $preferences['rating'] <= 5) {
            $target['userRating'] = $preferences['rating'];
        }
    }

    /** @return list<array{id:string,name:string}> */
    private function artistReferences(array $artists): array
    {
        $result = [];
        foreach ($artists as $artist) {
            if (is_array($artist)
                && is_string($artist['id'] ?? null) && $artist['id'] !== ''
                && is_string($artist['name'] ?? null) && $artist['name'] !== '') {
                $result[] = ['id' => $artist['id'], 'name' => $artist['name']];
            }
        }

        return $result;
    }

    /** Fetches bounded pages from the shared authorization-aware artist service without path data. */
    private function artists(array $actor, ?string $libraryId): array
    {
        $artists = [];
        for ($offset = 0; $offset <= 10_000 && count($artists) < self::MAX_INDEX_ARTISTS; $offset += 100) {
            $page = $this->media->artists($actor, $libraryId, 100, $offset);
            foreach ($page['artists'] as $artist) {
                $artists[] = $artist;
            }
            if (count($artists) >= $page['total'] || $page['artists'] === []) {
                break;
            }
        }

        return array_slice($artists, 0, self::MAX_INDEX_ARTISTS);
    }

    /** Resolves null to all libraries or proves one requested library against the actor snapshot. */
    private function libraryScope(array $actor, mixed $musicFolderId): string
    {
        if ($musicFolderId === null || $musicFolderId === '') {
            return self::ALL_LIBRARIES;
        }
        if (!is_string($musicFolderId) || strlen($musicFolderId) > 64) {
            throw new SubsonicRequestInvalid('A Subsonic parameter is invalid.');
        }
        $library = $this->findLibrary($actor, trim($musicFolderId));
        if ($library === null) {
            throw new SubsonicEntityNotFound('Music folder was not found.');
        }

        return $library['id'];
    }

    /** @return array{id: string, name: string, accessLevel: string}|null */
    private function findLibrary(array $actor, string $libraryId): ?array
    {
        $libraries = is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [];
        foreach ($libraries as $library) {
            if (is_array($library)
                && ($library['id'] ?? null) === $libraryId
                && is_string($library['name'] ?? null)
                && is_string($library['accessLevel'] ?? null)) {
                return [
                    'id' => $libraryId,
                    'name' => $library['name'],
                    'accessLevel' => $library['accessLevel'],
                ];
            }
        }

        return null;
    }

    /** Ensures a composite scope remains all-authorized-libraries or one current grant. */
    private function assertScope(array $actor, string $scope): void
    {
        if ($scope !== self::ALL_LIBRARIES && $this->findLibrary($actor, $scope) === null) {
            throw new SubsonicEntityNotFound('Virtual music directory was not found.');
        }
    }

    /** Tests an album projection's library object without accepting a missing scope. */
    private function albumMatchesScope(array $album, string $scope): bool
    {
        return $scope === self::ALL_LIBRARIES
            || (is_array($album['library'] ?? null) && ($album['library']['id'] ?? null) === $scope);
    }

    /** Rejects browse calls for authenticated accounts whose global play permission was revoked. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic browsing requires play capability.');
        }
    }

    /** Generates the stable artist directory grammar; no name/path text enters the identifier. */
    private function artistNodeId(string $scope, string $artistId): string
    {
        return 'vm:artist:' . $scope . ':' . $artistId;
    }

    /** Generates a branch-specific album node so its parent can be revalidated on every request. */
    private function albumNodeId(string $scope, string $artistId, string $albumId): string
    {
        return 'vm:album:' . $scope . ':' . $artistId . ':' . $albumId;
    }

    /** Existing catalog services use ULIDs; virtual IDs retain that same closed input grammar. */
    private function isCatalogId(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1;
    }

    /** Uses ASCII initials for predictable legacy grouping and `#` for all other leading scripts. */
    private function indexInitial(string $sortName): string
    {
        $first = function_exists('mb_substr') ? mb_substr(trim($sortName), 0, 1, 'UTF-8') : substr(trim($sortName), 0, 1);
        $first = strtoupper($first);

        return preg_match('/^[A-Z]$/', $first) === 1 ? $first : '#';
    }

    /** Selects a safe lower-case extension from indexed container/codec metadata only. */
    private function audioSuffix(array $audio): string
    {
        foreach ([$audio['container'] ?? null, $audio['codec'] ?? null] as $candidate) {
            if (is_string($candidate)) {
                $candidate = strtolower(trim($candidate));
                if (preg_match('/^[a-z0-9]{1,10}$/', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return 'bin';
    }

    /** Maps known audio suffixes without trusting an indexed value as a response header. */
    private function contentType(string $suffix): string
    {
        return match ($suffix) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'm4a', 'mp4' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg', 'opus' => 'audio/ogg',
            'wav', 'wave' => 'audio/wav',
            'aif', 'aiff' => 'audio/aiff',
            'wma' => 'audio/x-ms-wma',
            'ape' => 'audio/x-ape',
            default => 'application/octet-stream',
        };
    }

    /** Builds a label-only relative path after removing separators/control characters per segment. */
    private function virtualPath(string $artist, string $album, string $title, ?int $track, string $suffix): string
    {
        $trackPrefix = $track === null ? '' : str_pad((string) $track, 2, '0', STR_PAD_LEFT) . ' - ';

        return $this->virtualSegment($artist, 'Unknown Artist') . '/'
            . $this->virtualSegment($album, 'Unknown Album') . '/'
            . $trackPrefix . $this->virtualSegment($title, 'Unknown Song') . '.' . $suffix;
    }

    /** Sanitizes one metadata label without ever consulting a filesystem path. */
    private function virtualSegment(string $value, string $fallback): string
    {
        $value = preg_replace('/[\\x00-\\x1F\\x7F\\\\\/]+/u', ' - ', trim($value));
        $value = is_string($value) ? preg_replace('/\\s+/u', ' ', $value) : '';
        $value = is_string($value) ? trim($value, " .\t\n\r\0\x0B") : '';

        return $value === '' ? $fallback : $value;
    }

    /** Adds an optional compatible attribute only when its source type is present and valid. */
    private function optional(array &$target, string $name, string|int|null $value): void
    {
        if ($value !== null && $value !== '') {
            $target[$name] = $value;
        }
    }
}
