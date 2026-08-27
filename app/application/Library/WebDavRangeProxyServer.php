<?php

declare(strict_types=1);

namespace app\application\Library;

use Throwable;

/**
 * 在独立短命进程中把 FFprobe/FFmpeg 的单区间请求转发给一个固定网络库对象。
 *
 * 服务器只绑定 IPv4 回环地址，只接受一次性令牌路径、GET 和单个 `bytes=start-end` 区间；没有 Range
 * 时收敛为 `bytes=0-`。扫描模式把下游也限制为一个 1 MiB 区间，促使 FFprobe 按需 seek；转码模式把
 * 一个 FFmpeg 开放区间保持为连续响应，内部再拆成最多 1 MiB 的上游请求。每段仍由具体远端客户端复验
 * ETag、总大小和 Content-Range。最多处理有界数量的串行连接，父进程结束探测或转码后会主动终止；
 * 任何客户端、协议或上游错误只返回通用状态，不输出 URL、路径、用户名、凭据或远端正文。
 */
final readonly class WebDavRangeProxyServer
{
    private const MAX_HEADER_BYTES = 16_384;
    private const MAX_REQUESTS = 32_768;
    private const MAX_RANGE_BYTES = 1_048_576;

    public function __construct(
        private RemoteLibraryClient $client,
        private WebDavObject $object,
        private string $token,
        private bool $continuousResponse = false,
    ) {
    }

    /**
     * 绑定随机回环端口并进入串行服务循环。
     *
     * `$ready` 在 socket 完成绑定后仅接收端口号；连接空闲 300 秒会自行退出，避免父进程异常死亡后留下
     * 长驻秘密进程。正文采用固定 64 KiB 背压转发，媒体子进程提前断开时立即停止读取上游流。
     */
    public function serve(callable $ready): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!is_resource($server)) throw new RemoteLibraryUnavailable('REMOTE_RANGE_PROXY_BIND_FAILED', '按需探测代理无法启动。');
        try {
            $name = stream_socket_get_name($server, false);
            $port = is_string($name) && preg_match('/:(\d+)$/', $name, $match) === 1 ? (int) $match[1] : 0;
            if ($port < 1024 || $port > 65_535) throw new RemoteLibraryUnavailable('REMOTE_RANGE_PROXY_BIND_FAILED', '按需探测代理无法启动。');
            $ready($port);
            for ($handled = 0; $handled < self::MAX_REQUESTS; ++$handled) {
                $connection = @stream_socket_accept($server, 300);
                if (!is_resource($connection)) break;
                try {
                    stream_set_timeout($connection, 20);
                    $this->handle($connection);
                } catch (Throwable) {
                    $this->writeStatus($connection, 502, 'Bad Gateway');
                } finally {
                    fclose($connection);
                }
            }
        } finally {
            fclose($server);
        }
    }

    /**
     * 解析一个 FFprobe/FFmpeg 请求，并把目标区间连续转发到同一响应。
     *
     * 首个上游区间验证成功后才提交 206，因而首段失败仍可返回 502。提交后发生版本漂移、短读或网络失败
     * 时只能关闭连接，不能在音频正文尾部拼接第二个 HTTP 状态。下游声明目标区间的精确长度，内部逐段读取
     * 不改变 Content-Range 语义；这保证顺序解码不会在首个 1 MiB 被误判为正常 EOF。
     *
     * @param resource $connection 仅由当前串行代理进程拥有的回环连接。
     */
    private function handle($connection): void
    {
        $requestLine = fgets($connection, 4096);
        if (!is_string($requestLine)
            || preg_match('#^GET /([a-f0-9]{64}) HTTP/1\.[01]\r?\n$#', $requestLine, $match) !== 1
            || !hash_equals($this->token, $match[1])) {
            $this->writeStatus($connection, 404, 'Not Found');
            return;
        }
        $headers = [];
        $bytes = strlen($requestLine);
        while (($line = fgets($connection, 4096)) !== false) {
            $bytes += strlen($line);
            if ($bytes > self::MAX_HEADER_BYTES) {
                $this->writeStatus($connection, 431, 'Request Header Fields Too Large');
                return;
            }
            if ($line === "\r\n" || $line === "\n") break;
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*?)\r?\n$/', $line, $header) !== 1) {
                $this->writeStatus($connection, 400, 'Bad Request');
                return;
            }
            $key = strtolower($header[1]);
            if (isset($headers[$key])) {
                $this->writeStatus($connection, 400, 'Bad Request');
                return;
            }
            $headers[$key] = trim($header[2]);
        }
        $range = $headers['range'] ?? 'bytes=0-';
        if (preg_match('/^bytes=(\d+)-(\d*)$/', $range, $rangeMatch) !== 1) {
            $this->writeStatus($connection, 416, 'Range Not Satisfiable');
            return;
        }
        $start = (int) $rangeMatch[1];
        $requestedEnd = $rangeMatch[2] === '' ? $this->object->size - 1 : (int) $rangeMatch[2];
        if ($start >= $this->object->size || $requestedEnd < $start) {
            $this->writeStatus($connection, 416, 'Range Not Satisfiable');
            return;
        }
        $responseEnd = min($requestedEnd, $this->object->size - 1);
        if (!$this->continuousResponse) {
            $responseEnd = min($responseEnd, $start + self::MAX_RANGE_BYTES - 1);
        }
        $firstChunkEnd = min($responseEnd, $start + self::MAX_RANGE_BYTES - 1);
        try {
            $response = $this->client->openRange($this->object, $start, $firstChunkEnd);
        } catch (Throwable) {
            $this->writeStatus($connection, 502, 'Bad Gateway');
            return;
        }
        $responseLength = $responseEnd - $start + 1;
        $header = "HTTP/1.1 206 Partial Content\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . $responseLength . "\r\n"
            . 'Content-Range: bytes ' . $start . '-' . $responseEnd . '/' . $this->object->size . "\r\n"
            . "Accept-Ranges: bytes\r\nConnection: close\r\n\r\n";
        if (!$this->writeAll($connection, $header)) {
            $response->body->close();
            return;
        }

        $cursor = $start;
        while ($cursor <= $responseEnd) {
            try {
                $forwarded = $this->forwardRangeBody($connection, $response);
            } catch (Throwable) {
                $response->body->close();
                return;
            }
            $response->body->close();
            if ($forwarded !== $response->length()) return;
            $cursor += $forwarded;
            if ($cursor > $responseEnd) return;

            $chunkEnd = min($responseEnd, $cursor + self::MAX_RANGE_BYTES - 1);
            try {
                $response = $this->client->openRange($this->object, $cursor, $chunkEnd);
            } catch (Throwable) {
                return;
            }
        }
    }

    /**
     * 把一个已经复验的上游区间完整写入下游，并返回实际字节数。
     *
     * 返回值小于声明长度表示远端短读或媒体子进程已断开；调用方必须终止整个下游响应。方法不重试当前
     * 区间，避免在已发送部分正文后产生重复字节；重试由 FFmpeg 重新发起带 Range 的新连接完成。
     *
     * @param resource $connection
     */
    private function forwardRangeBody($connection, WebDavRangeResponse $response): int
    {
        $forwarded = 0;
        $expected = $response->length();
        while (!$response->body->eof() && $forwarded < $expected) {
            $chunk = $response->body->read(min(65_536, $expected - $forwarded));
            if ($chunk === '' || !$this->writeAll($connection, $chunk)) break;
            $forwarded += strlen($chunk);
        }
        return $forwarded;
    }

    /** @param resource $connection */
    private function writeStatus($connection, int $status, string $reason): void
    {
        $this->writeAll($connection, "HTTP/1.1 {$status} {$reason}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    /** @param resource $connection */
    private function writeAll($connection, string $bytes): bool
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($connection, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) return false;
            $offset += $written;
        }
        return true;
    }
}
