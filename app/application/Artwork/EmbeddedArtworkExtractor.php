<?php

declare(strict_types=1);

namespace app\application\Artwork;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 使用 FFprobe/FFmpeg 从音频容器提取内嵌专辑封面，并发布到私有摘要缓存。
 *
 * 两个二进制路径必须是服务端配置的绝对可执行文件，命令参数以数组传递且不经过 shell。探测 JSON、
 * 图片 stdout 和 stderr 都有独立上限，超时或超限会终止子进程。源音频只读，成功的唯一副作用是在
 * `VELIN_RUNTIME_PATH/embedded-artwork-cache` 创建 0700 目录及 0600 缓存文件；临时文件使用随机名，
 * 校验后原子发布，异常会清理临时文件。缓存本身不授予任何媒体权限。
 */
final class EmbeddedArtworkExtractor implements EmbeddedArtworkSource
{
    private const MAX_PROBE_BYTES = 524_288;
    private const MAX_IMAGE_BYTES = 20_971_520;
    private const MAX_ERROR_BYTES = 131_072;
    private const DEFAULT_TIMEOUT_SECONDS = 15.0;

    public function __construct(
        private readonly ?string $ffprobePath = null,
        private readonly ?string $ffmpegPath = null,
        private readonly ?string $runtimePath = null,
        private readonly ?float $timeoutSeconds = null,
        private readonly ArtworkImageInspector $inspector = new ArtworkImageInspector(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * 多个 attached picture 按全局流序号稳定排序，首个能保持原编码提取并通过图片检查的候选获选。
     * 当前只接受 FFmpeg 可无损复制的 MJPEG、PNG 和 WebP；GIF、SVG、未知视频流及普通视频均不会进入
     * 浏览器。候选存在但全部无效时保留 `hadCandidates=true`，且不会在数据库保存半成品。
     */
    public function discover(string $audioPath): array
    {
        try {
            $streams = $this->probeStreams($audioPath);
        } catch (EmbeddedArtworkExtractionFailed) {
            return ['match' => null, 'hadCandidates' => false];
        }
        $hadCandidates = $streams !== [];
        foreach ($streams as $stream) {
            if (!isset($stream['index'], $stream['codec'])) {
                continue;
            }
            try {
                $path = $this->extractAndPublish($audioPath, $stream['index'], $stream['codec']);
                $image = $this->inspector->inspect($path);
                if ($image !== null) {
                    return ['match' => ['streamIndex' => $stream['index'], 'image' => $image], 'hadCandidates' => true];
                }
            } catch (EmbeddedArtworkExtractionFailed) {
                continue;
            }
        }

        return ['match' => null, 'hadCandidates' => $hadCandidates];
    }

    /** {@inheritDoc} */
    public function materialize(
        string $audioPath,
        int $streamIndex,
        string $expectedMime,
        int $expectedSize,
        string $expectedSha256,
    ): string {
        if ($streamIndex < 0 || $streamIndex > 4096
            || !in_array($expectedMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $expectedSize <= 0 || $expectedSize > self::MAX_IMAGE_BYTES
            || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
        ) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_IDENTITY_INVALID');
        }
        $extension = $this->extensionForMime($expectedMime);
        $path = $this->cacheRoot() . DIRECTORY_SEPARATOR . $expectedSha256 . '.' . $extension;
        if ($this->matchesExpected($path, $expectedMime, $expectedSize, $expectedSha256)) {
            return $path;
        }

        $codec = $this->codecForMime($expectedMime);
        $generated = $this->extractAndPublish($audioPath, $streamIndex, $codec);
        if (!$this->matchesExpected($generated, $expectedMime, $expectedSize, $expectedSha256)) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CHANGED');
        }

        return $generated;
    }

    /**
     * 返回受支持的 attached picture 流；普通视频不会被误判为封面。
     *
     * @return list<array{index: int, codec: string}>
     */
    private function probeStreams(string $audioPath): array
    {
        $binary = $this->binary($this->ffprobePath, 'VELIN_FFPROBE_PATH', base_path('bin/ffprobe'), 'FFPROBE_UNAVAILABLE');
        $this->assertAudioPath($audioPath);
        $process = new Process([
            $binary,
            '-v', 'error',
            '-show_entries', 'stream=index,codec_type,codec_name:stream_disposition=attached_pic',
            '-of', 'json',
            $audioPath,
        ]);
        $stdout = $this->runBounded($process, self::MAX_PROBE_BYTES, 'FFPROBE');
        try {
            $payload = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_PROBE_INVALID');
        }
        $matches = [];
        foreach (is_array($payload['streams'] ?? null) ? $payload['streams'] : [] as $stream) {
            if (!is_array($stream)
                || ($stream['codec_type'] ?? null) !== 'video'
                || (int) ($stream['disposition']['attached_pic'] ?? 0) !== 1
            ) {
                continue;
            }
            $index = filter_var($stream['index'] ?? null, FILTER_VALIDATE_INT);
            $codec = is_string($stream['codec_name'] ?? null) ? strtolower($stream['codec_name']) : '';
            if ($index !== false && $index >= 0 && $index <= 4096 && in_array($codec, ['mjpeg', 'png', 'webp'], true)) {
                $matches[] = ['index' => $index, 'codec' => $codec];
            }
        }
        usort($matches, static fn (array $left, array $right): int => $left['index'] <=> $right['index']);

        return $matches;
    }

    /**
     * 以原编码复制一帧到有界内存，验证后再原子发布到摘要命名的私有缓存。
     *
     * FFmpeg 不获得输出目录选择权，stdout 超过 20 MiB 会立即停止。相同摘要的并发发布是幂等的；若
     * 目标已经存在且有效，随机临时文件会被清理并复用目标。缓存文件绝不位于音乐库根目录。
     */
    private function extractAndPublish(string $audioPath, int $streamIndex, string $codec): string
    {
        $binary = $this->binary($this->ffmpegPath, 'VELIN_FFMPEG_PATH', base_path('bin/ffmpeg'), 'FFMPEG_UNAVAILABLE');
        $this->assertAudioPath($audioPath);
        $extension = match ($codec) {
            'mjpeg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CODEC_UNSUPPORTED'),
        };
        $process = new Process([
            $binary,
            '-v', 'error',
            '-nostdin',
            '-i', $audioPath,
            '-map', '0:' . $streamIndex,
            '-frames:v', '1',
            '-c:v', 'copy',
            '-f', 'image2pipe',
            'pipe:1',
        ]);
        $bytes = $this->runBounded($process, self::MAX_IMAGE_BYTES, 'FFMPEG');
        if ($bytes === '') {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_EMPTY');
        }

        $root = $this->cacheRoot();
        $temporary = $root . DIRECTORY_SEPARATOR . '.extract-' . bin2hex(random_bytes(12)) . '.' . $extension;
        $written = @file_put_contents($temporary, $bytes, LOCK_EX);
        try {
            if ($written !== strlen($bytes) || !@chmod($temporary, 0600)) {
                throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CACHE_WRITE_FAILED');
            }
            $image = $this->inspector->inspect($temporary);
            if ($image === null || $this->extensionForMime($image['mime']) !== $extension) {
                throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_IMAGE_INVALID');
            }
            $target = $root . DIRECTORY_SEPARATOR . $image['sha256'] . '.' . $extension;
            if ($this->matchesExpected($target, $image['mime'], $image['size'], $image['sha256'])) {
                return $target;
            }
            if (!@rename($temporary, $target)) {
                throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CACHE_PUBLISH_FAILED');
            }
            $temporary = '';

            return $target;
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * 运行无 shell 子进程并分别限制 stdout 与 stderr，错误响应不包含子进程原文。
     *
     * 回调每次清空 Symfony Process 自带缓冲，避免在本类的有界副本之外再积累一份完整二进制输出。
     */
    private function runBounded(Process $process, int $maxStdoutBytes, string $prefix): string
    {
        $process->setTimeout($this->timeout());
        $stdout = '';
        $stdoutBytes = 0;
        $stderrBytes = 0;
        $oversized = false;
        try {
            $exitCode = $process->run(function (string $type, string $data) use (
                &$stdout,
                &$stdoutBytes,
                &$stderrBytes,
                &$oversized,
                $maxStdoutBytes,
                $process,
            ): void {
                if ($type === Process::OUT) {
                    $stdoutBytes += strlen($data);
                    if ($stdoutBytes <= $maxStdoutBytes) {
                        $stdout .= $data;
                    }
                } else {
                    $stderrBytes += strlen($data);
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                if ($stdoutBytes > $maxStdoutBytes || $stderrBytes > self::MAX_ERROR_BYTES) {
                    $oversized = true;
                    $process->stop(0.1);
                }
            });
        } catch (ProcessTimedOutException) {
            throw new EmbeddedArtworkExtractionFailed($prefix . '_TIMEOUT');
        } catch (Throwable) {
            throw new EmbeddedArtworkExtractionFailed($prefix . '_EXECUTION_FAILED');
        }
        if ($oversized) {
            throw new EmbeddedArtworkExtractionFailed($prefix . '_OUTPUT_TOO_LARGE');
        }
        if ($exitCode !== 0) {
            throw new EmbeddedArtworkExtractionFailed($prefix . '_REJECTED_FILE');
        }

        return $stdout;
    }

    /** 返回配置的绝对可执行文件，不接受请求路径或 PATH 搜索结果。 */
    private function binary(?string $configured, string $environment, string $fallback, string $errorCode): string
    {
        $binary = $configured ?? (getenv($environment) ?: $fallback);
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)) {
            throw new EmbeddedArtworkExtractionFailed($errorCode);
        }

        return $binary;
    }

    /** 音频必须是可读的绝对普通文件；目录边界和符号链接身份由上层再次校验。 */
    private function assertAudioPath(string $audioPath): void
    {
        if (!str_starts_with($audioPath, '/') || !is_file($audioPath) || !is_readable($audioPath)) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_SOURCE_UNREADABLE');
        }
    }

