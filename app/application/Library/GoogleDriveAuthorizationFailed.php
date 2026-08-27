<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** Google OAuth 启动、回调或一次性授权消费失败；消息必须已经脱敏并可安全返回浏览器。 */
final class GoogleDriveAuthorizationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
