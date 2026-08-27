<?php

declare(strict_types=1);

namespace app\application\Auth;

use RuntimeException;

/** 可信代理认证关闭或部署边界配置无效，调用方必须失败关闭而不能回退信任请求头。 */
final class TrustedProxyAuthUnavailable extends RuntimeException
{
}
