<?php

declare(strict_types=1);

namespace app\infrastructure\Scan;

use app\application\Storage\StorageLayout;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * 监督单个长驻文件监听 helper，并把 stdout 收窄为去路径化音乐库事件。
 *
 * 二进制和 allowedRoot 只来自部署配置，命令使用参数数组且不经过 shell；所有物理路径都在一次 stdin
 * JSON 快照中传递，不进入 argv。stderr 始终丢弃。stdout 只允许版本 1 JSONL 且累计未解析缓冲不超过
 * 64 KiB；未知字段、路径字段、超长输出、异常退出或固定 error 事件都会停止进程并失败关闭。进程重启
 * 与事件保留由上层 Worker 管理，本类不连接数据库也不创建扫描任务。
 */
final class LibraryWatchProcess
{
    private const MAX_BUFFER_BYTES = 65_536;

    private Process $process;
    private string $stdout = '';
    private bool $overflowed = false;
    private bool $closed = false;

    /**
     * @param list<array{key:string,root:string}> $targets 已由数据库查询得到的固定本地 watch 库。
     */
    public function __construct(
        array $targets,
        ?string $binaryPath = null,
        private readonly string $allowedRoot = StorageLayout::LIBRARY_ROOT,
        private readonly int $debounceMs = 2_000,
    ) {
        $path = $binaryPath ?? (string) (getenv('VELIN_LIBRARY_WATCH_HELPER_PATH')
            ?: base_path('bin/velin-library-watch-helper'));
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || !is_file($path) || !is_executable($path)) {
            throw new LibraryWatchUnavailable('WATCH_HELPER_UNAVAILABLE');
        }
        if ($targets === [] || count($targets) > 100 || $this->debounceMs < 250 || $this->debounceMs > 30_000
            || $this->allowedRoot === '' || $this->allowedRoot[0] !== DIRECTORY_SEPARATOR) {
            throw new LibraryWatchUnavailable('WATCH_CONFIGURATION_INVALID');
        }
        foreach ($targets as $target) {
            if (!is_array($target) || array_keys($target) !== ['key', 'root']
                || !is_string($target['key']) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $target['key']) !== 1
                || !is_string($target['root']) || $target['root'] === ''
                || $target['root'][0] !== DIRECTORY_SEPARATOR) {
                throw new LibraryWatchUnavailable('WATCH_CONFIGURATION_INVALID');
            }
        }
        try {
            $input = json_encode([
                'version' => 1,
                'allowedRoot' => $this->allowedRoot,
                'debounceMs' => $this->debounceMs,
                'watches' => $targets,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new LibraryWatchUnavailable('WATCH_CONFIGURATION_INVALID', $exception);
        }
        $this->process = new Process([$path]);
        $this->process->setInput($input);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);
        try {
            $this->process->start(function (string $type, string $bytes): void {
                if ($type !== Process::OUT || $bytes === '') {
                    return;
                }
                if (strlen($this->stdout) + strlen($bytes) > self::MAX_BUFFER_BYTES) {
                    $this->overflowed = true;
                    return;
                }
                $this->stdout .= $bytes;
            });
        } catch (\Throwable $exception) {
            throw new LibraryWatchUnavailable('WATCH_HELPER_START_FAILED', $exception);
        }
    }

    /**
     * 读取当前可用的完整事件行；部分行留到下一轮，绝不阻塞等待 helper。
     *
     * @return list<array{key:string,type:'changed'|'resync'}>
     */
    public function poll(): array
    {
        if ($this->closed) {
            throw new LibraryWatchUnavailable('WATCH_HELPER_STOPPED');
        }
        try {
            $running = $this->process->isRunning();
        } catch (\Throwable $exception) {
            $this->close();
            throw new LibraryWatchUnavailable('WATCH_HELPER_RUNTIME_FAILED', $exception);
        }
        if ($this->overflowed) {
            $this->close();
            throw new LibraryWatchUnavailable('WATCH_PROTOCOL_TOO_LARGE');
        }
        $events = [];
        while (($newline = strpos($this->stdout, "\n")) !== false) {
            $line = substr($this->stdout, 0, $newline);
            $this->stdout = substr($this->stdout, $newline + 1);
            if ($line === '' || strlen($line) > 4_096) {
                $this->close();
                throw new LibraryWatchUnavailable('WATCH_PROTOCOL_INVALID');
            }
            $event = $this->event($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if (!$running && $events === []) {
            $this->close();
            throw new LibraryWatchUnavailable('WATCH_HELPER_EXITED');
        }
        return $events;
    }

    /** 终止当前 helper；重复调用幂等，最多等待一秒后由 Symfony Process 强制结束。 */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            $this->process->stop(1.0, SIGTERM);
        } catch (\Throwable) {
            // 进程可能已经被 Workerman 或容器回收；关闭边界不再扩大错误。
        }
    }

    /** @return array{key:string,type:'changed'|'resync'}|null */
    private function event(string $line): ?array
    {
        try {
            $payload = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LibraryWatchUnavailable('WATCH_PROTOCOL_INVALID', $exception);
        }
        if (!is_array($payload) || array_is_list($payload) || ($payload['version'] ?? null) !== 1
            || !is_string($payload['type'] ?? null)
            || array_diff(array_keys($payload), ['version', 'type', 'key', 'reason', 'errorCode']) !== []) {
            throw new LibraryWatchUnavailable('WATCH_PROTOCOL_INVALID');
        }
        if ($payload['type'] === 'error') {
            $reason = is_string($payload['errorCode'] ?? null)
                && preg_match('/^WATCH_[A-Z0-9_]{2,60}$/', $payload['errorCode']) === 1
                ? $payload['errorCode'] : 'WATCH_HELPER_FAILED';
            throw new LibraryWatchUnavailable($reason);
        }
        if (!in_array($payload['type'], ['ready', 'changed', 'resync'], true)
            || !is_string($payload['key'] ?? null)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $payload['key']) !== 1
            || (isset($payload['reason']) && (!is_string($payload['reason'])
                || !in_array($payload['reason'], ['filesystem', 'overflow', 'root_identity'], true)))) {
            throw new LibraryWatchUnavailable('WATCH_PROTOCOL_INVALID');
        }
        if ($payload['type'] === 'ready') {
            return null;
        }
        return ['key' => $payload['key'], 'type' => $payload['type']];
    }
}
