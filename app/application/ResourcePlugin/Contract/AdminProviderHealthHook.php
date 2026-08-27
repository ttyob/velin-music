<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * AdminProviderHealthHook 定义插件渠道健康快照和固定探测边界。
 *
 * 探测由后台管理员触发，但请求不能携带第三方 URL、Cookie、搜索词或平台 ID；插件只能使用自己的
 * 动态渠道目录和加密配置，并返回稳定错误码与脱敏计数。核心仅负责权限、CSRF 和版本账本校验。
 */
interface AdminProviderHealthHook extends PhpResourcePlugin
{
    /** @return array<string,mixed> */
    public function providerHealth(): array;

    /** @param array<string,mixed> $actor */
    public function testProvider(string $providerKey, array $actor, string $requestId): array;
}
