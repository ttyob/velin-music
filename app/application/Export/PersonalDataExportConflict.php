<?php

declare(strict_types=1);

namespace app\application\Export;

use RuntimeException;

/** 表示同账号已有活动导出，或乐观版本对应的任务状态已经变化。 */
final class PersonalDataExportConflict extends RuntimeException
{
}
