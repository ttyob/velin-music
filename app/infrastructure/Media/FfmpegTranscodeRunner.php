<?php

declare(strict_types=1);

namespace app\infrastructure\Media;

use app\application\Storage\StorageWriteGuard;
use app\application\Transcode\TranscodePlan;
use app\infrastructure\Dlna\DlnaDeliveryObserver;
use RuntimeException;
use support\Log;
use support\Response;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * 在不阻塞 Webman 事件循环的前提下监督一个 FFmpeg 子进程。
 *
 * stdout/stderr 以非阻塞流注册到 Workerman 事件循环，客户端背压会暂停 stdout watcher，避免 PHP 内存无界
 * 增长。超时、输出上限、进程失败或客户端断开都会终止子进程、关闭管道和输入租约、删除本次私有 spool，
 * 并释放并发槽。精确长度接收器请求可按内部摘要原子发布、复用有界派生缓存；缓存命中仍需上层先完成本次
 * 授权和媒体身份复验。命令和 stderr 可能包含物理路径或回环秘密，任何退出路径都不得记录它们。
 */
final class FfmpegTranscodeRunner
{
    private const DLNA_CACHE_TTL_SECONDS = 604_800;
    private const DLNA_CACHE_MAX_FILES = 512;
    private const DLNA_CACHE_MAX_BYTES = 2_147_483_648;

    private EventInterface $events;
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** @var resource|null */
    private mixed $spool = null;

    private ?string $spoolPath = null;
    private ?int $timerId = null;
    private float $startedAt = 0.0;
    private int $outputBytes = 0;
    private bool $stdoutClosed = false;
    private bool $paused = false;
    private bool $finished = false;
    private mixed $previousOnClose = null;
    private mixed $previousOnBufferFull = null;
    private mixed $previousOnBufferDrain = null;
    private string $runtimeRoot;

