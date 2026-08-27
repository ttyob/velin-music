<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Download\DownloadManifestInvalid;
use app\application\Download\DownloadManifestService;
use app\application\Media\MediaDetailNotFound;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamService;
use app\application\Media\MediaStreamUnavailable;
use app\application\Playlist\PlaylistNotFound;
use app\application\Preference\PlaybackPreferenceService;
use app\application\Subsonic\SubsonicRequestInvalid;
use app\application\Subsonic\SubsonicTranscodeService;
use app\application\System\SystemLimitSettingsService;
use app\application\Transcode\TranscodeCapacityExceeded;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\ByteRangeParser;
use app\http\JsonResponseFactory;
use app\http\MediaSourceResponseFactory;
use app\http\RequestContext;
use app\http\UnsatisfiableByteRange;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供 App 离线清单、原始媒体 Range 下载和受限的标准音质派生下载。
 *
 * 清单与字节读取均在每次请求重验角色能力、全局下载开关、音乐库和文件身份；分享旧清单、ETag
 * 或断点位置不能绕过撤权。全局开关只拒绝新请求，不关闭已经开始发送的响应。下载不记录播放完成、
 * 原版不套用转码偏好；标准音质只允许 App access token 在 MP3/AAC/Opus 白名单内选择格式，码率仍取
 * 用户标准音质偏好与管理员上限。派生输出先完整写入受控缓存再发送，不能把部分转码伪装为可续传文件。
 * HEAD 与 GET 使用同一授权边界，便于客户端恢复前校验长度与版本。
 */
final class DownloadController
{
    public function __construct(
        private readonly SystemLimitSettingsService $limits = new SystemLimitSettingsService(),
    ) {
    }

