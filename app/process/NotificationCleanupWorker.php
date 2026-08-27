<?php

declare(strict_types=1);

namespace app\process;

use app\application\Notification\NotificationRetentionService;
use support\Log;
use Throwable;
use Webman\Context;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Hosts bounded notification/replay retention outside HTTP and scan workers.
 *
 * One process wakes at a low frequency and deletes at most one configured batch per table. It never
 * opens media paths or loops until empty, which prevents a large overdue backlog from monopolizing
 * SQLite. Remaining rows are handled by later ticks; stopping simply abandons no in-memory state.
 */
final class NotificationCleanupWorker
{
    private bool $busy = false;
    private bool $stopping = false;

    public function __construct(
        private readonly float $interval = 3600.0,
        private readonly int $batchSize = 500,
        private readonly NotificationRetentionService $retention = new NotificationRetentionService(),
    ) {
    }

    /** Schedules one post-start cleanup and recurring low-frequency batches on the event loop. */
    public function onWorkerStart(Worker $worker): void
    {
        Timer::add(30.0, [$this, 'tick'], [], false);
        Timer::add(max(60.0, $this->interval), [$this, 'tick']);
    }

    /** Prevents a new retention transaction after Workerman starts graceful shutdown. */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stopping = true;
    }

    /** Runs one idempotent bounded batch and always releases the process-local application context. */
    public function tick(): void
    {
        if ($this->busy || $this->stopping) {
            return;
        }
        $this->busy = true;
        try {
            $deleted = $this->retention->prune(gmdate('Y-m-d\TH:i:s\Z'), $this->batchSize);
            if ($deleted['notifications'] > 0 || $deleted['realtimeEvents'] > 0) {
                Log::info('Expired notification state pruned.', [
                    'notification_count' => $deleted['notifications'],
                    'realtime_event_count' => $deleted['realtimeEvents'],
                ]);
            }
        } catch (Throwable $throwable) {
            Log::error('Notification retention tick failed.', [
                'exception_class' => $throwable::class,
            ]);
        } finally {
            $this->busy = false;
            Context::destroy();
        }
    }
}
