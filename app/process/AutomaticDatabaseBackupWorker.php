<?php

declare(strict_types=1);

namespace app\process;

use app\application\System\SqliteBackupService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 每日从独立进程创建一份在线 SQLite 备份。
 *
 * 进程内 busy 防止计时器重入，服务内非阻塞文件锁防止 CLI 与自动任务并发。失败仅记录稳定异常类型，
 * 不输出数据库或备份路径；下个周期重新创建，不覆盖或删除上一份有效备份。
 */
final class AutomaticDatabaseBackupWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 86_400.0,
        private readonly SqliteBackupService $backups = new SqliteBackupService(),
    ) {}

    /** 启动五分钟后创建首份备份，随后周期不得短于一小时。 */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(300.0, [$this, 'tick'], [], false);
        Timer::add(max(3_600.0, $this->interval), [$this, 'tick']);
    }

    /** 优雅停止后不再启动可能持续数秒的 VACUUM INTO。 */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** 创建并验证一份备份，只记录不透明 ID 和大小。 */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) return;
        $this->busy = true;
        try {
            $result = $this->backups->createAutomatic();
            Log::info('Automatic SQLite backup created.', [
                'backup_id' => $result['backupId'], 'byte_size' => $result['byteSize'],
            ]);
        } catch (Throwable $throwable) {
            Log::error('Automatic SQLite backup failed.', ['exception_class' => $throwable::class]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
