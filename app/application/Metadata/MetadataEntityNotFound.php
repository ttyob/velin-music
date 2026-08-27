<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 对实体不存在和操作者未管理其全部关联音乐库使用同一不可枚举失败。 */
final class MetadataEntityNotFound extends RuntimeException
{
}
