<?php

declare(strict_types=1);

namespace app\infrastructure\Media;

use app\http\MediaSourceStreamResponse;
use app\infrastructure\Dlna\DlnaDeliveryObserver;
use RuntimeException;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * 以客户端背压驱动 WebDAV 远端音频的分段直放，不创建完整本地缓存。
 *
 * 每次同步上游读取最多 1 MiB，第一段在提交 HTTP 头前取得，故认证、Range 或对象版本失败仍可返回 503。
 * 后续段通过事件循环短延迟调度，客户端发送缓冲区满时暂停；断开、短读或上游失败会停止调度、恢复原
 * socket 回调并关闭连接。源对象及异常内容不写日志，Range GET 自身继续由 WebDavClient 复验身份。
 */
final class MediaSourceStreamRunner
{
    private const CHUNK_BYTES = 1_048_576;

    private EventInterface $events;
    private int $sent = 0;
    private bool $paused = false;
    private bool $finished = false;
    private ?int $timerId = null;
    private mixed $previousOnClose = null;
    private mixed $previousOnBufferFull = null;
    private mixed $previousOnBufferDrain = null;

    public function __construct(
        private readonly TcpConnection $connection,
        private readonly MediaSourceStreamResponse $response,
    ) {
        $this->events = Worker::getEventLoop();
    }

    /**
     * 读取第一段并提交精确长度响应，之后把所有权交给事件循环。
     *
     * @throws RuntimeException 首段读取或头发送失败；此时尚未向客户端提交媒体头，Http 可返回 503。
     */
    public function start(): void
    {
        if ($this->response->source->localPath() !== null || $this->response->length < 1
            || $this->response->offset < 0
            || $this->response->offset + $this->response->length > $this->response->totalSize) {
            throw new RuntimeException('REMOTE_MEDIA_RESPONSE_INVALID');
        }
        $firstLength = min(self::CHUNK_BYTES, $this->response->length);
        $first = $this->response->source->readRange($this->response->offset, $firstLength);
        if ($first === '' || strlen($first) > $firstLength) throw new RuntimeException('REMOTE_MEDIA_FIRST_READ_FAILED');
        $this->installCallbacks();
        $this->connection->pauseRecv();
        $headerBlock = $this->headerBlock();
        if ($this->connection->send($headerBlock, true) === false || $this->connection->send($first, true) === false) {
            $this->abort();
            throw new RuntimeException('REMOTE_MEDIA_HEADER_SEND_FAILED');
        }
        $this->sent = strlen($first);
        if ($this->response->deliveryTicketId !== null) {
            DlnaDeliveryObserver::start(
                $this->connection,
                $this->response->deliveryTicketId,
                $this->response->offset,
                $this->response->length,
                $this->response->totalSize,
                strlen($headerBlock),
            );
        }
        if ($this->sent >= $this->response->length) $this->complete();
        else $this->schedule();
    }

    /** 安排下一小段，避免一个回调循环连续阻塞多个远端请求。 */
    private function schedule(): void
    {
        if ($this->finished || $this->paused || $this->timerId !== null) return;
        $this->timerId = $this->events->delay(0.001, function (): void {
            $this->timerId = null;
            $this->pump();
        });
    }

    /** 获取并发送一个受限区间；任何失败都关闭已提交的响应，客户端可按 Range 重试。 */
    private function pump(): void
    {
        if ($this->finished || $this->paused) return;
        try {
            $remaining = $this->response->length - $this->sent;
            if ($remaining <= 0) {
                $this->complete();
                return;
            }
            $length = min(self::CHUNK_BYTES, $remaining);
            $bytes = $this->response->source->readRange($this->response->offset + $this->sent, $length);
            if ($bytes === '' || strlen($bytes) > $length || $this->connection->send($bytes, true) === false) {
                throw new RuntimeException('REMOTE_MEDIA_READ_FAILED');
            }
            $this->sent += strlen($bytes);
            if ($this->sent >= $this->response->length) $this->complete();
            else $this->schedule();
        } catch (Throwable) {
            $this->abort();
        }
    }

    /** 让 socket 背压和客户端断开共同拥有远端读取生命周期。 */
    private function installCallbacks(): void
    {
        $this->previousOnClose = $this->connection->onClose;
        $this->previousOnBufferFull = $this->connection->onBufferFull;
        $this->previousOnBufferDrain = $this->connection->onBufferDrain;
        $this->connection->onClose = function (TcpConnection $connection): void {
            $this->cleanup();
            if (is_callable($this->previousOnClose)) ($this->previousOnClose)($connection);
        };
        $this->connection->onBufferFull = function (TcpConnection $connection): void {
            $this->paused = true;
            if (is_callable($this->previousOnBufferFull)) ($this->previousOnBufferFull)($connection);
        };
        $this->connection->onBufferDrain = function (TcpConnection $connection): void {
            $this->paused = false;
            if (is_callable($this->previousOnBufferDrain)) ($this->previousOnBufferDrain)($connection);
            $this->schedule();
        };
    }

    /** 正常发送精确 Content-Length 后关闭连接；不写 chunk 终止符。 */
    private function complete(): void
    {
        if ($this->finished) return;
        $this->cleanup();
        $this->connection->close('', true);
    }

    /** 异常或断开路径幂等取消调度并关闭连接。 */
    private function abort(): void
    {
        if ($this->finished) return;
        $this->cleanup();
        $this->connection->close('', true);
    }

    private function cleanup(): void
    {
        if ($this->finished) return;
        $this->finished = true;
        if ($this->timerId !== null) {
            $this->events->offDelay($this->timerId);
            $this->timerId = null;
        }
        $this->connection->onClose = $this->previousOnClose;
        $this->connection->onBufferFull = $this->previousOnBufferFull;
        $this->connection->onBufferDrain = $this->previousOnBufferDrain;
    }

    /**
     * 序列化远端媒体响应头，避免框架把 marker 的空 body 再解释为 `Content-Length: 0`。
     *
     * Controller 已经给出经过范围校验的唯一精确长度；这里不能借用普通 Response 字符串序列化，因为
     * runner 会在头块之后自行发送正文，而普通响应只看当前空 body，会追加第二个冲突长度。部分浏览器会
     * 宽松接受重复头，但 DLNA Renderer 通常会在首段缓存后终止。状态只允许构造器已经复验的 200/206，
     * 所有头名和值仍防御性移除 CR/LF；Connection 由 runner 固定为 close，调用方不能覆盖连接生命周期。
     */
    private function headerBlock(): string
    {
        $reason = match ($this->response->statusCode) {
            200 => 'OK',
            206 => 'Partial Content',
            default => throw new RuntimeException('REMOTE_MEDIA_RESPONSE_INVALID'),
        };
        $block = "HTTP/1.1 {$this->response->statusCode} {$reason}\r\n";
        foreach ($this->response->streamHeaders as $name => $value) {
            if (strcasecmp($name, 'Connection') === 0) continue;
            $block .= str_replace(["\r", "\n"], '', $name) . ': '
                . str_replace(["\r", "\n"], '', $value) . "\r\n";
        }
        return $block . "Connection: close\r\n\r\n";
    }
}
