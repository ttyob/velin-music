<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** 表示经过脱敏的网络库配置、协议、认证、TLS、限流或对象身份失败。 */
class RemoteLibraryUnavailable extends RuntimeException
{
    /** 错误码可进入任务和管理响应；消息不得包含远端 URL、用户标识、路径、令牌、密钥或响应正文。 */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
