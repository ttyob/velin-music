<?php

declare(strict_types=1);

namespace app\application\Export;

use RuntimeException;

/** 仅在 Worker 内部中断分页读取或产物发布；不会把路径或个人数据带入错误正文。 */
final class PersonalDataExportCancelled extends RuntimeException
{
}
