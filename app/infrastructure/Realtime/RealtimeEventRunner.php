<?php

declare(strict_types=1);

namespace app\infrastructure\Realtime;

use app\application\Realtime\RealtimeEventService;
use app\http\RealtimeEventResponse;
use JsonException;
use support\Log;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * Owns one bounded SSE socket without blocking a Webman worker.
 *
 * A one-second event-loop timer reads only small invalidation rows. Every read rechecks account,
 * capability, and library scope. The stream closes after 55 seconds so Session revocation is also
 * revalidated by normal HTTP authentication on reconnect. Disconnect, backpressure, query failure,
 * or lifetime expiry removes the timer and restores prior socket callbacks.
 */
final class RealtimeEventRunner
{
    private EventInterface $events;
    private ?int $timerId = null;
    private int $cursor;
    private float $startedAt = 0.0;
    private float $lastHeartbeatAt = 0.0;
    private bool $closed = false;
    private mixed $previousOnClose = null;
    private mixed $previousOnBufferFull = null;

    public function __construct(
        private readonly TcpConnection $connection,
        private readonly RealtimeEventResponse $response,
        private readonly RealtimeEventService $service = new RealtimeEventService(),
    ) {
        $this->events = Worker::getEventLoop();
        $this->cursor = $response->lastEventId ?? 0;
    }

    /** Starts headers, an initial checkpoint/snapshot event, and the bounded non-blocking poll timer. */
    public function start(): void
    {
        // Resolve the initial cursor before committing HTTP headers so an unavailable database can
        // still produce the caller's normal generic 503 response instead of a malformed SSE body.
        if ($this->response->lastEventId === null) {
            $this->cursor = $this->service->currentCursor();
        }
        $this->installCallbacks();
        $this->connection->pauseRecv();
        $this->connection->send($this->headers(), true);
        $this->startedAt = $this->lastHeartbeatAt = microtime(true);

        if ($this->response->lastEventId === null) {
            $this->sendEvent($this->cursor, 'snapshot', ['reason' => 'initial']);
        } else {
            $this->poll();
        }
        if (!$this->closed) {
            $this->timerId = $this->events->repeat(1.0, fn () => $this->poll());
        }
    }

    /** Reads authorized events, emits reset on replay gaps, and sends checkpoints across hidden rows. */
    private function poll(): void
    {
        if ($this->closed) {
            return;
        }
        try {
            $batch = $this->service->read($this->response->actor, $this->cursor);
            if ($batch['reset']) {
                $this->cursor = $batch['cursor'];
                $this->sendEvent($this->cursor, 'reset', ['reason' => 'replay_unavailable']);
            } else {
                foreach ($batch['events'] as $event) {
                    $this->cursor = $event['id'];
                    $this->sendEvent($event['id'], $event['topic'], [
                        'resourceVersion' => $event['resourceVersion'],
                    ]);
                }
                if ($batch['cursor'] > $this->cursor) {
                    $this->cursor = $batch['cursor'];
                    $this->sendEvent($this->cursor, 'checkpoint', []);
                }
            }
        } catch (Throwable $throwable) {
            Log::warning('Realtime event stream closed after poll failure.', [
                'request_id' => $this->response->requestId,
                'exception_class' => $throwable::class,
            ]);
            $this->close();
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastHeartbeatAt >= 15.0) {
            $this->connection->send(': heartbeat ' . time() . "\n\n", true);
            $this->lastHeartbeatAt = $now;
        }
        if ($now - $this->startedAt >= 55.0) {
            $this->connection->send("retry: 3000\n\n", true);
            $this->close();
        }
    }

    /** Encodes one allowlisted event name and JSON object without accepting raw line-oriented data. */
    private function sendEvent(int $id, string $event, array $data): void
    {
        if (!in_array($event, [
            'snapshot', 'reset', 'checkpoint',
            'notifications.changed', 'jobs.changed', 'permissions.changed',
        ], true)) {
            return;
        }
        try {
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->close();
            return;
        }
        if ($this->connection->send("id: {$id}\nevent: {$event}\ndata: {$json}\n\n", true) === false) {
            $this->close();
        }
    }

    /** Chains only lifecycle callbacks; tiny events never queue through an unbounded buffer. */
    private function installCallbacks(): void
    {
        $this->previousOnClose = $this->connection->onClose;
        $this->previousOnBufferFull = $this->connection->onBufferFull;
        $this->connection->onClose = function (TcpConnection $connection): void {
            $this->cleanup();
            if (is_callable($this->previousOnClose)) {
                ($this->previousOnClose)($connection);
            }
        };
        $this->connection->onBufferFull = function (TcpConnection $connection): void {
            $this->close();
            if (is_callable($this->previousOnBufferFull)) {
                ($this->previousOnBufferFull)($connection);
            }
        };
    }

    /** Closes the raw SSE socket after releasing timer and callback ownership. */
    private function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->cleanup();
        if ($this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $this->connection->close('', true);
        }
    }

    /** Removes the one owned timer and restores callbacks while the socket remains established. */
    private function cleanup(): void
    {
        if ($this->timerId !== null) {
            $this->events->offRepeat($this->timerId);
            $this->timerId = null;
        }
        if ($this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $this->connection->onClose = $this->previousOnClose;
            $this->connection->onBufferFull = $this->previousOnBufferFull;
        }
    }

    /** Returns server-owned anti-buffering headers; no actor, path, or event payload enters headers. */
    private function headers(): string
    {
        return "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream\r\n"
            . "Cache-Control: private, no-store, no-transform\r\n"
            . "X-Accel-Buffering: no\r\n"
            . 'X-Request-ID: ' . str_replace(["\r", "\n"], '', $this->response->requestId) . "\r\n"
            . "Connection: close\r\n\r\n";
    }
}
