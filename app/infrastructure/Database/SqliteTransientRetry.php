<?php

declare(strict_types=1);

namespace app\infrastructure\Database;

use Closure;
use Illuminate\Database\QueryException;

/**
 * 对 SQLite 明确报告的瞬时写竞争执行有界退避重试。
 *
 * 该边界只识别 SQLite 原生基础错误码 SQLITE_BUSY(5) 与 SQLITE_LOCKED(6)，包括保留低八位
 * 基础码的扩展错误；不解析可能包含 SQL、绑定值或路径的异常消息，也不重试约束失败、语法错误或
 * 其他数据库异常。调用方必须保证整个 operation 可幂等重放，且不得在其内部持有跨调用事务。
 * 默认最多重试三次，总退避 1.3 秒；耗尽后原异常继续抛出，由业务任务保存真实失败终态。
 * 未来 MySQL 连接必须使用其原生死锁/锁等待错误码建立独立策略，不能复用 SQLite 数字错误码。
 */
final readonly class SqliteTransientRetry
{
    private const SQLITE_BUSY = 5;
    private const SQLITE_LOCKED = 6;

    /** @var list<int> 每次重试前的等待毫秒数。 */
    private const DEFAULT_DELAYS_MS = [100, 300, 900];

    private Closure $sleep;

    /**
     * @param null|Closure(int): void $sleep 接收微秒数；仅供测试替换，生产默认使用 usleep。
     */
    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * 执行可幂等重放的数据库操作，并仅在 SQLite 瞬时锁竞争时退避。
     *
     * operation 每次都必须从无外层事务的干净边界开始；一次失败可能已经提交了此前完成的短事务，
     * 因而调用方仍需依赖 upsert、任务 ID 或其他幂等键收敛。onRetry 只能记录无敏感信息的次数和延迟，
     * 不得再次访问业务数据库。非瞬时异常及重试耗尽均保留原始异常链向上抛出。
     *
     * @template T
     * @param Closure(): T $operation
     * @param null|Closure(int, int): void $onRetry 参数依次为从 1 开始的重试序号和等待毫秒数
     * @return T
     */
    public function run(Closure $operation, ?Closure $onRetry = null): mixed
    {
        $retry = 0;

        while (true) {
            try {
                return $operation();
            } catch (QueryException $exception) {
                if (!$this->isRetryable($exception) || !isset(self::DEFAULT_DELAYS_MS[$retry])) {
                    throw $exception;
                }

                $delayMs = self::DEFAULT_DELAYS_MS[$retry];
                ++$retry;
                $onRetry?->__invoke($retry, $delayMs);
                ($this->sleep)($delayMs * 1000);
            }
        }
    }

    /**
     * 使用驱动结构化错误码判定是否可恢复，不读取异常消息中的 SQL、参数或服务器路径。
     *
     * PDO SQLite 扩展码把基础结果码保存在低八位，例如 SQLITE_BUSY_SNAPSHOT(517) 仍归类为 5。
     * errorInfo 缺失、连接名不是 sqlite 或驱动码不是整数时一律失败关闭，避免误重试永久错误。
     */
    public function isRetryable(QueryException $exception): bool
    {
        if ($exception->connectionName !== 'sqlite') {
            return false;
        }

        $driverCode = $exception->errorInfo[1] ?? null;
        if (!is_int($driverCode)
            && !(is_string($driverCode) && preg_match('/^\d+$/D', $driverCode) === 1)
        ) {
            return false;
        }

        $baseCode = (int) $driverCode & 0xFF;

        return $baseCode === self::SQLITE_BUSY || $baseCode === self::SQLITE_LOCKED;
    }
}
