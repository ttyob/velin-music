<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** PHP 插件上传暂存、原子发布或受控删除暂时不可用。 */
final class PhpResourcePluginPackageUnavailable extends RuntimeException
{
}
