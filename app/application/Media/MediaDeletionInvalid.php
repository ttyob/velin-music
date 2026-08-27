<?php

declare(strict_types=1);

namespace app\application\Media;

use RuntimeException;

/** 删除命令参数或文件库前置条件不满足。 */
final class MediaDeletionInvalid extends RuntimeException
{
}
