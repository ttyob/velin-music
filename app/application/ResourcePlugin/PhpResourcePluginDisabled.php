<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use RuntimeException;

/** 插件包仍然保留，但管理员已禁止新的页面、钩子和 Worker 调用。 */
final class PhpResourcePluginDisabled extends RuntimeException
{
}
