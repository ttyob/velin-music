<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 歌曲不在当前操作者可管理的本地媒体范围内。 */
final class MediaDeletionNotFound extends RuntimeException
{
}
