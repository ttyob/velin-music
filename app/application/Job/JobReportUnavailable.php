<?php

declare(strict_types=1);

namespace app\application\Job;

use RuntimeException;

/** 表示任务尚未进入可生成稳定完整报告的终态。 */
final class JobReportUnavailable extends RuntimeException
{
}
