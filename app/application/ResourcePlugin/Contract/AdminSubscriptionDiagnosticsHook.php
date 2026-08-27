<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * AdminSubscriptionDiagnosticsHook 是订阅插件的可选诊断扩展。
 *
 * 单独接口保持既有订阅插件的向后兼容；试跑和运行历史必须使用脱敏统计，不能暴露 URL、磁力、Hash、
 * Cookie 或上游正文。核心只负责管理员权限、CSRF 和数据库版本校验。
 */
interface AdminSubscriptionDiagnosticsHook extends PhpResourcePlugin
{
    /** @param array<string,mixed> $actor */
    public function subscriptionRuns(string $subscriptionId, array $actor, int $limit = 20): array;

    /** @param array<string,mixed> $actor */
    public function trialSubscription(string $subscriptionId, array $actor, string $requestId): array;
}
