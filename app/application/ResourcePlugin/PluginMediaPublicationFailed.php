<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** 插件媒体发布的脱敏稳定失败；物理路径、远端 URL 和第三方正文不得进入消息。 */
final class PluginMediaPublicationFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