    public function __construct(
        private readonly TcpConnection $connection,
        private readonly TranscodePlan $plan,
        private readonly StorageWriteGuard $storageGuard = new StorageWriteGuard(),
        private readonly ?string $deliveryTicketId = null,
        ?string $runtimeRoot = null,
    ) {
        $this->events = Worker::getEventLoop();
        $runtime = $runtimeRoot ?? (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        // Docker 固定使用 `/app/runtime -> /data/runtime`。先把已存在的运行根规范化，保证临时文件创建时
        // 的目录字符串与发布阶段 realpath 使用同一身份；不存在或无法解析时保留原值，由 open/cache
        // 边界失败关闭。这里只解析部署配置，不接受请求路径，也不会创建或跟随子目录链接。
        $resolvedRuntime = realpath($runtime);
        $this->runtimeRoot = rtrim(is_string($resolvedRuntime) ? $resolvedRuntime : $runtime, DIRECTORY_SEPARATOR);
    }

    /**
     * 启动白名单命令并接管进程、输入、缓存、socket 与并发租约的完整生命周期。
     *
     * 前置条件：调用方已完成实时授权、媒体身份复验并取得转码并发槽。带稳定缓存键时先验证并打开完整缓存，
     * 命中后关闭未使用的本地/WebDAV 输入并直接投递或返回预热 204；未命中才创建私有 spool 和 FFmpeg。
     * 普通流在进程成功启动后提交 chunked Header，精确长度模式等完整 spool 后才提交 Header，因此启动失败
     * 仍可返回 503。连接中断会取消未完成的派生输出，已经原子发布的共享缓存不回滚。
     *
     * @throws RuntimeException 回调接管前的缓存、spool 或进程初始化失败。
     */
    public function start(): void
    {
        if ($this->plan->spoolBeforeSend) {
            if ($this->openCachedSpool()) {
                $this->plan->input->close();
                $this->plan->lease->release();
                $this->installConnectionCallbacks();
                $this->connection->pauseRecv();
                if ($this->plan->cacheOnly) $this->completeCacheOnly(false);
                else $this->sendCompletedSpool(false);
                return;
            }
            $this->openSpool();
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $this->process = @proc_open(
            $this->plan->command,
            $descriptors,
            $this->pipes,
            base_path(),
            ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
            ['bypass_shell' => true],
        );
        if (!is_resource($this->process) || !isset($this->pipes[0], $this->pipes[1], $this->pipes[2])) {
            $this->cleanup(false);
            throw new RuntimeException('TRANSCODE_PROCESS_START_FAILED');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->startedAt = microtime(true);
        $this->installConnectionCallbacks();
        $this->connection->pauseRecv();
        if (!$this->plan->spoolBeforeSend) {
            $this->sendStreamingHeaders();
        }
        $this->events->onReadable($this->pipes[1], fn () => $this->readStdout());
        $this->events->onReadable($this->pipes[2], fn () => $this->drainStderr());
        $this->timerId = $this->events->repeat(0.2, fn () => $this->poll());
    }

    /**
     * 在固定 runtime 子目录创建独占 0600 spool。
     *
     * 普通精确长度请求仍使用 transcode-spool 并在响应前删除路径；带内部缓存键的接收器请求写入独立缓存
     * 目录，完成前文件名含随机片段且不可复用。两种模式都先执行容量与挂载身份准入，并持续持有文件锁。
     */
    private function openSpool(): void
    {
        $cacheKey = $this->validCacheKey();
        $directory = $this->runtimeRoot . DIRECTORY_SEPARATOR
            . ($cacheKey === null ? 'transcode-spool' : 'dlna-transcode-cache');
        // 精确长度模式会先落完整临时文件，必须在 mkdir/fopen 前验证预计扩张与挂载身份。
        $this->storageGuard->assertTranscodeCacheAllowed($directory, $this->plan->maxOutputBytes);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('TRANSCODE_SPOOL_DIRECTORY_CREATE_FAILED');
        }
        if (is_link($directory) || !is_writable($directory)) {
            throw new RuntimeException('TRANSCODE_SPOOL_DIRECTORY_UNSAFE');
        }
        @chmod($directory, 0700);
        $prefix = $cacheKey === null ? '' : $cacheKey . '.';
        $this->spoolPath = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.part';
        $this->spool = @fopen($this->spoolPath, 'x+b');
        // 独占锁贯穿写入与发送生命周期；后台清理只删除能取得同一非阻塞锁的陈旧孤儿文件。
        if (!is_resource($this->spool) || !@flock($this->spool, LOCK_EX | LOCK_NB)
            || !@chmod($this->spoolPath, 0600)) {
            $this->cleanup(false);
            throw new RuntimeException('TRANSCODE_SPOOL_OPEN_FAILED');
        }
    }

    /**
     * 尝试打开已经完整发布的接收器转码缓存。
     *
     * 只有计划携带严格摘要键、目标是同一固定真实目录、对象为非链接普通文件且大小位于本次输出上限内才
     * 命中。打开后立即以 fstat 再验证并持有文件描述符，后续并发淘汰即使 unlink 目录项也不会截断当前
     * 响应；校验失败按缓存未命中处理，不删除未知对象，也不绕过本次媒体授权和转码准入。
     */
    private function openCachedSpool(): bool
    {
        $cacheKey = $this->validCacheKey();
        if ($cacheKey === null) return false;
        $directory = $this->cacheDirectory(false);
        if ($directory === null) return false;
        $path = $directory . DIRECTORY_SEPARATOR . $cacheKey . '.audio';
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path)
            || (int) ($stat['size'] ?? 0) <= 0 || (int) $stat['size'] > $this->plan->maxOutputBytes) return false;
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? @fstat($handle) : false;
        if (!is_resource($handle) || !is_array($opened) || (int) $opened['size'] !== (int) $stat['size']
            || (string) $opened['dev'] !== (string) $stat['dev'] || (string) $opened['ino'] !== (string) $stat['ino']) {
            if (is_resource($handle)) fclose($handle);
            return false;
        }
        $this->spool = $handle;
        $this->outputBytes = (int) $opened['size'];
        @touch($path);
        return true;
    }

