<?php

declare(strict_types=1);

namespace app\controller;

use app\application\ExternalPlayback\ExternalPlaybackTicketNotFound;
use app\application\ExternalPlayback\ExternalPlaybackTicketService;
use app\application\Media\MediaStreamService;
use app\application\Subsonic\SubsonicTranscodeService;
use app\http\ByteRangeParser;
use app\http\JsonResponseFactory;
use app\http\MediaSourceResponseFactory;
use app\http\RequestContext;
use app\http\TranscodeResponse;
use app\http\UnsatisfiableByteRange;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 向附近网络接收器提供票据限定的 GET/HEAD 音频响应。
 *
 * URL 是唯一凭据，不读取 Cookie、Authorization、查询格式或自定义 Header。每次访问先解析票据，再重验
 * 账号权限、音乐库和文件身份。原始音频支持单 Range；转码使用固定 MP3/AAC/Opus 计划并先生成精确
 * Content-Length。未知、过期、撤销、失权和文件变化统一返回 404，日志不包含 token 或完整 URL。
 */
final class ExternalPlaybackStreamController
{
    public function show(Request $request, string $ticket): Response
    {
        $requestId = RequestContext::requestId();
        $resolvedSize = 0;
        try {
            $resolved = (new ExternalPlaybackTicketService())->resolve($ticket);
            $media = (new MediaStreamService())->resolve($resolved['actor'], $resolved['songId']);
            $resolvedSize = $media->fileSize;
            if ($resolved['format'] !== 'raw') {
                // 转码长度只有缓存/FFmpeg 完成后才能精确获知；HEAD 只证明票据、权限、媒体与协商 MIME，
                // 不启动耗时转码，也绝不能因响应无 body 而在后台继续消耗转码租约。
                if (strtoupper($request->method()) === 'HEAD') {
                    return response('', 200, [
                        'Content-Type' => $resolved['mimeType'], 'Cache-Control' => 'private, no-store',
                        'Accept-Ranges' => 'none', 'X-Content-Type-Options' => 'nosniff',
                        'X-Request-ID' => $requestId,
                    ]);
                }
                $transcode = (new SubsonicTranscodeService())->negotiate($media, [
                    'format' => $resolved['format'], 'converted' => 'true', 'estimateContentLength' => 'true',
                ], $requestId, $resolved['actor'], persistentCache: true, lowLatency: true);
                if ($transcode === null) throw new ExternalPlaybackTicketNotFound('External ticket not found.');
                return new TranscodeResponse($transcode->plan);
            }
            $headers = [
                'Content-Type' => $media->mimeType,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store',
                'Accept-Ranges' => 'bytes',
                'ETag' => $media->etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $media->modifiedAt) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
            ];
            $rangeHeader = (string) $request->header('range', '');
            $range = $rangeHeader !== ''
                ? (new ByteRangeParser())->parse($rangeHeader, $media->fileSize) : null;
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
            if (!$throwable instanceof ExternalPlaybackTicketNotFound) {
                Log::warning('External playback stream failed.', [
                    'request_id' => $requestId, 'exception_class' => $throwable::class,
                ]);
            }
            return JsonResponseFactory::error('EXTERNAL_STREAM_NOT_FOUND',
                '播放地址不存在或已经失效。', 404, $requestId);
        }
    }
}
