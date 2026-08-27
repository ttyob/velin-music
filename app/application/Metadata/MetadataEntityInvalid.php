<?php

declare(strict_types=1);

namespace app\application\Metadata;

use InvalidArgumentException;

/** 表示元数据实体命令的类型、ID、版本、选择集或完整确认文本无效。 */
final class MetadataEntityInvalid extends InvalidArgumentException
{
}