    /** Chains socket callbacks so disconnect and backpressure control child ownership. */
    private function installConnectionCallbacks(): void
    {
        $this->previousOnClose = $this->connection->onClose;
        $this->previousOnBufferFull = $this->connection->onBufferFull;
        $this->previousOnBufferDrain = $this->connection->onBufferDrain;
        $this->connection->onClose = function (TcpConnection $connection): void {
            $this->abort('client_closed', false);
            if (is_callable($this->previousOnClose)) {
                ($this->previousOnClose)($connection);
            }
        };
        $this->connection->onBufferFull = function (TcpConnection $connection): void {
            $this->paused = true;
            if (!$this->stdoutClosed && isset($this->pipes[1]) && is_resource($this->pipes[1])) {
                $this->events->offReadable($this->pipes[1]);
            }
            if (is_callable($this->previousOnBufferFull)) {
                ($this->previousOnBufferFull)($connection);
            }
        };
        $this->connection->onBufferDrain = function (TcpConnection $connection): void {
            $this->paused = false;
            if (!$this->stdoutClosed && isset($this->pipes[1]) && is_resource($this->pipes[1])) {
                $this->events->onReadable($this->pipes[1], fn () => $this->readStdout());
                $this->readStdout();
            }
            if (is_callable($this->previousOnBufferDrain)) {
                ($this->previousOnBufferDrain)($connection);
            }
        };
    }

    /** Reads bounded stdout chunks and either spools or frames them for immediate delivery. */
    private function readStdout(): void
    {
        if ($this->finished || $this->paused || !isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
            return;
        }
        while (!$this->paused && ($chunk = @fread($this->pipes[1], 65_536)) !== false && $chunk !== '') {
            $this->outputBytes += strlen($chunk);
            if ($this->outputBytes > $this->plan->maxOutputBytes) {
                $this->abort('output_limit', true);
                return;
            }
            if (is_resource($this->spool)) {
                $written = @fwrite($this->spool, $chunk);
                if ($written !== strlen($chunk)) {
                    $this->abort('spool_write', true);
                    return;
                }
            } else {
                $frame = dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
                if ($this->connection->send($frame, true) === false) {
                    $this->abort('client_backpressure_failure', false);
                    return;
                }
            }
        }
        if (feof($this->pipes[1])) {
            $this->events->offReadable($this->pipes[1]);
            fclose($this->pipes[1]);
            unset($this->pipes[1]);
            $this->stdoutClosed = true;
            $this->finishIfExited();
        }
    }

