<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** 指定 PHP 资源插件未安装或安装包当前不可用。 */
class PhpResourcePluginNotFound extends RuntimeException
{
}
