<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 文件身份、歌曲状态或并发删除状态已经变化。 */
final class MediaDeletionConflict extends RuntimeException
{
}
