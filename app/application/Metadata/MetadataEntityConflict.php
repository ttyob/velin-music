<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 表示媒体在预览后变化、目标唯一键冲突、已有重定向或回滚后置条件不再成立。 */
final class MetadataEntityConflict extends RuntimeException
{
}
