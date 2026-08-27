<?php

declare(strict_types=1);

namespace app\application\Export;

use RuntimeException;

/** 表示个人导出对象、状态转换或下载产物不符合受控协议。 */
final class PersonalDataExportInvalid extends RuntimeException
{
}
