<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** PHP 插件 manifest、实现类或声明能力不符合固定协议。 */
class PhpResourcePluginInvalid extends RuntimeException
{
}
