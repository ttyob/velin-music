<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** 表示个人令牌名称、范围、到期时间或对象标识不符合固定协议。 */
final class PersonalAccessTokenInvalid extends RuntimeException
{
}
