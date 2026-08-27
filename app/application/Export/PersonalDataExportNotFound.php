<?php

declare(strict_types=1);

namespace app\application\Export;

use RuntimeException;

/** 当前账号看不到指定个人导出任务；不存在与越权使用同一异常，避免任务枚举。 */
final class PersonalDataExportNotFound extends RuntimeException
{
}
