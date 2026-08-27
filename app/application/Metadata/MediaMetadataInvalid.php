<?php

declare(strict_types=1);

namespace app\application\Metadata;

use InvalidArgumentException;

/** 表示字段、筛选、分页或命令负载不符合元数据维护的封闭契约。 */
final class MediaMetadataInvalid extends InvalidArgumentException
{
}
