<?php

declare(strict_types=1);

namespace app\application\Dlna;

use app\infrastructure\Database\SqliteTransientRetry;
use app\infrastructure\Database\SqliteWriteGate;
use Closure;
use Illuminate\Database\QueryException;
use support\Db;
use support\Log;

/**
 * 为 DLNA 票据提供受同部署写闸门保护的 SQLite 短事务。
 *
 * Renderer 命令不能与数据库组成原子事务，因此本边界只包围票据创建、撤销和节流时间更新，不允许在
 * operation 内执行 SSDP、SOAP、转码、文件读取或 Redis 租约操作。SQLite 明确返回基础码 5/6 时会在
 * 闸门外按固定上限退避并重放整个短事务；调用方必须使用稳定票据 ID 或幂等条件更新。重试耗尽映射为
 * `DLNA_DATABASE_BUSY`，其他数据库错误映射为 `DLNA_DATABASE_UNAVAILABLE`，两者均保留异常链但不会把
 * SQL、绑定值、媒体路径或票据写入日志。未来 MySQL 必须替换错误码策略，不能复用此 SQLite 分类。
 */
final readonly class DlnaDatabaseWriter
{
    private Closure $logger;

    /**
     * @param null|Closure(string,array<string,int|string>):void $logger 仅供测试截获脱敏日志；生产使用结构化日志
     */
    public function __construct(
        private SqliteTransientRetry $retry = new SqliteTransientRetry(),
        private SqliteWriteGate $writeGate = new SqliteWriteGate(),
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $level, array $context): void {
            Log::log($level, 'DLNA SQLite ticket transaction failed.', $context);
        };
    }

    /**
     * 执行一个可幂等重放的 DLNA 票据短事务，并把驱动异常收敛为稳定领域错误。
     *
     * operation 每次从无外层事务的边界开始；失败事务由 Illuminate 回滚，文件锁在异常传播前释放。
     * onRetry 和终态日志只包含固定原因码、重试序号、退避时长及数字驱动码，不记录异常消息。闸门自身
     * 无法创建时保留运行时异常，由控制器统一返回服务不可用；它不是 SQLite 驱动竞争，不能伪装成 BUSY。
     *
     * @template T
     * @param Closure():T $operation
     * @return T
     * @throws DlnaUnavailable SQLite 写竞争耗尽或发生非瞬时数据库故障
     */
    public function run(Closure $operation): mixed
    {
        try {
            return $this->retry->run(
                fn (): mixed => $this->writeGate->run(static fn (): mixed => Db::transaction($operation)),
                function (int $retryNumber, int $delayMs): void {
                    $this->safeLog('warning', [
                        'reason_code' => 'DLNA_DATABASE_BUSY_RETRY',
                        'retry_number' => $retryNumber,
                        'delay_ms' => $delayMs,
                    ]);
                },
            );
        } catch (QueryException $failure) {
            $retryable = $this->retry->isRetryable($failure);
            $reasonCode = $retryable ? 'DLNA_DATABASE_BUSY' : 'DLNA_DATABASE_UNAVAILABLE';
            $driverCode = $failure->errorInfo[1] ?? 0;
            $this->safeLog('error', [
                'reason_code' => $reasonCode,
                'driver_code' => is_int($driverCode) || is_string($driverCode) ? (string) $driverCode : 'unknown',
            ]);
            throw new DlnaUnavailable($reasonCode, 'DLNA 票据数据库暂时不可用。', $failure);
        }
    }

    /**
     * 尽力写入不含业务标识的数据库分类日志。
     *
     * 日志基础设施不是票据事务的一部分；即使 handler 配置损坏或磁盘不可写，也必须继续原有重试或抛出
     * 原始数据库领域错误，不能用日志异常替换根因。本方法不做数据库、网络或文件补偿。
     *
     * @param array<string,int|string> $context
     */
    private function safeLog(string $level, array $context): void
    {
        try {
            ($this->logger)($level, $context);
        } catch (\Throwable) {
        }
    }
}
