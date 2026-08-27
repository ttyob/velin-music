<?php

declare(strict_types=1);

namespace app\application\ExternalPlayback;

/** 外部播放协商失败的稳定领域异常，不携带设备地址、票据或媒体路径。 */
final class ExternalPlaybackUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
