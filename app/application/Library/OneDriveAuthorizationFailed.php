<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** 表示可安全返回浏览器的设备授权失败；消息和错误码均不得包含 Microsoft 原始响应或秘密。 */
final class OneDriveAuthorizationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
