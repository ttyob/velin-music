<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/**
 * 表示封面 Provider 服务的稳定失败，不携带远端响应正文、URL、凭据或服务器路径。
 *
 * `retryable` 只允许 Worker 对网络、429 和 5xx 做有界退避；协议、身份、许可或授权错误必须立即终结，
 * 防止重试把已撤回资源重新发布。HTTP Controller 只返回稳定 reasonCode，不记录异常 message 链。
 */
final class ArtworkProviderRemoteFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly bool $retryable,
        public readonly int $retryAfterSeconds = 2,
    ) {
        parent::__construct($reasonCode);
    }
}
