<?php

declare(strict_types=1);

namespace app\application\Upload;

use RuntimeException;

/** 表示暂存根、磁盘空间、文件身份、媒体校验或原子发布的安全前置条件失败。 */
final class UploadStorageFailed extends RuntimeException
{
}