    /** Drains but deliberately does not retain or log FFmpeg stderr, which can contain source paths. */
    private function drainStderr(): void
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return;
        }
        while (($chunk = @fread($this->pipes[2], 65_536)) !== false && $chunk !== '') {
            // Discard after draining: stable internal reason codes provide diagnostics without paths.
        }
        if (feof($this->pipes[2])) {
            $this->events->offReadable($this->pipes[2]);
            fclose($this->pipes[2]);
            unset($this->pipes[2]);
        }
    }

    /** Checks timeout and child status on a bounded timer; no busy polling occurs. */
    private function poll(): void
    {
        if ($this->finished) {
            return;
        }
        if (microtime(true) - $this->startedAt > $this->plan->timeoutSeconds) {
            $this->abort('timeout', true);
            return;
        }
        $this->finishIfExited();
    }

    /** Finalizes only after the child has exited and all stdout bytes have reached PHP. */
    private function finishIfExited(): void
    {
        if ($this->finished || !is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if (($status['running'] ?? false) || !$this->stdoutClosed) {
            return;
        }
        $exitCode = (int) ($status['exitcode'] ?? -1);
        if ($exitCode !== 0 || $this->outputBytes <= 0) {
            $this->abort('process_failed', true);
            return;
        }

        $this->stopWatchers();
        @proc_close($this->process);
        $this->process = null;
        // FFmpeg 已退出，不再需要本地定位符或一次性 WebDAV 回环代理；发送已落地 spool 不依赖输入源。
        $this->plan->input->close();
        $this->plan->lease->release();
        if (is_resource($this->spool)) {
            if ($this->plan->cacheOnly) $this->completeCacheOnly(true);
            else $this->sendCompletedSpool(true);
            return;
        }
        $this->finished = true;
        $this->restoreConnectionCallbacks();
        $this->connection->close("0\r\n\r\n", true);
    }

    /**
     * 发布可复用缓存（如启用）、发送精确长度响应头，并按 socket 背压排空已打开文件。
     *
     * 新缓存使用 link 作为同文件系统原子 no-replace 发布；并发赢家已存在时，本次临时 inode 仍可完成当前
     * 响应，随后只删除自己的临时目录项。发布失败且没有有效赢家时返回 503，不暴露部分文件。缓存命中
     * 调用传入 false，绝不删除持久目录项。投递期间的文件描述符独立于目录项，淘汰不会破坏当前响应。
     */
    private function sendCompletedSpool(bool $publish): void
    {
        if (!is_resource($this->spool) || ($publish && $this->spoolPath === null)) {
            $this->abort('spool_missing', true);
            return;
        }
        fflush($this->spool);
        rewind($this->spool);
        if ($publish && $this->spoolPath !== null) {
            $cacheKey = $this->validCacheKey();
            if ($cacheKey !== null && !$this->publishCache($cacheKey, $this->spoolPath)) {
                $this->abort('cache_publish', true);
                return;
            }
            if (is_file($this->spoolPath)) @unlink($this->spoolPath);
            $this->spoolPath = null;
        }
        $this->sendFixedLengthHeaders($this->outputBytes);
        $this->paused = false;
        $this->connection->onBufferFull = function (): void {
            $this->paused = true;
        };
        $this->connection->onBufferDrain = function (): void {
            $this->paused = false;
            $this->pumpSpool();
        };
        $this->pumpSpool();
    }

    /**
     * 完成浏览器发起的 DLNA 预热，但不把整首派生音频回传给浏览器。
     *
     * 缓存命中直接关闭已验证文件；未命中沿用与实际音响响应相同的原子发布和淘汰边界。只有发布完成才
     * 返回 204，因此前端随后设置 Renderer URI 时可以立即获得精确 Content-Length。连接提前断开仍按
     * 普通失败路径终止 FFmpeg、删除临时文件并释放输入/并发租约，不留下“已预热”的错误信号。
     */
    private function completeCacheOnly(bool $publish): void
    {
        if (!is_resource($this->spool) || ($publish && $this->spoolPath === null)) {
            $this->abort('spool_missing', true);
            return;
        }
        if ($publish && $this->spoolPath !== null) {
            $cacheKey = $this->validCacheKey();
            if ($cacheKey === null || !$this->publishCache($cacheKey, $this->spoolPath)) {
                $this->abort('cache_publish', true);
                return;
            }
            if (is_file($this->spoolPath)) @unlink($this->spoolPath);
            $this->spoolPath = null;
        }
        fclose($this->spool);
        $this->spool = null;
        $this->finished = true;
        $this->restoreConnectionCallbacks();
        if ($this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            $requestId = str_replace(["\r", "\n"], '', $this->plan->requestId);
            $this->connection->close("HTTP/1.1 204 No Content\r\n"
                . "Cache-Control: no-store\r\nX-Request-ID: {$requestId}\r\nConnection: close\r\n\r\n", true);
        }
    }

    /** 返回经过严格格式校验的内部缓存键；任何外部请求参数都不能直接成为文件名。 */
    private function validCacheKey(): ?string
    {
        $key = $this->plan->persistentCacheKey;
        return is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? $key : null;
    }

    /** 解析兼容既有部署目录名的固定接收器缓存根；读取不创建，写入只创建应用拥有的一级子目录。 */
    private function cacheDirectory(bool $create): ?string
    {
        $directory = $this->runtimeRoot . DIRECTORY_SEPARATOR . 'dlna-transcode-cache';
        if ($create && !is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return null;
        if (!is_dir($directory) || is_link($directory) || !is_writable($directory)) return null;
        $resolved = realpath($directory);
        return is_string($resolved) && $resolved === $directory ? $resolved : null;
    }

    /**
     * 原子发布成功转码并执行有界惰性淘汰。
     *
     * target 只由 64 位摘要组成；link 不覆盖并发赢家。现有赢家必须仍是非链接、非空且未超过本计划上限，
     * 否则本次发布失败关闭，避免替换身份异常对象。淘汰只处理本目录内固定 `.audio` 派生文件，按七天闲置、
     * 512 项和 2 GiB 三个内置边界删除最旧目录项；源媒体、普通 spool、未知文件和子目录永不触碰。删除是
     * 可重建缓存的不可回滚副作用，已经打开的响应描述符在 Linux 上继续有效，下一次未命中会重新转码。
     */
    private function publishCache(string $cacheKey, string $temporary): bool
    {
        $directory = $this->cacheDirectory(true);
        if ($directory === null || dirname($temporary) !== $directory) return false;
        $target = $directory . DIRECTORY_SEPARATOR . $cacheKey . '.audio';
        if (!is_file($target) && !@link($temporary, $target)) {
            clearstatcache(true, $target);
        }
        $stat = @lstat($target);
        if (!is_array($stat) || is_link($target) || !is_file($target)
            || (int) ($stat['size'] ?? 0) <= 0 || (int) $stat['size'] > $this->plan->maxOutputBytes) return false;
        @chmod($target, 0640);
        $this->pruneCache($directory, $cacheKey);
        return true;
    }

    /** 只删除可重建且严格命名的陈旧缓存；当前刚发布键始终受保护。 */
    private function pruneCache(string $directory, string $protectedKey): void
    {
        $names = @scandir($directory);
        if (!is_array($names)) return;
        $entries = [];
        $now = time();
        foreach ($names as $name) {
            if (preg_match('/^([a-f0-9]{64})\.audio$/D', $name, $matches) !== 1) continue;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || !is_file($path)) continue;
            $entries[] = ['key' => $matches[1], 'path' => $path, 'size' => max(0, (int) $stat['size']),
                'mtime' => (int) $stat['mtime']];
        }
        usort($entries, static fn (array $left, array $right): int => $left['mtime'] <=> $right['mtime']);
        $bytes = array_sum(array_column($entries, 'size'));
        $files = count($entries);
        foreach ($entries as $entry) {
            $expired = $now - $entry['mtime'] > self::DLNA_CACHE_TTL_SECONDS;
            $overLimit = $files > self::DLNA_CACHE_MAX_FILES || $bytes > self::DLNA_CACHE_MAX_BYTES;
            if ((!$expired && !$overLimit) || $entry['key'] === $protectedKey) continue;
            if (@unlink($entry['path'])) {
                --$files;
                $bytes -= $entry['size'];
            }
        }
    }

    /** Queues private spool bytes in bounded pieces and closes only after the final piece. */
    private function pumpSpool(): void
    {
        if ($this->finished || $this->paused || !is_resource($this->spool)) {
            return;
        }
        while (!$this->paused) {
            $chunk = fread($this->spool, 1_048_576);
            if ($chunk === false) {
                $this->abort('spool_read', false);
                return;
            }
            if ($chunk === '') {
                if (!feof($this->spool)) {
                    return;
                }
                fclose($this->spool);
                $this->spool = null;
                $this->finished = true;
                $this->restoreConnectionCallbacks();
                $this->connection->close('', true);
                return;
            }
            if ($this->connection->send($chunk, true) === false) {
                $this->abort('client_backpressure_failure', false);
                return;
            }
        }
    }

    /** Terminates the child and returns a generic 503 only when response headers are still unsent. */
    private function abort(string $reason, bool $notifyClient): void
    {
        if ($this->finished) {
            return;
        }
        $headersUnsent = $this->plan->spoolBeforeSend && is_resource($this->spool);
        $this->finished = true;
        $this->cleanup(true);
        Log::warning('Supervised audio transcode terminated.', [
            'request_id' => $this->plan->requestId,
            'reason' => $reason,
        ]);
        if ($this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
            if ($notifyClient && $headersUnsent) {
                $this->connection->close(new Response(503, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                    'X-Request-ID' => $this->plan->requestId,
                    'Connection' => 'close',
                ], 'Transcoding unavailable.'));
            } else {
                // Headers may already describe audio; close without appending a misleading error body.
                $this->connection->close('', true);
            }
        }
    }

    /** Removes event ownership, stops FFmpeg, deletes spool state, and releases admission. */
    private function cleanup(bool $terminate): void
    {
        $this->stopWatchers();
        if ($terminate && is_resource($this->process)) {
            @proc_terminate($this->process, 15);
            @proc_terminate($this->process, 9);
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
        if (is_resource($this->spool)) {
            @fclose($this->spool);
        }
        $this->spool = null;
        if ($this->spoolPath !== null && is_file($this->spoolPath)) {
            @unlink($this->spoolPath);
        }
        $this->spoolPath = null;
        $this->plan->input->close();
        $this->plan->lease->release();
        $this->restoreConnectionCallbacks();
    }

    /** Removes only watchers owned by this session, tolerating already-closed pipes. */
    private function stopWatchers(): void
    {
        if ($this->timerId !== null) {
            $this->events->offRepeat($this->timerId);
            $this->timerId = null;
        }
        foreach ([1, 2] as $index) {
            if (isset($this->pipes[$index]) && is_resource($this->pipes[$index])) {
                $this->events->offReadable($this->pipes[$index]);
            }
        }
    }

    /** Restores callbacks only while the socket is still alive; closed sockets clear them internally. */
    private function restoreConnectionCallbacks(): void
    {
        if ($this->connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return;
        }
        $this->connection->onClose = $this->previousOnClose;
        $this->connection->onBufferFull = $this->previousOnBufferFull;
        $this->connection->onBufferDrain = $this->previousOnBufferDrain;
    }

    /** Commits a chunked response for immediate conversion output. */
    private function sendStreamingHeaders(): void
    {
        $headers = $this->baseHeaders();
        $headers['Transfer-Encoding'] = 'chunked';
        $this->connection->send($this->headerBlock($headers), true);
    }

    /** Commits exact Content-Length after FFmpeg has atomically completed its private spool. */
    private function sendFixedLengthHeaders(int $length): void
    {
        $headers = $this->baseHeaders();
        $headers['Content-Length'] = (string) $length;
        $headerBlock = $this->headerBlock($headers);
        if ($this->deliveryTicketId !== null) {
            DlnaDeliveryObserver::start(
                $this->connection,
                $this->deliveryTicketId,
                0,
                $length,
                $length,
                strlen($headerBlock),
            );
        }
        $this->connection->send($headerBlock, true);
    }

    /** @return array<string, string> Headers contain no source path or untrusted control bytes. */
    private function baseHeaders(): array
    {
        $headers = [
            'Content-Type' => $this->plan->contentType,
            // 响应类型由服务端布尔值决定，文件名仍经过固定回退与 RFC 5987 编码，不能注入头部。
            'Content-Disposition' => ($this->plan->attachment ? 'attachment' : 'inline')
                . "; filename=\"audio\"; filename*=UTF-8''"
                . rawurlencode($this->plan->downloadName),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-ID' => $this->plan->requestId,
            'Connection' => 'close',
        ];
        if ($this->plan->responseEtag !== null
            && preg_match('/^"[a-f0-9]{64}"$/D', $this->plan->responseEtag) === 1) {
            $headers['ETag'] = $this->plan->responseEtag;
        }
        return $headers;
    }

    /** Serializes only server-owned header names/values after stripping CR/LF defensively. */
    private function headerBlock(array $headers): string
    {
        $block = "HTTP/1.1 200 OK\r\n";
        foreach ($headers as $name => $value) {
            $block .= str_replace(["\r", "\n"], '', $name) . ': '
                . str_replace(["\r", "\n"], '', $value) . "\r\n";
        }

        return $block . "\r\n";
    }
}
