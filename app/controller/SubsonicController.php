<?php

declare(strict_types=1);

namespace app\controller;

use app\application\Subsonic\SubsonicAuthenticationFailed;
use app\application\Subsonic\SubsonicAuthenticator;
use app\application\Subsonic\SubsonicAuthorizationDenied;
use app\application\Subsonic\SubsonicBookmarkService;
use app\application\Subsonic\SubsonicCatalogService;
use app\application\Subsonic\SubsonicDirectoryService;
use app\application\Subsonic\SubsonicEntityNotFound;
use app\application\Subsonic\SubsonicFeatureUnsupported;
use app\application\Subsonic\SubsonicLyricsService;
use app\application\Subsonic\SubsonicMediaService;
use app\application\Subsonic\SubsonicNowPlayingService;
use app\application\Subsonic\SubsonicPlaylistService;
use app\application\Subsonic\SubsonicPlaybackReportService;
use app\application\Subsonic\SubsonicPreferenceService;
use app\application\Subsonic\SubsonicRequestInvalid;
use app\application\Subsonic\SubsonicScrobbleService;
use app\application\Subsonic\SubsonicScanStatusService;
use app\application\Subsonic\SubsonicQueueService;
use app\application\Subsonic\SubsonicRadioService;
use app\application\Subsonic\SubsonicSystemService;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\RequestContext;
use app\http\SubsonicResponseFactory;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Adapts `/rest/{method}` GET/HEAD/POST calls to the first Subsonic v1.16.1 application slice.
 *
 * Query and URL-encoded form parameters share one bounded validation path, with form values taking
 * precedence as required by OpenSubsonic `formPost`. The discovery endpoint is public by protocol;
 * every other method first performs salt+token authentication and resolves live capabilities and
 * library grants. Plaintext `p` is never read. Unknown endpoints return a protocol error instead of
 * falling through to HTML, and all known failures retain HTTP 200 for legacy client compatibility.
 */
