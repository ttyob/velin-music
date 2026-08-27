<?php

declare(strict_types=1);

namespace app\application\ExternalPlayback;

/** 票据未知、失效、越权或媒体身份变化时统一使用，避免公开端点枚举失败原因。 */
final class ExternalPlaybackTicketNotFound extends \RuntimeException
{
}
