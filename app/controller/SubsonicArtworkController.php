<?php

declare(strict_types=1);

namespace app\controller;

use app\application\Artwork\ArtworkService;
use app\application\Artwork\ArtworkTransformService;
use app\application\Subsonic\SubsonicArtworkTicketService;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 使用短时票据向不附加 Subsonic 认证参数的客户端提供艺人图片。 */
final class SubsonicArtworkController
{
    /**
     * 解析票据、实时复验账号和艺人授权后返回原图或有界缩略图。
     *
     * size 只接受 16-2048，缺省返回原图；它不参与授权且不能选择任意文件。GET/HEAD 都执行完全相同的
     * 票据、授权和图片身份检查，未知、过期、撤权、无图及畸形输入统一 404，避免形成账号或艺人枚举
     * 信号。意外运行时故障只记录异常类别和服务端 request ID，不记录票据、URL、账号或艺人 ID。
     */
    public function show(Request $request, string $ticket): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $resolved = (new SubsonicArtworkTicketService())->resolve($ticket);
            $size = $request->get('size');
            if ($resolved === null || !is_string($size)
                || preg_match('/^\d{2,4}$/D', $size) !== 1
                || (int) $size < 16 || (int) $size > 2048) {
                return $this->notFound($requestId);
            }
            $artwork = (new ArtworkService())->resolveArtist($resolved['actor'], $resolved['artistId']);
            $artwork = (new ArtworkTransformService())->resize($artwork, (int) $size);
            $headers = [
                'Content-Type' => $artwork->mimeType,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=3600, must-revalidate',
                'ETag' => $artwork->etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $artwork->modifiedAt) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
            ];
            if (strtoupper($request->method()) === 'HEAD') return response('', 200, $headers);
            return response('', 200, $headers)->withFile($artwork->path);
        } catch (Throwable $throwable) {
            Log::warning('Subsonic ticketed artwork request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return $this->notFound($requestId);
        }
    }

    /** 所有票据和授权失败使用相同无正文响应，且禁止共享缓存。 */
    private function notFound(string $requestId): Response
    {
        return response('', 404, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $requestId,
        ]);
    }
}
