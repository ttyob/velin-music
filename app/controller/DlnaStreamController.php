<?php

declare(strict_types=1);

namespace app\controller;

use app\application\Dlna\DlnaTicketNotFound;
use app\application\Dlna\DlnaTicketService;
use app\application\Media\MediaStreamService;
use app\application\Subsonic\SubsonicTranscodeService;
use app\http\ByteRangeParser;
use app\http\JsonResponseFactory;
use app\http\MediaSourceResponseFactory;
use app\http\RequestContext;
use app\http\UnsatisfiableByteRange;
use app\http\TranscodeResponse;
use app\infrastructure\Dlna\DlnaDeliveryObserver;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 向无法携带 Cookie/Header 的 DLNA Renderer 提供票据范围内音频。
 *
 * URL 票据只固定调用身份、歌曲和输出格式；每次 GET 仍重验账号能力、音乐库授权及真实文件身份。原始
 * 直放支持单 Range 并返回私有缓存校验元数据；转码只使用 MP3/AAC/Opus 固定配置并先生成精确 Content-Length，
 * 兼容不支持 chunked 的音响。请求字节不会直接增加播放次数，票据、路径和设备身份不进入日志。
 */
final class DlnaStreamController
{
    /** 返回票据授权的原始或受监督转码响应。 */
    public function show(Request $request, string $token): Response
    {
        $requestId = RequestContext::requestId();
        $resolvedSize = 0;
        try {
            $ticket = (new DlnaTicketService())->resolve($token);
            $media = (new MediaStreamService())->resolve($ticket['actor'], $ticket['songId']);
            $resolvedSize = $media->fileSize;
            if ($ticket['format'] !== 'raw') {
                $response = (new SubsonicTranscodeService())->negotiate($media, [
                    'format' => $ticket['format'],
                    'converted' => 'true',
                    'estimateContentLength' => 'true',
                ], $requestId, $ticket['actor'], persistentCache: true, lowLatency: true);
                if ($response === null) throw new DlnaTicketNotFound('DLNA 转码票据无效。');
                return new TranscodeResponse($response->plan, $ticket['ticketId']);
            }

            $headers = [
                'Content-Type' => $media->mimeType,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-cache, must-revalidate',
                'Accept-Ranges' => 'bytes',
                'ETag' => $media->etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $media->modifiedAt) . ' GMT',
                'transferMode.dlna.org' => 'Streaming',
                'contentFeatures.dlna.org' => 'DLNA.ORG_OP=01;DLNA.ORG_CI=0;DLNA.ORG_FLAGS=01700000000000000000000000000000',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
            ];
            $rangeHeader = (string) $request->header('range', '');
            $range = $rangeHeader === '' ? null : (new ByteRangeParser())->parse($rangeHeader, $media->fileSize);
            $offset = $range?->offset ?? 0;
            $length = $range?->length ?? $media->fileSize;
            $response = (new MediaSourceResponseFactory())->create(
                $media,
                $range === null ? 200 : 206,
                $headers,
                $offset,
                $length,
                $ticket['ticketId'],
            );
            // WebDAV marker 由远端 runner 安装投递观察；本地 withFile 仍在这里使用最终框架头长度。
            if ($media->source->localPath() !== null && $request->connection !== null) {
                DlnaDeliveryObserver::start(
                    $request->connection,
                    $ticket['ticketId'],
                    $offset,
                    $length,
                    $media->fileSize,
                    strlen((string) $response),
                );
            }
            return $response;
        } catch (Throwable $throwable) {
            if ($throwable instanceof UnsatisfiableByteRange) {
                return JsonResponseFactory::error('RANGE_NOT_SATISFIABLE', '请求的音频范围无效。', 416, $requestId)
                    ->withHeader('Content-Range', 'bytes */' . $resolvedSize);
            }
            if (!$throwable instanceof DlnaTicketNotFound) {
                Log::warning('DLNA stream request failed.', ['request_id' => $requestId,
                    'exception_class' => $throwable::class]);
            }
            return JsonResponseFactory::error('DLNA_STREAM_NOT_FOUND', '投放地址不存在或已经失效。', 404, $requestId);
        }
    }
}
