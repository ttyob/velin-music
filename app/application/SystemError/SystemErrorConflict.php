<?php

declare(strict_types=1);

namespace app\application\SystemError;

use RuntimeException;

/** 表示管理员读取后记录已复发、已被他人处理或版本发生其他并发变化。 */
final class SystemErrorConflict extends RuntimeException
{
}
