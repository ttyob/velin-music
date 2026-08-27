<?php

declare(strict_types=1);

namespace app\application\Job;

use RuntimeException;

/** 表示统一任务命令读取后来源状态或乐观版本已经变化，调用方必须刷新而不能自动重放。 */
final class JobCenterConflict extends RuntimeException
{
}
