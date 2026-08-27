<?php

declare(strict_types=1);

namespace app\process;

use app\http\TranscodeResponse;
use app\http\RealtimeEventResponse;
use app\http\MediaSourceStreamResponse;
use app\infrastructure\Media\FfmpegTranscodeRunner;
use app\infrastructure\Media\MediaSourceStreamRunner;
use app\infrastructure\Realtime\RealtimeEventRunner;
use support\Log;
use support\Response;
use Throwable;
use Webman\App;
use Webman\Context;

/**
 * 为 Webman HTTP 分发器增加内部非阻塞流响应边界。
 *
 * 普通响应保持框架实现不变；远端媒体、转码和实时事件 marker 不经过普通编码器。请求 Context
 * 释放后，相应 runner 独占 socket、子进程、超时、背压和清理生命周期，使长媒体操作不阻塞同步
 * Controller，同时保持每个已认证请求只提交一个响应。
 */
final class Http extends App
{
    /**
     * 把内部流 marker 移交给对应的事件驱动 supervisor。
     *
     * runner 启动失败发生在媒体响应头提交前，因此可返回通用 HTTP 503。日志只记录请求 ID 与异常类，
     * 不记录命令参数、本地路径、远端定位或凭据；移交后 Controller 与请求 Context 不再拥有流生命周期。
     */
    protected static function send($connection, $response, $request): void
    {
        if ($response instanceof MediaSourceStreamResponse) {
            Context::destroy();
            unset($request->context['session']);
            try {
                (new MediaSourceStreamRunner($connection, $response))->start();
            } catch (Throwable $throwable) {
                Log::error('Remote media stream supervisor failed to start.', [
                    'request_id' => $response->streamHeaders['X-Request-ID'] ?? null,
                    'exception_class' => $throwable::class,
                ]);
                $connection->close(new Response(503, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Request-ID' => $response->streamHeaders['X-Request-ID'] ?? '',
                    'Connection' => 'close',
                ], 'Remote media unavailable.'));
            }
            return;
        }
        if ($response instanceof RealtimeEventResponse) {
            Context::destroy();
            unset($request->context['session']);
            try {
                (new RealtimeEventRunner($connection, $response))->start();
            } catch (Throwable $throwable) {
                Log::error('Realtime event supervisor failed to start.', [
                    'request_id' => $response->requestId,
                    'exception_class' => $throwable::class,
                ]);
                $connection->close(new Response(503, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Request-ID' => $response->requestId,
                    'Connection' => 'close',
                ], 'Realtime updates unavailable.'));
            }
            return;
        }

        if (!$response instanceof TranscodeResponse) {
            parent::send($connection, $response, $request);
            return;
        }

        Context::destroy();
        unset($request->context['session']);
        try {
            (new FfmpegTranscodeRunner(
                $connection,
                $response->plan,
                deliveryTicketId: $response->deliveryTicketId,
            ))->start();
        } catch (Throwable $throwable) {
            $response->plan->lease->release();
            Log::error('Audio transcode supervisor failed to start.', [
                'request_id' => $response->plan->requestId,
                'exception_class' => $throwable::class,
            ]);
            $connection->close(new Response(503, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Request-ID' => $response->plan->requestId,
                'Connection' => 'close',
            ], 'Transcoding unavailable.'));
        }
    }
}
