<?php

declare(strict_types=1);

namespace app\application\Job;

use RuntimeException;

/** 表示完整报告超过服务端明确声明的行数或字节安全上限。 */
final class JobReportTooLarge extends RuntimeException
{
}
