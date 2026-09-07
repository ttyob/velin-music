<?php

declare(strict_types=1);

namespace app\application\Subsonic;

/**
 * Builds authorization-safe Subsonic system, library, and current-user projections.
 *
 * This service deliberately consumes the principal already produced by SubsonicAuthenticator so
 * it cannot widen media scope through a separate query. Physical paths, credential readiness, role
 * IDs, Session state, and inactive libraries never enter protocol responses. Capability booleans
 * reflect current Velin authorization; they do not claim that every optional Subsonic endpoint has
 * already been implemented. OpenSubsonic extensions are a separate explicit allowlist and must be
 * expanded only after contract tests for the complete extension pass.
 */
final class SubsonicSystemService
{
    /** Returns the empty endpoint body used by authenticated `ping` requests. */
    public function ping(): array
    {
        return [];
    }

    /**
     * Returns the compatibility license object required by Subsonic clients.
     *
     * `valid=true` only means this self-hosted server is not imposing Subsonic's historical trial
     * limit. It is not a commercial license assertion and therefore includes no email or expiry.
     *
     * @return array{license: array{valid: bool}}
     */
    public function license(): array
    {
        return ['license' => ['valid' => true]];
    }

    /**
     * Publicly advertises only fully implemented OpenSubsonic extensions.
     *
     * The discovery endpoint itself is public per OpenSubsonic v1. `formPost` version 1 is safe to
     * announce because all compatibility routes accept URL-encoded POST bodies with the same
     * validation and authentication as GET. `transcoding` version 1 is announced only after the
     * MP3/AAC/Opus negotiation, async supervision, limits, disconnect cleanup, and output contract
     * tests all pass. `songLyrics` versions 1 and 2 are declared only after line/multilingual mapping,
     * enhanced word cues, empty results, and JSON/XML contracts pass without weakening live grants.
     * `transcodeOffset` v1 is backed by bounded positive stream offsets in the supervised FFmpeg
     * plan. `indexBasedQueue` v1 is declared only with both index endpoints, duplicate preservation,
     * empty-queue rules, live authorization and JSON/XML mapping covered by tests. `playbackReport`
     * v1 is declared only with strict state/rate validation, ignoreScrobble isolation, account-level
     * sessions, live grants and estimated now-playing timeline fields covered by tests.
     * `topSongsByArtistId` v1 只有在 getTopSongs 已实现规范 `id` 优先级、实时授权和名称兼容回退后声明。
     * `songSimilarity` v1 复用 getSimilarSongs/getSimilarSongs2：种子与候选都重新证明实时权限，并只用
     * 共同艺人、同专辑和共同流派做本地确定性排序；没有关系时返回空集合，不在协议请求中访问外部服务。
     *
     * @return array{openSubsonicExtensions: list<array{name: string, versions: list<int>}>}
     */
    public function openSubsonicExtensions(): array
    {
        return [
            'openSubsonicExtensions' => [
                ['name' => 'formPost', 'versions' => [1]],
                ['name' => 'transcoding', 'versions' => [1]],
                ['name' => 'transcodeOffset', 'versions' => [1]],
                ['name' => 'indexBasedQueue', 'versions' => [1]],
                ['name' => 'playbackReport', 'versions' => [1]],
                ['name' => 'songLyrics', 'versions' => [1, 2]],
                ['name' => 'topSongsByArtistId', 'versions' => [1]],
                ['name' => 'songSimilarity', 'versions' => [1]],
            ],
        ];
    }

    /**
     * Maps active, currently granted libraries to opaque Subsonic music-folder IDs.
     *
     * A principal without global `play` receives an empty folder collection even if stale library
     * grants exist. IDs are stable strings by Velin contract and never derive from filesystem roots.
     *
     * @param array<string, mixed> $actor Authenticated principal with live capabilities/libraries.
     * @return array{musicFolders: array{musicFolder: list<array{id: string, name: string}>}}
     */
    public function musicFolders(array $actor): array
    {
        $libraries = $this->can($actor, 'play') && is_array($actor['libraries'] ?? null)
            ? $actor['libraries']
            : [];
        $folders = [];
        foreach ($libraries as $library) {
            if (!is_array($library)
                || !is_string($library['id'] ?? null)
                || !is_string($library['name'] ?? null)) {
                continue;
            }
            $folders[] = ['id' => $library['id'], 'name' => $library['name']];
        }

        return ['musicFolders' => ['musicFolder' => $folders]];
    }

    /**
     * Returns only the authenticated user's Subsonic role projection.
     *
     * `username` is required by the protocol but cannot be used to enumerate another account in
     * this first compatibility slice. A mismatch uses the same not-found exception regardless of
     * whether the requested identity exists. Folder IDs repeat the live authorization snapshot.
     * Unsupported podcast, comments, video conversion, and external scrobble abilities remain false.
     *
     * @param array<string, mixed> $actor Authenticated principal from SubsonicAuthenticator.
     * @throws SubsonicRequestInvalid Missing, array, empty, or oversized username parameter.
     * @throws SubsonicEntityNotFound Requested username is not the authenticated identity.
     * @return array{user: array<string, mixed>}
     */
    public function user(array $actor, mixed $requestedUsername): array
    {
        if (!is_string($requestedUsername)) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is missing.');
        }
        $requestedUsername = trim($requestedUsername);
        if ($requestedUsername === '' || strlen($requestedUsername) > 254) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is invalid.');
        }
        $username = is_string($actor['username'] ?? null) ? $actor['username'] : '';
        if ($username === '' || strcasecmp($username, $requestedUsername) !== 0) {
            throw new SubsonicEntityNotFound('Requested Subsonic user was not found.');
        }

        $folders = [];
        if ($this->can($actor, 'play') && is_array($actor['libraries'] ?? null)) {
            foreach ($actor['libraries'] as $library) {
                if (is_array($library) && is_string($library['id'] ?? null)) {
                    $folders[] = $library['id'];
                }
            }
        }

        $isSuperAdmin = ($actor['isSuperAdmin'] ?? false) === true;
        $email = is_string($actor['email'] ?? null) ? $actor['email'] : '';

        return ['user' => [
            'folder' => $folders,
            'username' => $username,
            'email' => $email,
            'scrobblingEnabled' => false,
            'adminRole' => $isSuperAdmin,
            'settingsRole' => $isSuperAdmin || $this->can($actor, 'manage_system'),
            'downloadRole' => $this->can($actor, 'download'),
            // 上传只属于受 CSRF 保护的管理后台，Subsonic 协议身份永远不能获得上传角色。
            'uploadRole' => false,
            'playlistRole' => $this->can($actor, 'create_playlist'),
            'coverArtRole' => $this->can($actor, 'play'),
            'commentRole' => false,
            'podcastRole' => false,
            'streamRole' => $this->can($actor, 'play'),
            'jukeboxRole' => $this->can($actor, 'jukebox'),
            'videoConversionRole' => false,
        ]];
    }

    /**
     * Returns only the authenticated identity under the administrative users container.
     *
     * Some clients call getUsers for account settings. Velin does not expose its account directory
     * through this compatibility surface, including to administrators; complete user management
     * remains on audited internal APIs. Reusing getUser keeps capabilities and folder grants live.
     */
    public function users(array $actor): array
    {
        $username = is_string($actor['username'] ?? null) ? $actor['username'] : null;
        $current = $this->user($actor, $username);

        return ['users' => ['user' => [$current['user']]]];
    }

    /** Tests one exact global capability without accepting truthy or malformed values. */
    private function can(array $actor, string $capability): bool
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];

        return in_array($capability, $capabilities, true);
    }
}
