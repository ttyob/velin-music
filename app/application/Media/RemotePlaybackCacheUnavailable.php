<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 后台远程播放预缓存无法安全完成；reasonCode 可记录，异常正文不得包含路径、账号或远端信息。 */
final class RemotePlaybackCacheUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
