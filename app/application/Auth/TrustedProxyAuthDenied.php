<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** 可信代理来源、身份或账号状态不满足登录条件；对外不得细分具体原因。 */
final class TrustedProxyAuthDenied extends RuntimeException
{
}
