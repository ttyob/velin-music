<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamService;
use app\application\Media\MediaStreamUnavailable;
use app\application\Media\PlayableMedia;
use app\application\Playback\WebPlaybackNegotiator;
use app\application\Playback\PlaybackQualityRequestInvalid;
use app\application\Playback\PlayerProfileInvalid;
use app\application\Playback\WebPlaybackRequestInvalid;
use app\application\Subsonic\SubsonicRequestInvalid;
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
 * 通过 Workerman 背压发送已授权的原始音频或受监督转码响应。
 *
 * Controller 不读取完整文件：直放的单段 Range 交给文件/远端来源 Runner，实时转码交给 FFmpeg
 * supervisor。Web 对未缓冲转码执行 seek 时只能提交受限整数秒；App Bearer 可在单次请求选择原始或
 * 标准音质，格式、码率、输入位置和命令仍由服务端生成。任何请求都会重新验证账号、音乐库授权和媒体
 * 身份；读取字节本身不计完整播放，也不暴露物理路径。
 */
final class StreamController
{
    /**
     * 返回一个不透明歌曲 ID 的完整直放、单段 Range 或实时转码响应。
     *
     * 前置条件是 Cookie、PAT 或 App Bearer 具备 play 且歌曲当前可见。quality 只允许 App Bearer 提交；
     * timeOffset 仅重新定位本来就需要转码的响应，直放保持原始字节和 Range 语义。失败在响应提交前映射
     * 为稳定 JSON；流已提交后的断连由 Runner 回收进程与租约。
     */
    public function show(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        $resolvedFileSize = 0;
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $requestedQuality = $this->requestedQuality($request, $actor);
            $media = (new MediaStreamService())->resolve($actor, $songId);
            $resolvedFileSize = $media->fileSize;
            $playerId = $request->get('playerId');
            if ($playerId !== null && !is_string($playerId)) throw new PlayerProfileInvalid('播放器标识无效。');
            $timeOffsetSeconds = $this->timeOffsetSeconds($request);
            $transcoded = (new WebPlaybackNegotiator())->negotiate(
                $actor,
                $media,
                $requestId,
                is_string($playerId) && $playerId !== '' ? $playerId : null,
                $timeOffsetSeconds,
                $requestedQuality,
            );
            if ($transcoded !== null) return $transcoded;
            $headers = $this->mediaHeaders($media, $requestId);

            if ($this->isNotModified($request, $media)) {
                return response('', 304, $headers);
            }

            $rangeHeader = (string) $request->header('range', '');
            $useRange = $rangeHeader !== '' && $this->ifRangeMatches($request, $media);
            $range = $useRange
                ? (new ByteRangeParser())->parse($rangeHeader, $media->fileSize)
                : null;

            $offset = $range?->offset ?? 0;
            $length = $range?->length ?? $media->fileSize;
            return (new MediaSourceResponseFactory())->create(
                $media,
                $range === null ? 200 : 206,
                $headers,
                $offset,
                $length,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaStreamNotFound) {
                return JsonResponseFactory::error('MEDIA_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof UnsatisfiableByteRange) {
                return JsonResponseFactory::error(
                    'RANGE_NOT_SATISFIABLE',
                    '请求的音频范围无效。',
                    416,
                    $requestId,
                )->withHeader('Content-Range', 'bytes */' . $resolvedFileSize);
            }
            if ($throwable instanceof MediaStreamUnavailable) {
                Log::warning('Authorized media failed runtime file validation.', [
                    'request_id' => $requestId,
                    'song_id' => $songId,
                    'reason_code' => $throwable->reasonCode,
                ]);

                return JsonResponseFactory::error(
                    'MEDIA_FILE_UNAVAILABLE',
                    '音频文件暂时不可用，请重新扫描音乐库或检查存储挂载。',
                    503,
                    $requestId,
                );
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
                return JsonResponseFactory::error(
                    'TRANSCODE_CAPACITY_EXCEEDED',
                    '转码服务繁忙，请稍后重试。',
                    503,
                    $requestId,
                )->withHeader('Retry-After', '5');
            }
            if ($throwable instanceof SubsonicRequestInvalid) {
                Log::warning('Playback transcode negotiation failed.', [
                    'request_id' => $requestId,
                    'song_id' => $songId,
                    'exception_class' => $throwable::class,
                ]);
                return JsonResponseFactory::error(
                    'TRANSCODE_CONFIGURATION_UNAVAILABLE',
                    '当前转码配置不可用，请切换为原始直放或联系管理员。',
                    503,
                    $requestId,
                );
            }
            if ($throwable instanceof PlayerProfileInvalid) {
                return JsonResponseFactory::error('PLAYER_PROFILE_INVALID', '播放器标识无效。', 422, $requestId);
            }
            if ($throwable instanceof PlaybackQualityRequestInvalid) {
                return JsonResponseFactory::error('STREAM_QUALITY_INVALID', '播放音质参数无效。', 422, $requestId);
            }
            if ($throwable instanceof WebPlaybackRequestInvalid) {
                return JsonResponseFactory::error('STREAM_OFFSET_INVALID', '播放跳转位置无效。', 422, $requestId);
            }

            Log::error('Direct stream request failed.', [
                'request_id' => $requestId,
                'song_id' => $songId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('STREAM_UNAVAILABLE', '播放服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 解析 App 单次播放音质覆盖。
     *
     * 参数只接受 `original|standard`，且当前身份必须来自短期 App access token；Web Cookie 和 PAT 即使拥有
     * play 也不能伪装 App 覆盖账号偏好。省略参数保持历史行为。失败发生在解析歌曲和取得转码槽
     * 前，异常不包含原始查询内容，避免恶意值进入日志。
     *
     * @param array<string,mixed> $actor 已通过 play 能力校验的实时账号投影。
     * @throws PlaybackQualityRequestInvalid 参数、认证类型或枚举不符合协议。
     */
    private function requestedQuality(Request $request, array $actor): ?string
    {
        $raw = $request->get('quality');
        if ($raw === null || $raw === '') return null;
        if (($actor['authenticationType'] ?? null) !== 'app_access_token'
            || !is_string($raw) || !in_array($raw, ['original', 'standard'], true)) {
            throw new PlaybackQualityRequestInvalid('播放音质参数无效。');
        }

        return $raw;
    }

    /**
     * 解析 Web 实时转码重新定位秒数。
     *
     * 参数只接受 0 到 86400 的规范十进制整数；歌曲时长边界由协商器在打开媒体输入前复验。
     * 缺省值保持既有流行为。解析失败只产生 422，不得把任意浮点、科学计数法或负值传给 FFmpeg。
     *
     * @throws WebPlaybackRequestInvalid 参数不是受支持的整数秒数。
     */
    private function timeOffsetSeconds(Request $request): ?int
    {
        $raw = $request->get('timeOffset');
        if ($raw === null || $raw === '') return null;
        if (!is_string($raw) || preg_match('/^(?:0|[1-9]\d{0,4})$/', $raw) !== 1) {
            throw new WebPlaybackRequestInvalid('播放跳转位置无效。');
        }
        $seconds = (int) $raw;
        if ($seconds > 86_400) throw new WebPlaybackRequestInvalid('播放跳转位置无效。');

        return $seconds;
    }

    /** Builds cache validators and a UTF-8 filename without disclosing the registered path. */
    private function mediaHeaders(PlayableMedia $media, string $requestId): array
    {
        return [
            'Content-Type' => $media->mimeType,
            'Content-Disposition' => "inline; filename=\"audio\"; filename*=UTF-8''" . rawurlencode($media->downloadName),
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'ETag' => $media->etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $media->modifiedAt) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ];
    }

    /** Applies If-None-Match precedence, then the second-granularity If-Modified-Since validator. */
    private function isNotModified(Request $request, PlayableMedia $media): bool
    {
        $ifNoneMatch = trim((string) $request->header('if-none-match', ''));
        if ($ifNoneMatch !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || ltrim($candidate, 'W/') === $media->etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = strtotime((string) $request->header('if-modified-since', ''));

        return $ifModifiedSince !== false && $media->modifiedAt <= $ifModifiedSince;
    }

    /** Uses Range only when an optional strong ETag/date If-Range still identifies this file. */
    private function ifRangeMatches(Request $request, PlayableMedia $media): bool
    {
        $ifRange = trim((string) $request->header('if-range', ''));
        if ($ifRange === '') {
            return true;
        }
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/')) {
            return $ifRange === $media->etag;
        }
        $timestamp = strtotime($ifRange);

        return $timestamp !== false && $media->modifiedAt <= $timestamp;
    }
}
