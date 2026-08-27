<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 表示重复候选筛选、类型或分页参数无效，不包含媒体身份和服务器路径。 */
final class DuplicateMediaInvalid extends RuntimeException
{
}
