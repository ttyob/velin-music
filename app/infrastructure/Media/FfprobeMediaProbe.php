<?php

declare(strict_types=1);

namespace app\infrastructure\Media;

use app\application\Media\MediaMetadata;
use app\application\Media\MediaProbe;
use app\application\Media\MediaProbeFailed;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 在扫描 Worker 或播放缺失事实补全中使用固定参数执行 FFprobe。
 *
 * 可执行文件必须是绝对可执行路径，Symfony Process 使用参数数组且不经过 shell。普通入口只接受本地
 * 真实文件；WebDAV 入口只接受带随机令牌的 `127.0.0.1` 回环 URL，绝不把远端地址或 Authorization
 * 放入进程参数。每次调用限制时间和输出大小，FFprobe 只读且不会调用 FFmpeg 或写回标签。
 */
final class FfprobeMediaProbe implements MediaProbe
{
    private const DEFAULT_TIMEOUT_SECONDS = 15.0;
    private const MAX_OUTPUT_BYTES = 2_097_152;

    public function __construct(
        private readonly ?string $binaryPath = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly FfprobeJsonParser $parser = new FfprobeJsonParser(),
    ) {
    }

    /** 执行一次本地文件探测；调用方仍需在探测后复验文件属于当前音乐库。 */
    public function probe(string $absolutePath, string $fallbackTitle): MediaMetadata
    {
        if (!str_starts_with($absolutePath, '/') || !is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new MediaProbeFailed('MEDIA_FILE_UNREADABLE', '音频文件不可读取。');
        }
        return $this->run($absolutePath, $fallbackTitle);
    }

    /**
     * 探测 WebDavRangeProxySession 生成的一次性回环 URL。
     *
     * URL 必须严格为 IPv4 回环、非特权端口和 256 位令牌路径，禁止用户信息、查询和片段。此入口不接受
     * 任意 HTTP 地址；代理负责对象身份、Range 和凭据。失败直接交给上层降级或冷却，禁止完整下载回退。
     */
    public function probeLocalRangeUrl(string $url, string $fallbackTitle): MediaMetadata
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http' || ($parts['host'] ?? null) !== '127.0.0.1'
            || !is_int($parts['port'] ?? null) || $parts['port'] < 1024 || $parts['port'] > 65_535
            || preg_match('#^/[a-f0-9]{64}$#', (string) ($parts['path'] ?? '')) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new MediaProbeFailed('WEBDAV_RANGE_PROXY_URL_INVALID', 'WebDAV 按需探测地址无效。');
        }
        return $this->run($url, $fallbackTitle);
    }

    /** 执行共享的有界子进程并只解析 stdout；stderr、输入定位符和第三方正文不会进入异常。 */
    private function run(string $input, string $fallbackTitle): MediaMetadata
    {
        $binary = $this->binaryPath ?? (getenv('VELIN_FFPROBE_PATH') ?: base_path('bin/ffprobe'));
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)) {
            throw new MediaProbeFailed('FFPROBE_UNAVAILABLE', '媒体探测器未正确配置。');
        }

        $process = new Process([
            $binary,
            '-v', 'error',
            '-show_entries',
            'format=format_name,duration,bit_rate:format_tags:stream=index,codec_type,codec_name,sample_rate,channels,bits_per_raw_sample,bits_per_sample,bit_rate:stream_tags',
            '-of', 'json',
            $input,
        ]);
        if (str_starts_with($input, 'http://127.0.0.1:')) {
            // 回环代理绝不能被宿主 http_proxy 转发；false 让 Symfony 从子进程环境中移除对应变量。
            $process->setEnv([
                'http_proxy' => false, 'https_proxy' => false, 'all_proxy' => false,
                'HTTP_PROXY' => false, 'HTTPS_PROXY' => false, 'ALL_PROXY' => false,
                'no_proxy' => '127.0.0.1', 'NO_PROXY' => '127.0.0.1',
            ]);
        }
        $timeout = $this->timeoutSeconds
            ?? (is_numeric(getenv('VELIN_FFPROBE_TIMEOUT')) ? (float) getenv('VELIN_FFPROBE_TIMEOUT') : self::DEFAULT_TIMEOUT_SECONDS);
        $process->setTimeout(max(1.0, min(120.0, $timeout)));
        $stdout = '';
        $outputBytes = 0;
        $oversized = false;

        try {
            $exitCode = $process->run(function (string $type, string $data) use (&$outputBytes, &$oversized, &$stdout, $process): void {
                $outputBytes += strlen($data);
                if ($outputBytes > self::MAX_OUTPUT_BYTES) {
                    $oversized = true;
                    $process->stop(0.1);
                    return;
                }
                if ($type === Process::OUT) {
                    $stdout .= $data;
                }
            });
        } catch (ProcessTimedOutException) {
            throw new MediaProbeFailed('FFPROBE_TIMEOUT', '媒体探测超时。');
        } catch (Throwable) {
            throw new MediaProbeFailed('FFPROBE_EXECUTION_FAILED', '媒体探测器执行失败。');
        }
        if ($oversized) {
            throw new MediaProbeFailed('FFPROBE_OUTPUT_TOO_LARGE', '媒体探测结果超过安全限制。');
        }
        if ($exitCode !== 0) {
            throw new MediaProbeFailed('FFPROBE_REJECTED_FILE', '媒体探测器无法解析该音频文件。');
        }

        return $this->parser->parse($stdout, $fallbackTitle);
    }
}