    /** 创建并验证固定私有缓存根，拒绝预先存在的符号链接。 */
    private function cacheRoot(): string
    {
        $runtime = $this->runtimePath ?? (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $root = rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'embedded-artwork-cache';
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CACHE_CREATE_FAILED');
        }
        if (is_link($root) || !is_writable($root)) {
            throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CACHE_UNSAFE');
        }
        @chmod($root, 0700);

        return $root;
    }

    /** 复验缓存图片签名、大小和摘要，缓存文件本身不可信。 */
    private function matchesExpected(string $path, string $mime, int $size, string $sha256): bool
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $image = $this->inspector->inspect($path);

        return $image !== null
            && $image['mime'] === $mime
            && $image['size'] === $size
            && hash_equals($sha256, $image['sha256']);
    }

    /** 把已允许的 MIME 映射到固定扩展名，禁止用外部字符串拼接文件名。 */
    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CODEC_UNSUPPORTED'),
        };
    }

    /** 把已允许的 MIME 映射回 FFmpeg 编解码器白名单。 */
    private function codecForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'mjpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new EmbeddedArtworkExtractionFailed('EMBEDDED_ARTWORK_CODEC_UNSUPPORTED'),
        };
    }

    /** 超时只允许 1-120 秒，环境配置异常时采用保守默认值。 */
    private function timeout(): float
    {
        $configured = $this->timeoutSeconds
            ?? (is_numeric(getenv('VELIN_FFPROBE_TIMEOUT')) ? (float) getenv('VELIN_FFPROBE_TIMEOUT') : self::DEFAULT_TIMEOUT_SECONDS);

        return max(1.0, min(120.0, $configured));
    }
}
