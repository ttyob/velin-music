<?php

declare(strict_types=1);

namespace app\application\Account;

use RuntimeException;

/** 表示目标会话不存在、不属于当前账号或已撤销，统一响应可防跨账号枚举。 */
final class SelfSessionNotFound extends RuntimeException
{
}
