<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use RuntimeException;

/** 携带可持久化稳定错误码和是否允许重试；消息不得包含响应正文、URL、凭据或歌曲信息。 */
final class ScrobbleDeliveryFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable)
    {
        parent::__construct($errorCode);
    }
}
