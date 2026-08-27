<?php

declare(strict_types=1);

namespace app\application\SystemError;

use RuntimeException;

/** 表示后台异常筛选或状态命令超出固定词汇和有界输入范围。 */
final class SystemErrorInvalid extends RuntimeException
{
}