final class SubsonicController
{
    /**
     * Normalizes the optional `.view`/`.json` suffix, authenticates, and dispatches a strict allowlist.
     *
     * Response format is selected independently by SubsonicResponseFactory (`f` then `.json`). Raw
     * submitted credentials and values are never logged or copied into public error messages.
     */
    public function handle(Request $request, string $method): Response
    {
        $requestId = RequestContext::requestId();
        $method = (string) preg_replace('/\.(?:view|json)$/', '', $method);
        if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $method) !== 1) {
            return SubsonicResponseFactory::error($request, 0, 'Requested endpoint is not supported.', $requestId);
        }

        $parameters = $this->parameters($request, $method);
        $service = new SubsonicSystemService();
        if ($method === 'getOpenSubsonicExtensions') {
            return SubsonicResponseFactory::success(
                $request,
                $service->openSubsonicExtensions(),
                $requestId,
            );
        }

        try {
            $actor = (new SubsonicAuthenticator())->authenticate($parameters);
            $directory = new SubsonicDirectoryService();
            $catalog = new SubsonicCatalogService();
            $playlists = new SubsonicPlaylistService();
            $playbackReports = new SubsonicPlaybackReportService();
            $preferences = new SubsonicPreferenceService();
            $scrobbles = new SubsonicScrobbleService();
            $queue = new SubsonicQueueService();
            $lyrics = new SubsonicLyricsService();
            $bookmarks = new SubsonicBookmarkService();
            $media = new SubsonicMediaService();
            $nowPlaying = new SubsonicNowPlayingService();
            $scanStatus = new SubsonicScanStatusService();
            $radios = new SubsonicRadioService();
            $body = match ($method) {
                'ping' => $service->ping(),
                'getLicense' => $service->license(),
                'getMusicFolders' => $service->musicFolders($actor),
                'getUser' => $service->user($actor, $parameters['username'] ?? null),
                'getUsers' => $service->users($actor),
                'getIndexes' => $directory->indexes($actor, $parameters['musicFolderId'] ?? null),
                'getMusicDirectory' => $directory->directory($actor, $parameters['id'] ?? null),
                'getSong' => $catalog->song($actor, $parameters['id'] ?? null),
                'getArtists' => $catalog->artists($actor, $parameters['musicFolderId'] ?? null),
                'getArtist' => $catalog->artist($actor, $parameters['id'] ?? null),
                'getAlbum' => $catalog->album($actor, $parameters['id'] ?? null),
                'getArtistInfo' => $catalog->artistInfo($actor, $parameters['id'] ?? null, false),
                'getArtistInfo2' => $catalog->artistInfo($actor, $parameters['id'] ?? null, true),
                'getAlbumInfo' => $catalog->albumInfo($actor, $parameters['id'] ?? null, false),
                'getAlbumInfo2' => $catalog->albumInfo($actor, $parameters['id'] ?? null, true),
                'getTopSongs' => $catalog->topSongs($actor, $parameters),
                'getSimilarSongs' => $catalog->similarSongs($actor, $parameters, false),
                'getSimilarSongs2' => $catalog->similarSongs($actor, $parameters, true),
                'getGenres' => $catalog->genres($actor, $parameters['musicFolderId'] ?? null),
                'getRandomSongs' => $catalog->randomSongs($actor, $parameters),
                'getSongsByGenre' => $catalog->songsByGenre($actor, $parameters),
                'getAlbumList' => $catalog->albumList($actor, $parameters),
                'getAlbumList2' => $catalog->albumList2($actor, $parameters),
                'getStarred' => $catalog->starred($actor, $parameters['musicFolderId'] ?? null),
                'getStarred2' => $catalog->starred2($actor, $parameters['musicFolderId'] ?? null),
                'getNowPlaying' => $nowPlaying->get($actor),
                'getScanStatus' => $scanStatus->get($actor),
                'startScan' => $scanStatus->start($actor, $parameters, $requestId),
                'getPlaylists' => $playlists->playlists($actor, $parameters['username'] ?? null),
                'getPlaylist' => $playlists->playlist($actor, $parameters['id'] ?? null),
                'createPlaylist' => $playlists->create($actor, $parameters),
                'updatePlaylist' => $playlists->update($actor, $parameters),
                'deletePlaylist' => $playlists->delete($actor, $parameters['id'] ?? null),
                'star' => $preferences->favorite($actor, $parameters, true),
                'unstar' => $preferences->favorite($actor, $parameters, false),
                'setRating' => $preferences->rating($actor, $parameters),
                'scrobble' => $scrobbles->record($actor, $parameters),
                'reportPlayback' => $playbackReports->report($actor, $parameters),
                'getPlayQueue' => $queue->get($actor),
                'savePlayQueue' => $queue->save($actor, $parameters),
                'getPlayQueueByIndex' => $queue->getByIndex($actor),
                'savePlayQueueByIndex' => $queue->saveByIndex($actor, $parameters),
                'getLyrics' => $lyrics->legacy($actor, $parameters),
                'getLyricsBySongId' => $lyrics->bySongId(
                    $actor,
                    $parameters['id'] ?? null,
                    $parameters['enhanced'] ?? null,
                ),
                'getBookmarks' => $bookmarks->get($actor),
                'createBookmark' => $bookmarks->create($actor, $parameters),
                'deleteBookmark' => $bookmarks->delete($actor, $parameters['id'] ?? null),
                'getInternetRadioStations' => $radios->get($actor),
                'createInternetRadioStation' => $radios->create($actor, $parameters, $requestId),
                'updateInternetRadioStation' => $radios->update($actor, $parameters, $requestId),
                'deleteInternetRadioStation' => $radios->delete($actor, $parameters['id'] ?? null, $requestId),
                'stream' => $media->stream($request, $actor, $parameters, $requestId),
                'download' => $media->download($request, $actor, $parameters, $requestId),
                'getCoverArt' => $media->coverArt($request, $actor, $parameters, $requestId),
                'getAvatar' => $media->avatar($request, $actor, $parameters['username'] ?? null, $requestId),
                'search3' => $catalog->search3($actor, $parameters),
                'search2' => $catalog->search2($actor, $parameters),
                default => null,
            };
            if ($body === null) {
                return SubsonicResponseFactory::error(
                    $request,
                    0,
                    'Requested endpoint is not supported.',
                    $requestId,
                );
            }
            if ($body instanceof Response) {
                return $body;
            }

            $this->observeCatalogResponse($method, $parameters, $body, $requestId);

            return SubsonicResponseFactory::success($request, $body, $requestId);
        } catch (SubsonicRequestInvalid) {
            return SubsonicResponseFactory::error(
                $request,
                10,
                'Required parameter is missing or invalid.',
                $requestId,
            );
        } catch (SubsonicAuthenticationFailed) {
            return SubsonicResponseFactory::error(
                $request,
                40,
                'Wrong username or password.',
                $requestId,
            );
        } catch (SubsonicAuthorizationDenied) {
            return SubsonicResponseFactory::error(
                $request,
                50,
                'User is not authorized for the requested operation.',
                $requestId,
            );
        } catch (UserRuntimeLimitExceeded) {
            // Subsonic 协议没有结构化 current/maximum 扩展，使用标准“无权限”码稳定拒绝新动作。
            return SubsonicResponseFactory::error(
                $request,
                50,
                'Account runtime limit does not allow the requested operation.',
                $requestId,
            );
        } catch (SubsonicEntityNotFound) {
            return SubsonicResponseFactory::error($request, 70, 'Requested data was not found.', $requestId);
        } catch (SubsonicFeatureUnsupported) {
            return SubsonicResponseFactory::error($request, 0, 'Requested feature is not supported.', $requestId);
        } catch (Throwable $throwable) {
            Log::error('Subsonic request failed.', [
                'request_id' => $requestId,
                'method' => $method,
                'exception_class' => $throwable::class,
            ]);

            return SubsonicResponseFactory::error($request, 0, 'Server error.', $requestId);
        }
    }

    /**
     * 为艺人详情及离线曲库同步的标准多请求链路记录不含身份与媒体标识的诊断事实。
     *
     * Subsonic `getArtist` 只返回专辑，歌曲由 `getTopSongs` 或后续 `getAlbum` 获取；客户端若没有发起后续
     * 请求，仅查看业务查询结果无法区分“服务端空列表”和“客户端未调用”。部分客户端还会先通过
     * `getAlbumList2` 或 `search3` 建立本地专辑索引，再逐张调用 `getAlbum`；这两种入口也纳入同一旁路
     * 观测。日志只记录方法名、参数是否存在以及各结果类型数量，绝不记录用户名、token、salt、查询词、
     * 实体 ID 或响应正文。该观测只在成功响应后执行，日志后端故障由框架隔离，不能改变协议响应、授权
     * 结果或数据库状态。
     *
     * @param array<string,mixed> $parameters 已解析请求参数，仅检查固定键是否存在。
     * @param array<string,mixed> $body 已完成授权并准备序列化的协议响应。
     */
    private function observeCatalogResponse(
        string $method,
        array $parameters,
        array $body,
        string $requestId,
    ): void {
        $infoContainer = $method === 'getArtistInfo2' ? 'artistInfo2' : 'artistInfo';
        $counts = match ($method) {
            'getArtist' => ['album_count' => $this->responseListCount($body, ['artist', 'album'])],
            'getAlbum' => ['song_count' => $this->responseListCount($body, ['album', 'song'])],
            'getTopSongs' => ['song_count' => $this->responseListCount($body, ['topSongs', 'song'])],
            'getAlbumList2' => ['album_count' => $this->responseListCount($body, ['albumList2', 'album'])],
            'search3' => [
                'artist_count' => $this->responseListCount($body, ['searchResult3', 'artist']),
                'album_count' => $this->responseListCount($body, ['searchResult3', 'album']),
                'song_count' => $this->responseListCount($body, ['searchResult3', 'song']),
            ],
            'getArtistInfo', 'getArtistInfo2' => [
                'similar_artist_count' => $this->responseListCount($body, [$infoContainer, 'similarArtist']),
            ],
            default => null,
        };
        if ($counts === null) {
            return;
        }

        try {
            Log::info('Subsonic catalog response completed.', [
                'request_id' => $requestId,
                'method' => $method,
                ...$counts,
                'has_id_parameter' => array_key_exists('id', $parameters),
                'has_artist_parameter' => array_key_exists('artist', $parameters),
            ]);
        } catch (Throwable) {
            // 可观测性是旁路能力；日志存储不可用时仍必须返回已经授权并生成完成的协议结果。
        }
    }

    /**
     * 在固定服务端响应路径上计算列表数量，供无敏感值诊断日志使用。
     *
     * 路径由调用方常量提供，绝不接受请求参数；缺失、对象或标量均视为零。方法只读取已授权且已经完成
     * 生成的内存响应，不改变序列化结构，也不会因诊断数据形状异常阻断客户端请求。
     *
     * @param list<string> $path 服务端固定响应键路径。
     */
    private function responseListCount(array $body, array $path): int
    {
        $value = $body;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return 0;
            }
            $value = $value[$key];
        }

        return is_array($value) && array_is_list($value) ? count($value) : 0;
    }

    /**
     * Merges parsed query/form maps without interpreting plaintext password compatibility input.
     *
     * PHP/Webman already bounds request bodies at the HTTP layer. Individual authentication and
     * endpoint fields still reject arrays and enforce explicit lengths before database/crypto work.
     *
     * @return array<string, mixed>
     */
    private function parameters(Request $request, string $method): array
    {
        $query = $request->get();
        $form = $request->post();
        $parameters = array_replace(is_array($query) ? $query : [], is_array($form) ? $form : []);
        foreach ($this->repeatedParameters($request->queryString(), $method) as $name => $values) {
            $parameters[$name] = $values;
        }
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            foreach ($this->repeatedParameters($request->rawBody(), $method) as $name => $values) {
                // OpenSubsonic formPost gives body values precedence over query values. Repeated
                // fields replace the complete query collection rather than concatenating sources.
                $parameters[$name] = $values;
            }
        }

        return $parameters;
    }

    /**
     * Preserves endpoint-specific repeated playlist and favorite parameters that parse_str overwrites.
     *
     * Only a fixed protocol allowlist is collected; favorite entity IDs are enabled exclusively for
     * star/unstar so scalar `id` endpoints retain their normal shape. Both plain repeated keys and
     * PHP-style `[]` keys are accepted. Each key records at most 1001 values, deliberately one above
     * domain limits so oversized commands are rejected without unbounded request-memory growth.
     *
     * @return array<string, list<string>>
     */
    private function repeatedParameters(string $encoded, string $method): array
    {
        if ($encoded === '') {
            return [];
        }
        $allowed = [
            'songId' => true,
            'songIdToAdd' => true,
            'songIndexToRemove' => true,
        ];
        if ($method === 'star' || $method === 'unstar') {
            $allowed += ['id' => true, 'albumId' => true, 'artistId' => true];
        }
        if ($method === 'savePlayQueue' || $method === 'savePlayQueueByIndex') {
            $allowed += ['id' => true];
        }
        $result = [];
        foreach (explode('&', $encoded) as $part) {
            [$rawName, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($rawName);
            if (str_ends_with($name, '[]')) {
                $name = substr($name, 0, -2);
            }
            if (!isset($allowed[$name]) || count($result[$name] ?? []) >= 1001) {
                continue;
            }
            $result[$name][] = urldecode($rawValue);
        }

        return $result;
    }
}
