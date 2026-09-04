<?php

declare(strict_types=1);

namespace app\http;

use app\application\Media\PlayableMedia;
use support\Response;

/**
 * 为统一媒体读取源选择本地 Go/Workerman 文件响应或远端 Range supervisor。
 *
 * 本地 Web/App/Subsonic 文件优先生成内置 Go 网关描述；网关关闭、文件位于裸机允许根外或 DLNA 仍需
 * Workerman socket 进度观察时使用 `withFile`。WebDAV 不创建临时文件，改为内部响应标记。各分支共享
 * 相同状态、范围和业务响应头，调用方无需知道本地路径、远端 URL 或凭据。
 */
final readonly class MediaSourceResponseFactory
{
    public function __construct(private LocalFileResponseFactory $localFiles = new LocalFileResponseFactory())
    {
    }

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
            // DLNA 原始直放仍由 Workerman 观察实际 socket 写入量；迁移进度统计前不能让 Go 提前接管正文。
            return $this->localFiles->create(
                $path,
                $status,
                $headers,
                $offset,
                $length,
                accelerate: $deliveryTicketId === null,
            );
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
