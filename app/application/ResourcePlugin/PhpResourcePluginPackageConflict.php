<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** PHP 插件包已存在、状态正在变化或需要先重启完成上一操作。 */
final class PhpResourcePluginPackageConflict extends RuntimeException
{
}
