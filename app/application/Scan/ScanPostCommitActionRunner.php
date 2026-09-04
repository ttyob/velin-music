<?php

declare(strict_types=1);

namespace app\application\Scan;

use Closure;
use support\Log;
use Throwable;

/**
 * ScanPostCommitActionRunner 隔离扫描成功终态提交后的非关键扩展动作。
 *
 * 插件通知、个人 M3U 同步和自动刮削调度都在扫描事务提交后执行：它们失败时允许稍后补偿，但不得把真实
 * 的 succeeded 终态改写成 failed，也不得阻止后续动作。run 同时捕获动作和告警日志抛出的 Throwable，
 * 因而调用方可以顺序执行多个动作并保证前一个动作的双重故障不会中断后一个动作。动作必须自行保持幂等，
 * 本边界不重试、不回滚已提交事务，也不记录路径、凭据或异常 message。
 */
final class ScanPostCommitActionRunner
{
    private readonly Closure $warningLogger;

    /**
     * 构建提交后隔离器；warningLogger 仅用于合同测试模拟日志设施故障。
     *
     * @param (Closure(string, array<string, string>): void)|null $warningLogger
     */
    public function __construct(?Closure $warningLogger = null)
    {
        $this->warningLogger = $warningLogger
            ?? static fn (string $message, array $context): mixed => Log::warning($message, $context);
    }

    /**
     * 执行一个可补偿动作；失败时仅写脱敏告警，并保证本方法始终正常返回。
     *
     * context 只能包含稳定标识和错误码，不得放入媒体路径、远端地址、凭据或异常 message。runner 会覆盖
     * exception_class，避免调用方伪造实际失败类型。即使日志写入再次失败也会静默返回，后续动作由调用方
     * 继续执行；本方法不自动重试，防止非幂等扩展动作被重复调用。
     *
     * @param Closure(): mixed $action
     * @param array<string, string> $context
     */
    public function run(Closure $action, string $warningMessage, array $context): void
    {
        try {
            $action();
            return;
        } catch (Throwable $failure) {
            $context['exception_class'] = $failure::class;
        }

        try {
            ($this->warningLogger)($warningMessage, $context);
        } catch (Throwable) {
            // 已提交扫描不能因补偿告警失败而回到失败状态机，也不能递归尝试记录同一个日志故障。
        }
    }
}
