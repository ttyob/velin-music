<?php

declare(strict_types=1);

namespace app\application\Metadata;

use InvalidArgumentException;

/** 表示标签写回请求结构、字段版本或确认材料不符合公开契约；异常不包含路径或标签值。 */
final class AudioTagWritebackInvalid extends InvalidArgumentException {}
