<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * AdminProviderLoginHook 定义插件自有后台页面的可选渠道登录流程。
 *
 * 核心只在 Cookie Session、`manage_system`、CSRF、活动包和数据库版本全部通过后调用；providerKey、
 * loginType 和 sessionId 仍须由插件按自己的动态目录及 opaque 会话复验。实现不得向浏览器返回 Cookie、
 * 第三方二维码 key、Token、Header 或原始响应，登录成功只能把凭据写入插件自己的加密配置。第三方扫码
 * 无法参与数据库回滚，因此实现必须在持久化失败时保持旧运行配置并允许管理员重新发起。
 */
interface AdminProviderLoginHook extends PhpResourcePlugin
{
    /** @param array<string,mixed> $actor */
    public function startProviderLogin(
        string $providerKey,
        string $loginType,
        array $actor,
        string $requestId,
    ): array;

    /** @param array<string,mixed> $actor */
    public function checkProviderLogin(
        string $providerKey,
        string $sessionId,
        array $actor,
        string $requestId,
    ): array;
}
