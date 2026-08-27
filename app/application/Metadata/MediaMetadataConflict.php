<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 表示字段版本、对象事实或不可变预览在提交前已经变化。 */
final class MediaMetadataConflict extends RuntimeException
{
}
