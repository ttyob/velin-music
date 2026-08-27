<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 表示歌曲发现接口收到非规范整数或超出公开边界的分页参数。 */
final class MediaDiscoveryInvalid extends RuntimeException
{
}
