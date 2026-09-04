<?php

declare(strict_types=1);

namespace app\http;

use support\Response;

/**
 * 在内置 Go 网关和 Workerman 文件响应之间选择本地文件发送方式。
 *
 * 网关启用且文件位于允许根时，响应只携带一次性加密描述，正文由 Go 基于同一客户端连接直接读取；
 * 裸机未启用、根外兼容文件或明确禁止加速的协议继续使用 `withFile()`。两条路径都要求调用方已完成
 * 认证和业务授权，本工厂只处理文件身份与传输，不能根据路径授予媒体可见性。
 */
final class LocalFileResponseFactory
{
    private bool $enabled;
    private ?MediaDeliveryTokenEncoder $tokens;

    public function __construct(?bool $enabled = null, ?MediaDeliveryTokenEncoder $tokens = null)
    {
        $this->enabled = $enabled ?? (bool) config('media_delivery.enabled', false);
        $this->tokens = $this->enabled ? ($tokens ?? new MediaDeliveryTokenEncoder()) : null;
    }

    /**
     * 创建完整或单范围文件响应。
     *
     * status 只接受 200/206，offset/length 必须由上层 Range 解析器验证。`accelerate=false` 用于仍依赖
     * Workerman socket 投递进度观察的 DLNA；它不会关闭全局网关。加速响应不预写 Content-Length 或
     * Content-Range，由 Go 解密并复验文件总大小后生成，避免客户端直达内部端口时暴露路径或错误长度。
     */
    public function create(
        string $path,
        int $status,
        array $headers,
        int $offset,
        int $length,
        bool $accelerate = true,
    ): Response {
        if (!in_array($status, [200, 206], true) || $offset < 0 || $length < 1) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_RANGE_INVALID');
        }
        if (!$this->enabled || !$accelerate || !$this->tokens instanceof MediaDeliveryTokenEncoder
            || !$this->tokens->supports($path)) {
            $response = response('', $status, $headers);
            return $status === 200 && $offset === 0
                ? $response->withFile($path)
                : $response->withFile($path, $offset, $length);
        }
        unset($headers['Content-Length'], $headers['Content-Range'], $headers['X-Velin-Media-Delivery']);
        $headers['X-Velin-Media-Delivery'] = $this->tokens->encode($path, $offset, $length);
        return response('', $status, $headers);
    }
}
