<?php

declare(strict_types=1);

namespace app\application\Upload;

use RuntimeException;

/** 表示幂等键重用、版本过期、偏移不连续或目标已存在等可恢复并发冲突。 */
final class UploadConflict extends RuntimeException
{
}
