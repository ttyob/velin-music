<?php

declare(strict_types=1);

namespace app\process;

use app\application\Scan\ScanWorkerService;
use app\application\Storage\DefaultMediaStorageProvisioner;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Coroutine\Exception\PoolException;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Hosts the single SQLite-aware library scan consumer outside Webman's HTTP workers.
 *
 * The timer is only a low-cost durable-queue check; no filesystem is touched while the queue is
 * empty. One process bounds disk and SQLite pressure. A future Redis wake-up may reduce idle polls,
 * but the database remains the source of truth and lease recovery still runs after restarts.
 */
final class LibraryScanWorker
{
    private bool $busy = false;
    private bool $stopping = false;
    private readonly string $workerId;

    public function __construct(
        private readonly float $pollInterval = 2.0,
        private readonly ScanWorkerService $scans = new ScanWorkerService(),
        private readonly DefaultMediaStorageProvisioner $defaultStorage = new DefaultMediaStorageProvisioner(),
    ) {
        $host = gethostname();
        $this->workerId = ($host === false ? 'velin' : $host) . ':' . getmypid();
    }

    /**
     * Schedules storage provisioning and durable-queue polling after Worker startup.
     *
     * Workerman invokes this lifecycle callback inside a Fiber but Select invokes timers from its
     * non-coroutine event-loop context. Webman's database pool reserves different connection paths
     * for those modes. Database work therefore starts only in provisionStorage(), where it shares
     * the same non-coroutine SQLite connection as every later tick and preserves the configured
     * one-connection-per-process limit.
     */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(0.05, [$this, 'provisionStorage'], [], false);
        Timer::add(0.1, [$this, 'tick'], [], false);
        Timer::add(max(0.5, $this->pollInterval), [$this, 'tick']);
    }

    /**
     * Provisions the fixed container directories once without preventing later queue polling.
     *
     * A mount or permission failure is logged and leaves the persisted storage status in error;
     * queued scans remain durable and may succeed after an operator fixes the mount and restarts the
     * Worker. Context cleanup runs for both outcomes so application-scoped references do not leak
     * between the initialization timer and the first queue timer.
     */
    public function provisionStorage(): void
    {
        try {
            $this->defaultStorage->provision();
        } catch (Throwable $throwable) {
            Log::error('Default media storage is unavailable.', [
                'exception_class' => $throwable::class,
            ]);
        } finally {
            Context::destroy();
        }
    }

    /** Marks the current execution for safe lease release at its next checkpoint. */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /**
     * Claims and executes at most one task; overlapping timer callbacks are ignored.
     *
     * Every accepted tick owns an isolated application Context. The finally block releases that
     * tick's references after success, failure, cancellation, or an unexpected exception, while the
     * pool retains its dedicated non-coroutine SQLite connection for the next timer. Cleanup remains
     * outside the service catch so logging can still use the complete failure scope first.
     */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) {
            return;
        }
        $this->busy = true;
        try {
            $this->scans->recoverStaleLeases();
            $job = $this->scans->claimNext($this->workerId);
            if ($job !== null) {
                $this->scans->execute(
                    $job,
                    fn (): bool => $this->stopping || Worker::getStatus() !== Worker::STATUS_RUNNING,
                );
            }
        } catch (Throwable $throwable) {
            Log::error('Library scan queue tick failed.', [
                'worker_id' => $this->workerId,
                'exception_class' => $throwable::class,
                // PoolException messages are framework-owned constants and contain no SQL or paths.
                'pool_error' => $throwable instanceof PoolException ? $throwable->getMessage() : null,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