    /**
     * 返回当前账号可下载对象的冻结清单。
     *
     * 角色 capability 与全局开关必须同时允许；拒绝发生在解析歌曲、专辑或播放列表之前，避免调用者
     * 利用错误差异枚举媒体。该读取不创建文件或后台任务，失败没有需要补偿的副作用。
     */
    public function manifest(Request $request, string $type, string $id): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'download');
            $this->limits->assertFeatureAllowed('download');
            $manifest = (new DownloadManifestService())->create($actor, $type, $id);
            if ((string) $request->header('if-none-match', '') === $manifest['etag']) {
                return response('', 304, ['ETag' => $manifest['etag'], 'Cache-Control' => 'private, no-cache',
                    'X-Request-ID' => $requestId]);
            }
            return JsonResponseFactory::create(['data' => ['manifest' => $manifest], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId)->withHeaders([
                'ETag' => $manifest['etag'], 'Cache-Control' => 'private, no-cache, must-revalidate',
            ]);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Download manifest request failed.');
        }
    }

    /**
     * 发送一个已授权歌曲的原始字节、单段 Range 或完整标准音质派生文件。
     *
     * HEAD、完整 GET 与断点 GET 共用全局下载开关；检查先于媒体解析和响应头生成。响应一旦开始发送便
     * 不因管理员随后改限额而被异步截断，下次续传会重新校验并按稳定策略码拒绝。
     */
    public function file(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        $resolvedSize = 0;
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'download');
            $this->limits->assertFeatureAllowed('download');
            $media = (new MediaStreamService())->resolve($actor, $songId);
            $resolvedSize = $media->fileSize;
            $variant = $this->variant($request, $actor);
            if ($variant['quality'] === 'standard') {
                $preference = (new PlaybackPreferenceService())->snapshot($actor);
                $bitrate = min(
                    (int) $preference['maxBitrateKbps'],
                    (int) $preference['administratorMaxBitrateKbps'],
                );
                $response = (new SubsonicTranscodeService())->negotiate(
                    $media,
                    [
                        'format' => $variant['format'],
                        'maxBitRate' => $bitrate,
                        'converted' => true,
                        'estimateContentLength' => true,
                    ],
                    $requestId,
                    $actor,
                    true,
                    null,
                    true,
                );
                if ($response === null) {
                    throw new DownloadManifestInvalid('普通音质下载格式无效。');
                }
                return $response;
            }
            $headers = [
                'Content-Type' => $media->mimeType,
                'Content-Disposition' => "attachment; filename=\"audio\"; filename*=UTF-8''"
                    . rawurlencode($media->downloadName),
                'Cache-Control' => 'private, no-cache, must-revalidate',
                'ETag' => $media->etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $media->modifiedAt) . ' GMT',
                'Accept-Ranges' => 'bytes', 'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId,
            ];
            if ((string) $request->header('if-none-match', '') === $media->etag) {
                return response('', 304, $headers);
            }
            $rangeHeader = (string) $request->header('range', '');
            $ifRange = trim((string) $request->header('if-range', ''));
            if ($rangeHeader !== '' && $ifRange !== '') {
                $ifRangeTime = strtotime($ifRange);
                $matches = $ifRange === $media->etag
                    || ($ifRangeTime !== false && $media->modifiedAt <= $ifRangeTime);
                // 续传版本不一致时回完整新文件；客户端必须以新 ETag 重建临时文件，不能拼接旧字节。
                if (!$matches) $rangeHeader = '';
            }
            $range = $rangeHeader === '' ? null : (new ByteRangeParser())->parse($rangeHeader, $media->fileSize);
            $offset = $range?->offset ?? 0;
            $length = $range?->length ?? $media->fileSize;
            if (strtoupper($request->method()) === 'HEAD') {
                if ($range !== null) $headers['Content-Range'] = 'bytes ' . $offset . '-'
                    . ($offset + $length - 1) . '/' . $media->fileSize;
                $headers['Content-Length'] = (string) $length;
                return response('', $range === null ? 200 : 206, $headers);
            }
            return (new MediaSourceResponseFactory())->create($media, $range === null ? 200 : 206,
                $headers, $offset, $length);
        } catch (Throwable $throwable) {
            if ($throwable instanceof UnsatisfiableByteRange) {
                return JsonResponseFactory::error('RANGE_NOT_SATISFIABLE', '请求的音频范围无效。',
                    416, $requestId)->withHeader('Content-Range', 'bytes */' . $resolvedSize);
            }
            return $this->failure($throwable, $requestId, 'Offline media download failed.');
        }
    }

    /**
     * 解析单次下载质量与格式。
     *
     * 缺省和 original 保持历史原始 Range 行为，且禁止附带格式。standard 只允许短期 App access token，
     * 并继承入口已经验证的 download 能力；格式严格限制为 MP3/AAC/Opus。校验在打开转码输入和取得并发槽前
     * 完成，未知值不进入日志或 FFmpeg 参数。返回值只含内部白名单枚举，不含原始查询文本。
     *
     * @param array<string,mixed> $actor 已通过 download capability 校验的实时账号投影。
     * @return array{quality:'original'|'standard',format:?string}
     */
    private function variant(Request $request, array $actor): array
    {
        $quality = $request->get('quality');
        $format = $request->get('format');
        if ($quality === null || $quality === '') $quality = 'original';
        if (!is_string($quality) || !in_array($quality, ['original', 'standard'], true)) {
            throw new DownloadManifestInvalid('下载音质参数无效。');
        }
        if ($quality === 'original') {
            if ($format !== null && $format !== '') {
                throw new DownloadManifestInvalid('原版下载不能指定转换格式。');
            }
            return ['quality' => 'original', 'format' => null];
        }
        if (($actor['authenticationType'] ?? null) !== 'app_access_token') {
            throw new AuthorizationDenied('An App access token is required for standard downloads.');
        }
        if (!is_string($format) || !in_array($format, ['mp3', 'aac', 'opus'], true)) {
            throw new DownloadManifestInvalid('下载格式参数无效。');
        }
        return ['quality' => 'standard', 'format' => $format];
    }

    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('DOWNLOAD_PERMISSION_DENIED', '没有下载音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded
            && $throwable->reasonCode === 'DOWNLOAD_DISABLED_BY_SYSTEM_POLICY') {
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                '系统当前已禁止下载。',
                403,
                $requestId,
            );
        }
        if ($throwable instanceof MediaStreamNotFound || $throwable instanceof MediaDetailNotFound
            || $throwable instanceof PlaylistNotFound) {
            return JsonResponseFactory::error('DOWNLOAD_SOURCE_NOT_FOUND', '下载内容不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof DownloadManifestInvalid) {
            return JsonResponseFactory::error('DOWNLOAD_MANIFEST_INVALID', $throwable->getMessage(), 422, $requestId);
        }
        if ($throwable instanceof MediaStreamUnavailable) {
            return JsonResponseFactory::error('MEDIA_FILE_UNAVAILABLE', '音频文件暂时不可用。', 503, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                '当前账号的转码并发或码率已达到管理员限制。',
                429,
                $requestId,
                ['current' => $throwable->current, 'maximum' => $throwable->maximum],
            )->withHeader('Retry-After', '5');
        }
        if ($throwable instanceof TranscodeCapacityExceeded) {
            return JsonResponseFactory::error('TRANSCODE_CAPACITY_EXCEEDED', '转码服务繁忙，请稍后重试。',
                503, $requestId)->withHeader('Retry-After', '5');
        }
        if ($throwable instanceof SubsonicRequestInvalid) {
            return JsonResponseFactory::error('DOWNLOAD_TRANSCODE_UNAVAILABLE', '普通音质下载暂时不可用。',
                503, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error('DOWNLOAD_UNAVAILABLE', '下载服务暂时不可用。', 503, $requestId);
    }
}
