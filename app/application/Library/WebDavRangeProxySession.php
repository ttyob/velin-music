<?php

declare(strict_types=1);

namespace app\application\Library;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * 管理一次媒体探测或转码专用的回环 HTTP Range 代理子进程。
 *
 * WebDAV 密码、OneDrive 或 Google Drive OAuth 秘密只通过子进程标准输入传递，不进入 argv、环境变量、临时文件或 URL。子进程仅绑定
 * `127.0.0.1`，路径还包含 256 位一次性令牌；父进程必须在探测完成、失败或超时后调用 close，析构
 * 只承担异常路径的补偿终止。该会话不允许提供给浏览器或其他 Worker；原始直放使用统一读取源逐段转发，
 * 不经过该子进程。
 */
final class WebDavRangeProxySession
{
    private function __construct(
        private readonly Process $process,
        public readonly string $url,
    ) {
    }

    /**
     * 启动一个只服务指定不可变对象的代理。
     *
     * 配置 JSON 在管道写完后即由 Symfony Process 关闭；启动输出只允许一行不含秘密的 READY 端口。
     * 三秒内无法绑定回环端口时终止子进程并返回脱敏错误。扫描调用方可回退完整临时下载；转码调用方
     * 必须直接失败，不能为了继续播放而隐式物化完整远端源。
     */
    public static function start(array $configuration): self
    {
        $sourceType = in_array(($configuration['sourceType'] ?? null), ['onedrive', 'google_drive'], true)
            ? (string) $configuration['sourceType'] : 'webdav';
        $helper = dirname(__DIR__, 3) . '/bin/webdav-range-proxy.php';
        if (!is_file($helper) || !is_readable($helper) || !str_starts_with(PHP_BINARY, '/')
            || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) {
            throw self::unavailable($sourceType);
        }
        $token = bin2hex(random_bytes(32));
        try {
            $input = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            throw self::unavailable($sourceType);
        }
        if (!is_string($input) || strlen($input) > 65_536) {
            throw self::unavailable($sourceType);
        }
        $process = new Process([PHP_BINARY, $helper, $token]);
        $process->setTimeout(null);
        $process->setInput($input);
        try {
            $process->start();
            $deadline = microtime(true) + 3.0;
            $output = '';
            do {
                $output .= $process->getIncrementalOutput();
                if (preg_match('/^READY ([1-9]\d{0,4})\R/', $output, $match) === 1) {
                    $port = (int) $match[1];
                    if ($port >= 1024 && $port <= 65_535) {
                        $input = str_repeat("\0", strlen($input));
                        return new self($process, 'http://127.0.0.1:' . $port . '/' . $token);
                    }
                }
                if (!$process->isRunning()) break;
                usleep(10_000);
            } while (microtime(true) < $deadline);
        } catch (Throwable) {
            // 统一在下方终止并转成不含子进程输出的稳定错误。
        }
        $input = str_repeat("\0", strlen($input));
        if ($process->isRunning()) $process->stop(0.2);
        throw self::unavailable($sourceType);
    }

    /** 按来源生成稳定且脱敏的代理错误，避免 OAuth 网络库任务被错误归类成 WebDAV。 */
    private static function unavailable(string $sourceType): RemoteLibraryUnavailable
    {
        return match ($sourceType) {
            'onedrive' => new OneDriveUnavailable('ONEDRIVE_RANGE_PROXY_UNAVAILABLE', 'OneDrive 按需探测代理不可用。'),
            'google_drive' => new GoogleDriveUnavailable('GOOGLE_DRIVE_RANGE_PROXY_UNAVAILABLE', 'Google Drive 按需探测代理不可用。'),
            default => new WebDavUnavailable('WEBDAV_RANGE_PROXY_UNAVAILABLE', 'WebDAV 按需探测代理不可用。'),
        };
    }

    /** 幂等终止代理；未完成的上游 Range 流随子进程退出关闭，不修改远端对象。 */
    public function close(): void
    {
        if ($this->process->isRunning()) $this->process->stop(0.2);
    }

    public function __destruct()
    {
        $this->close();
    }
}
