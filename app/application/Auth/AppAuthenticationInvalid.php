<?php

declare(strict_types=1);

namespace app\application\Auth;

/** App 登录、PKCE 或令牌生命周期违反公开契约时使用的稳定领域失败。 */
final class AppAuthenticationInvalid extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
