<?php

declare(strict_types=1);

namespace app\application\SystemError;

use RuntimeException;

/** 对格式错误、不存在和已被并发清理的异常记录统一返回不可枚举的不存在。 */
final class SystemErrorNotFound extends RuntimeException
{
}
