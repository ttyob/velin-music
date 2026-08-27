<?php

declare(strict_types=1);

namespace app\application\User;

use RuntimeException;

/** 批量用户命令、预览令牌或确认字段不符合固定协议。 */
final class UserBulkInvalid extends RuntimeException
{
}
