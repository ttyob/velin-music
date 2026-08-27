<?php

declare(strict_types=1);

namespace app\application\Account;

use RuntimeException;

/** 表示个人资料版本或认证事实已在校验后发生变化，调用方必须重新读取。 */
final class SelfAccountConflict extends RuntimeException
{
}
