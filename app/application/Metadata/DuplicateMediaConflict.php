<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 表示重复组、强证据或合并后的个人状态已变化，调用方必须刷新而不能强制覆盖。 */
final class DuplicateMediaConflict extends RuntimeException
{
}
