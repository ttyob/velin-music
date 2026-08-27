<?php

declare(strict_types=1);

namespace app\infrastructure\Dlna;

use Workerman\Connection\TcpConnection;
use Workerman\Timer;

/**
 * 观察一个 DLNA 文件响应真正写入 socket 的媒体字节，而不是 PHP 已排队的发送缓存。
 *
 * 调用方必须在发送响应头之前提供当前 connection->bytesWritten 基线及精确头部长度。观察器每 250ms
 * 读取 Workerman 累计写出量，扣除本响应头后再限制到 body 长度；getSendBufferQueueSize 不计入进度，
 * 因而不会把尚未交给内核的队列字节冒充音响缓存。连接关闭或 body 写完后定时器自行回收。
 */
final class DlnaDeliveryObserver
{
    private ?int $timerId = null;

    private function __construct(
        private readonly TcpConnection $connection,
        private readonly string $ticketId,
        private readonly int $offset,
        private readonly int $length,
        private readonly int $totalBytes,
        private readonly int $baselineBytes,
        private readonly int $headerBytes,
        private readonly DlnaDeliveryProgressStore $store,
    ) {
    }

    /**
     * 启动当前 Worker 内的短期观察；Redis 或 Timer 异常不会改变媒体响应。
     *
     * Range 的 offset 会投影为媒体文件中的最远投递位置，Store 再对并发 Range 原子取最大值。响应头长度
     * 来自即将发送的完整头块，不使用固定常量；keep-alive 连接先前响应由 baseline 排除。
     */
    public static function start(
        TcpConnection $connection,
        string $ticketId,
        int $offset,
        int $length,
        int $totalBytes,
        int $headerBytes,
    ): void {
        if ($length <= 0 || $totalBytes <= 0 || $headerBytes <= 0) return;
        $observer = new self(
            $connection,
            $ticketId,
            max(0, $offset),
            $length,
            $totalBytes,
            $connection->bytesWritten,
            $headerBytes,
            new DlnaDeliveryProgressStore(),
        );
        $observer->store->record($ticketId, max(0, $offset), $totalBytes);
        $observer->timerId = Timer::add(0.25, static fn () => $observer->sample());
    }

    /** 采样只计 bytesWritten；即便同一连接随后复用，body 上限也会把本响应固定在精确长度内。 */
    private function sample(): void
    {
        $written = max(0, $this->connection->bytesWritten - $this->baselineBytes - $this->headerBytes);
        $bodyBytes = min($this->length, $written);
        $this->store->record($this->ticketId, min($this->totalBytes, $this->offset + $bodyBytes), $this->totalBytes);
        if ($bodyBytes >= $this->length || $this->connection->getStatus() === TcpConnection::STATUS_CLOSED) {
            if ($this->timerId !== null) Timer::del($this->timerId);
            $this->timerId = null;
        }
    }
}
