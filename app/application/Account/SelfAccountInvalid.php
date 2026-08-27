<?php

declare(strict_types=1);

namespace app\application\Account;

use RuntimeException;

/** 表示当前账号自助命令的公开字段、密码或会话标识不符合约束。 */
final class SelfAccountInvalid extends RuntimeException
{
}
