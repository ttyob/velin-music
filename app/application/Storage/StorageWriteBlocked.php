<?php

declare(strict_types=1);

namespace app\application\Storage;

use RuntimeException;

/** 高风险写入因严重容量或已确认挂载身份变化被拒绝。 */
final class StorageWriteBlocked extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
