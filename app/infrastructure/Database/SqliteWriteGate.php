<?php

declare(strict_types=1);

namespace app\infrastructure\Database;

use Closure;
use RuntimeException;

/**
 * 使用运行时文件锁串行化同一部署内的 SQLite 写事务。
 *
 * SQLite 的 WAL 允许读写并行，但同一数据库仍只有一个写者。Webman 的多个 Worker 拥有各自的
 * PDO 连接，`busy_timeout` 只能延迟失败，不能保证扫描和刮削在锁等待期间都能继续。调用方应把
 * 这个闸门放在短 `Db::transaction()` 外层；禁止把文件遍历、网络请求或 FFmpeg 工作放进闸门。
 * 锁文件只表示进程间排队，不保存业务数据，进程异常退出时由操作系统自动释放。操作失败或抛出
 * 异常时仍必须释放句柄，避免后续 Worker 永久等待；闸门不修改数据库、不吞异常，也不提供跨主机
 * 锁语义，未来 MySQL 部署应使用数据库自身的事务并发控制。
 */
final class SqliteWriteGate
{
    private string $lockPath;

    /**
     * @param null|string $lockPath 仅用于测试替换；生产环境固定使用 runtime 下的 SQLite 锁文件。
     */
    public function __construct(?string $lockPath = null)
    {
        $this->lockPath = $lockPath ?? runtime_path('sqlite-write.lock');
    }

    /**
     * 在同一部署的 SQLite 写入闸门内执行一个幂等、短时数据库事务。
     *
     * 前置条件：operation 自身负责开启和提交事务，且不在其中执行网络或长时间文件操作。锁文件的
     * 父目录必须已由运行时创建；测试可以提供独立临时文件。无论 operation 成功、抛出业务异常还是
     * 数据库异常，句柄都会在 finally 中解锁并关闭，原异常原样向上传递。
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     * @throws RuntimeException 锁文件无法创建或获取独占锁时抛出固定错误，不暴露服务器路径。
     */
    public function run(Closure $operation): mixed
    {
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('SQLITE_WRITE_GATE_UNAVAILABLE');
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('SQLITE_WRITE_GATE_UNAVAILABLE');
            }
            try {
                return $operation();
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
