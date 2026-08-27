<?php

declare(strict_types=1);

namespace app\application\Storage;

use RuntimeException;

/** 表示策略版本或挂载事实已在管理员操作期间发生变化。 */
final class StorageGovernanceConflict extends RuntimeException
{
}
