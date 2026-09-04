<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Artwork\ArtworkNotFound;
use app\application\Artwork\ArtworkService;
use app\application\Artwork\ArtworkUnavailable;
use app\application\Artwork\ResolvedArtwork;
use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\http\JsonResponseFactory;
use app\http\LocalFileResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供已授权的歌曲、专辑和艺术家原始图片，并使用私有浏览器缓存校验器。
 *
 * 图片响应不使用 JSON envelope。Controller 完成实时权限和图片身份解析后，内置 Go 网关直接发送本地
 * 文件；源码/裸机关闭网关时回退 Workerman。两条路径都不缓冲图片、不在公开响应暴露文件名或物理路径。
 */
final class ArtworkController
{
    /** 返回歌曲独立封面；未选择歌曲候选时由领域服务回退所属专辑。 */
    public function song(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $artwork = (new ArtworkService())->resolveSong($actor, $songId);
            $headers = $this->headers($artwork, $requestId);
            if ($this->isNotModified($request, $artwork)) return response('', 304, $headers);
            return (new LocalFileResponseFactory())->create(
                $artwork->path,
                200,
                $headers,
                0,
                $artwork->fileSize,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof ArtworkNotFound) {
                return JsonResponseFactory::error('ARTWORK_NOT_FOUND', '歌曲封面不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof ArtworkUnavailable) {
                Log::warning('Authorized song artwork failed runtime validation.', [
                    'request_id' => $requestId, 'song_id' => $songId, 'reason_code' => $throwable->reasonCode,
                ]);
                return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '歌曲封面暂时不可用。', 503, $requestId);
            }
            Log::error('Song artwork request failed.', [
                'request_id' => $requestId, 'song_id' => $songId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '封面服务暂时不可用。', 503, $requestId);
        }
    }

    /** 返回当前账号可见的专辑原图；不存在与无权访问统一返回不可枚举的 404。 */
    public function album(Request $request, string $albumId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $artwork = (new ArtworkService())->resolveAlbum($actor, $albumId);
            $headers = $this->headers($artwork, $requestId);
            if ($this->isNotModified($request, $artwork)) {
                return response('', 304, $headers);
            }

            return (new LocalFileResponseFactory())->create(
                $artwork->path,
                200,
                $headers,
                0,
                $artwork->fileSize,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof ArtworkNotFound) {
                return JsonResponseFactory::error('ARTWORK_NOT_FOUND', '封面不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof ArtworkUnavailable) {
                Log::warning('Authorized artwork failed runtime file validation.', [
                    'request_id' => $requestId,
                    'album_id' => $albumId,
                    'reason_code' => $throwable->reasonCode,
                ]);

                return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '封面暂时不可用，请重新扫描音乐库。', 503, $requestId);
            }
            Log::error('Artwork request failed.', [
                'request_id' => $requestId,
                'album_id' => $albumId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '封面服务暂时不可用。', 503, $requestId);
        }
    }

    /** 返回当前音乐库授权范围内的艺人原图；不存在与无权访问统一返回不可枚举的 404。 */
    public function artist(Request $request, string $artistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $artwork = (new ArtworkService())->resolveArtist($actor, $artistId);
            $headers = $this->headers($artwork, $requestId);
            if ($this->isNotModified($request, $artwork)) {
                return response('', 304, $headers);
            }

            return (new LocalFileResponseFactory())->create(
                $artwork->path,
                200,
                $headers,
                0,
                $artwork->fileSize,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有浏览音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof ArtworkNotFound) {
                return JsonResponseFactory::error('ARTWORK_NOT_FOUND', '艺术家图片不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof ArtworkUnavailable) {
                Log::warning('Authorized artist artwork failed runtime file validation.', [
                    'request_id' => $requestId,
                    'artist_id' => $artistId,
                    'reason_code' => $throwable->reasonCode,
                ]);
                return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '艺术家图片暂时不可用，请重新扫描音乐库。', 503, $requestId);
            }
            Log::error('Artist artwork request failed.', [
                'request_id' => $requestId,
                'artist_id' => $artistId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('ARTWORK_UNAVAILABLE', '图片服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 构造原图响应的私有缓存、内容类型和嗅探保护头。
     *
     * 此处不能预设 Content-Length：Go 网关会在解密并复验文件身份后写入，兼容回退的 `withFile()` 也会
     * 在 Workerman 编码阶段依据最终文件追加。业务层提前写入会产生重复长度或与并发变化后的文件不符。
     * 文件身份和大小仍由 ArtworkService 先验证，传输边界随后独立复验。
     */
    private function headers(ResolvedArtwork $artwork, string $requestId): array
    {
        return [
            'Content-Type' => $artwork->mimeType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600, must-revalidate',
            'ETag' => $artwork->etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $artwork->modifiedAt) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
    }

    /** 按 HTTP 优先级先校验 If-None-Match，再按秒精度校验 If-Modified-Since。 */
    private function isNotModified(Request $request, ResolvedArtwork $artwork): bool
    {
        $ifNoneMatch = trim((string) $request->header('if-none-match', ''));
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || ltrim($candidate, 'W/') === $artwork->etag) {
                    return true;
                }
            }

            return false;
        }
        $ifModifiedSince = strtotime((string) $request->header('if-modified-since', ''));

        return $ifModifiedSince !== false && $artwork->modifiedAt <= $ifModifiedSince;
    }
}
