<?php

declare(strict_types=1);

namespace app\application\Storage;

use RuntimeException;

/** 表示管理员提交的阈值或挂载确认采样不满足严格契约。 */
final class StorageGovernanceInvalid extends RuntimeException
{
}
