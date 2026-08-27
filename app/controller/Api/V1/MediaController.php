<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaDetailNotFound;
use app\application\Media\MediaDiscoveryInvalid;
use app\application\Media\MediaDiscoveryValidator;
use app\application\Media\MediaQueryService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes read-only, path-free catalog lists for the Web frontend and future clients.
 *
 * Every operation requires a valid Session and global `play` capability, then delegates live
 * library-scope enforcement to MediaQueryService. Query limits are bounded and malformed library
 * IDs resolve to an empty result rather than revealing whether another user's library exists.
 */
final class MediaController
{
    /** Returns an authorized song page including safe technical audio fields. */
    public function songs(Request $request): Response
    {
        return $this->respond($request, 'songs');
    }

    /**
     * 返回当前账号经常播放的歌曲分页，严格按累计播放次数倒序。
     *
     * 端点要求有效 Session 或 Bearer 身份及实时 `play` 能力；统计只参与筛选和排序，查询服务会再次
     * 应用当前音乐库授权、文件可用性和元数据状态。`page`/`pageSize` 非规范或越界时返回 422，不会
     * 静默扩大查询。读取不会增加播放次数、恢复历史、修改队列或写入媒体文件。
     */
    public function frequentlyPlayedSongs(Request $request): Response
    {
        return $this->respondDiscovery($request, 'frequently-played');
    }

    /**
     * 返回当前账号可见曲库中的稳定随机歌曲分页。
     *
     * 端点要求有效 Session 或 Bearer 身份及实时 `play` 能力；同一账号、UTC 日期和授权目录快照下
     * 分页顺序稳定且跨页不重复，目录或授权变化后立即按新范围计算。请求不接受客户端指定用户或物理
     * 路径，也不会记录播放、持久化随机状态或触发扫描；非法分页统一返回 422。
     */
    public function randomRecommendationSongs(Request $request): Response
    {
        return $this->respondDiscovery($request, 'random-recommendations');
    }

    /** Returns an authorized album page with denormalized track and duration totals. */
    public function albums(Request $request): Response
    {
        return $this->respond($request, 'albums');
    }

    /** Returns artists reachable through authorized, currently available songs. */
    public function artists(Request $request): Response
    {
        return $this->respond($request, 'artists');
    }

    /** Returns authorized multi-value genre aggregates with distinct media counts. */
    public function genres(Request $request): Response
    {
        return $this->respond($request, 'genres');
    }

    /** Returns authorized release-year aggregates, including a distinct unknown group. */
    public function years(Request $request): Response
    {
        return $this->respond($request, 'years');
    }

    /** Returns an authorized album header, genres, availability count, and ordered songs. */
    public function album(Request $request, string $albumId): Response
    {
        return $this->respondDetail($request, 'album', $albumId);
    }

    /** Returns one authorized song summary with safe extended tags and genres. */
    public function song(Request $request, string $songId): Response
    {
        return $this->respondDetail($request, 'song', $songId);
    }

    /** Returns an authorized artist aggregate with bounded local songs and albums. */
    public function artist(Request $request, string $artistId): Response
    {
        return $this->respondDetail($request, 'artist', $artistId);
    }

    /** Maps common pagination input and emits the shared JSON envelope. */
    private function respond(Request $request, string $resource): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $libraryId = is_string($request->get('libraryId')) ? $request->get('libraryId') : null;
            $limit = is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50;
            $offset = is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0;
            $releaseYear = $this->releaseYear($request);
            $service = new MediaQueryService();
            $data = match ($resource) {
                'songs' => $service->songs(
                    $actor,
                    $libraryId,
                    $limit,
                    $offset,
                    is_string($request->get('genreId')) ? $request->get('genreId') : null,
                    false,
                    $releaseYear,
                ),
                'albums' => $service->albums($actor, $libraryId, $limit, $offset, false, $releaseYear),
                'artists' => $service->artists($actor, $libraryId, $limit, $offset),
                'genres' => $service->genres($actor, $libraryId, $limit, $offset),
                'years' => $service->years($actor, $libraryId, $limit, $offset),
            };

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            Log::error('Media catalog request failed.', [
                'request_id' => $requestId,
                'resource' => $resource,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('MEDIA_CATALOG_UNAVAILABLE', '音乐目录暂时不可用。', 503, $requestId);
        }
    }

    /** Maps the public year token without accepting floats, signs, or arbitrary SQL text. */
    private function releaseYear(Request $request): int|string|null
    {
        $value = $request->get('releaseYear');
        if ($value === null || $value === '') {
            return null;
        }
        if ($value === 'unknown') {
            return 'unknown';
        }

        return is_string($value) && preg_match('/^[0-9]{4}$/', $value) === 1 ? (int) $value : 'invalid';
    }

    /**
     * 执行只读歌曲发现请求并保持统一 JSON envelope 与失败映射。
     *
     * 鉴权先于分页查询，避免未认证请求借参数错误探测接口内部；日志只记录服务端资源类别和异常类型，
     * 不记录身份、页码或媒体数据。查询服务负责授权后 total 与稳定排序，控制器不缓存结果，确保撤权
     * 能在下一次请求生效。
     */
    private function respondDiscovery(Request $request, string $resource): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $pagination = (new MediaDiscoveryValidator())->pagination(
                $request->get('page'),
                $request->get('pageSize'),
            );
            $service = new MediaQueryService();
            $data = match ($resource) {
                'frequently-played' => $service->frequentlyPlayedSongPage(
                    $actor,
                    $pagination['page'],
                    $pagination['pageSize'],
                ),
                'random-recommendations' => $service->randomRecommendationSongs(
                    $actor,
                    $pagination['page'],
                    $pagination['pageSize'],
                ),
            };

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaDiscoveryInvalid) {
                return JsonResponseFactory::error('VALIDATION_FAILED', '页码或每页条数无效。', 422, $requestId);
            }
            Log::error('Media discovery request failed.', [
                'request_id' => $requestId,
                'resource' => $resource,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('MEDIA_DISCOVERY_UNAVAILABLE', '歌曲发现服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * Maps one opaque detail ID after authentication and preserves the non-enumerable 404 boundary.
     *
     * Invalid, missing, unavailable, and unauthorized IDs intentionally return the same code and
     * message. Logs never contain the requested ID because it may refer to another user's catalog.
     */
    private function respondDetail(Request $request, string $resource, string $mediaId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $service = new MediaQueryService();
            $data = match ($resource) {
                'album' => $service->albumDetail($actor, $mediaId),
                'artist' => $service->artistDetail($actor, $mediaId),
                'song' => $service->songDetail($actor, $mediaId),
            };

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaDetailNotFound) {
                return JsonResponseFactory::error('MEDIA_NOT_FOUND', '媒体不存在或无权访问。', 404, $requestId);
            }
            Log::error('Media detail request failed.', [
                'request_id' => $requestId,
                'resource' => $resource,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('MEDIA_CATALOG_UNAVAILABLE', '音乐目录暂时不可用。', 503, $requestId);
        }
    }
}
