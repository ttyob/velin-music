<?php

declare(strict_types=1);

namespace app\http;

use app\application\Media\PlayableMedia;
use support\Response;

/**
 * 为统一媒体读取源选择本地 Workerman 文件响应或远端 Range supervisor。
 *
 * 本地文件继续使用框架 `withFile` 的零拷贝/背压路径；WebDAV 不创建临时文件，改为内部响应标记。两种
 * 分支共享相同状态、长度、Content-Range 和业务响应头，调用方无需知道远端 URL 或凭据。
 */
final readonly class MediaSourceResponseFactory
{
    /**
     * 创建完整或单区间响应。
     *
     * length 必须是实际响应字节数，完整响应也传总大小。DLNA ticket 只交给远端 runner 记录投递进度；
     * 本地分支仍由 Controller 在拿到真实 withFile 头块后安装既有 observer。
     *
     * @param array<string,string> $headers
     */
    public function create(
        PlayableMedia $media,
        int $status,
        array $headers,
        int $offset,
        int $length,
        ?string $deliveryTicketId = null,
    ): Response {
        if (!in_array($status, [200, 206], true) || $offset < 0 || $length < 1
            || $offset + $length > $media->fileSize) {
            throw new \InvalidArgumentException('MEDIA_RESPONSE_RANGE_INVALID');
        }
        $path = $media->source->localPath();
        if ($path !== null) {
            $response = response('', $status, $headers);
            return $status === 200 && $offset === 0 && $length === $media->fileSize
                ? $response->withFile($path)
                : $response->withFile($path, $offset, $length);
        }
        if ($status === 206) $headers['Content-Range'] = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $media->fileSize;
        $headers['Content-Length'] = (string) $length;
        $headers['Accept-Ranges'] = 'bytes';
        return new MediaSourceStreamResponse(
            $media->source,
            $status,
            $headers,
            $offset,
            $length,
            $media->fileSize,
            $deliveryTicketId,
        );
    }
}
