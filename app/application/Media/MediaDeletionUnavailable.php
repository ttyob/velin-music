<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 文件移动、数据库提交或补偿恢复无法安全完成。 */
final class MediaDeletionUnavailable extends RuntimeException
{
}
