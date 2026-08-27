<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Media\PlayableMedia;
use app\application\System\SystemLimitSettingsService;
use app\application\Transcode\TranscodeAdmission;
use app\application\Transcode\TranscodePlan;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\TranscodeResponse;
use Throwable;

/**
 * 把 Subsonic 流参数协商为原始直放或受限 FFmpeg 执行计划。
 *
 * 转换目标只允许 MP3、AAC/ADTS 和 Opus/Ogg；原始或与源格式相同的请求保持直放，除非码率、正时间偏移
 * 或 `converted=true` 明确要求转换。所有参数在取得并发槽之前完成规范化与边界校验，不支持或互相矛盾
 * 的组合明确失败，不能静默返回错误格式的字节。
 */
final readonly class SubsonicTranscodeService
{
    public function __construct(
        private TranscodeAdmission $admission = new TranscodeAdmission(),
        private SystemLimitSettingsService $limits = new SystemLimitSettingsService(),
    ) {
    }

    /**
     * 直放时返回 null，需要转换时返回仅供内部 HTTP supervisor 消费的流式响应。
     *
     * 正时间偏移以秒为单位，并在打开输入后应用，以保持准确的输出时间语义。
     * `estimateContentLength=true` 会先写入私有临时 spool，使 HTTP Content-Length 精确；false 则立即
     * 分块发送。FFmpeg 仍由 HTTP supervisor 启动。WebDAV 输入协商会先启动一次性回环 Range 代理；
     * 若后续参数构造、并发准入或计划创建失败，catch 会关闭租约并终止代理，不留下完整源文件或长期秘密进程。
     * persistentCache 仅供已经重新鉴权的接收器投放 Controller 启用：键由媒体 ETag 与去地址化转码计划
     * 生成，本地路径或 WebDAV 一次性回环 URL 都不会进入键。缓存只保存可重建的目标编码，不保存远端
     * 原始音频，也不跨源文件版本或编码参数复用。lowLatency 选择编码器公开的速度优先复杂度，仍保持
     * 相同格式、码率与精确长度语义；普通 Web/Subsonic 请求不改变既有音质配置，也不写持久缓存。
     *
     * @param array<string, mixed> $parameters 已合并但仍需严格验证的 Subsonic 请求参数。
     * @throws SubsonicRequestInvalid 格式、布尔值、码率、偏移无效，或 raw 请求包含互斥转换参数。
     */
    public function negotiate(
        PlayableMedia $media,
        array $parameters,
        string $requestId,
        ?array $actor = null,
        bool $attachment = false,
        ?float $gainDb = null,
        bool $persistentCache = false,
        bool $lowLatency = false,
        bool $cacheOnly = false,
    ): ?TranscodeResponse {
        $sourceFormat = strtolower((string) pathinfo($media->downloadName, PATHINFO_EXTENSION));
        $requestedFormat = $this->format($parameters['format'] ?? null);
        $maxBitRate = array_key_exists('maxBitRate', $parameters)
            ? $this->integer($parameters['maxBitRate'], 0, 1000, 'Maximum bitrate')
            : 0;
        if ($maxBitRate > 0 && $maxBitRate < 16) {
            throw new SubsonicRequestInvalid('Maximum bitrate is below the supported codec floor.');
        }
        $offset = $this->offset($parameters);
        $estimate = array_key_exists('estimateContentLength', $parameters)
            ? $this->boolean($parameters['estimateContentLength'])
            : false;
        $converted = array_key_exists('converted', $parameters)
            ? $this->boolean($parameters['converted'])
            : false;

        // 多个主流客户端即使选择原始流也会附带全局码率上限。`raw` 明确拥有格式决定权，因此码率值
        // 只完成语法校验后忽略，不能把本可直放的请求误判成冲突；但时间偏移和 converted 会改变字节
        // 语义，仍必须拒绝，客户端应改用 HTTP Range 或明确的转码格式。
        if ($requestedFormat === 'raw') {
            if ($offset > 0 || $converted) {
                throw new SubsonicRequestInvalid('Raw format cannot be combined with conversion or time offset.');
            }
            return null;
        }

        $sourceRequest = $requestedFormat === null
            || $requestedFormat === $sourceFormat;
        $requiresConversion = !$sourceRequest || $maxBitRate > 0 || $offset > 0 || $converted;
        if (!$requiresConversion) {
            return null;
        }
        if ($media->durationMs > 0 && $offset * 1000 >= $media->durationMs) {
            throw new SubsonicRequestInvalid('Stream offset is outside the song duration.');
        }

        $target = $this->targetFormat($requestedFormat, $sourceFormat);
        [$codec, $muxer, $contentType, $extension, $defaultBitRate, $codecArguments] = $this->profile($target);
        $bitRate = $maxBitRate > 0 ? min($maxBitRate, $defaultBitRate) : $defaultBitRate;
        $limits = $this->limits->get();
        $maximumBitrate = (int) $limits['maxTranscodeBitrateKbps'];
        if ($maxBitRate > $maximumBitrate) {
            throw new UserRuntimeLimitExceeded('TRANSCODE_BITRATE_EXCEEDED', $maxBitRate, $maximumBitrate);
        }
        $bitRate = min($bitRate, $maximumBitrate);
        $executable = $this->executable();
        $input = $media->source->openTranscodeInput();
        try {
            $command = [
                $executable,
                '-nostdin',
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $input->locator,
            ];
            if ($offset > 0) {
                $command[] = '-ss';
                $command[] = (string) $offset;
            }
            array_push(
                $command,
                '-map',
                '0:a:0',
                '-vn',
                '-sn',
                '-dn',
                '-map_metadata',
                '-1',
                '-codec:a',
                $codec,
                '-b:a',
                $bitRate . 'k',
            );
            if ($gainDb !== null) {
                // 增益只接受服务端根据扫描标签算出的有限值，并在此再次限幅。构造固定 `volume` 过滤器，
                // 不允许请求参数成为任意 FFmpeg filter，从根源上避免参数注入和失控放大。
                if (!is_finite($gainDb)) throw new SubsonicRequestInvalid('ReplayGain adjustment is invalid.');
                $boundedGain = max(-30.0, min(30.0, $gainDb));
                $command[] = '-filter:a';
                $command[] = 'volume=' . number_format($boundedGain, 3, '.', '') . 'dB';
            }
            array_push($command, ...$codecArguments);
            if ($lowLatency) {
                array_push($command, ...match ($target) {
                    // LAME 的 compression_level 数值越高速度越快；320 kbps CBR 下采用 7 缩短整曲 spool 等待。
                    'mp3' => ['-compression_level', '7'],
                    'aac' => ['-aac_coder', 'fast'],
                    'opus' => ['-compression_level', '5'],
                });
            }
            array_push($command, '-f', $muxer, 'pipe:1');

            $remainingSeconds = $media->durationMs > 0
                ? max(1, (int) ceil(($media->durationMs - ($offset * 1000)) / 1000))
                : 0;
            $timeoutCeiling = max(60, min(86_400, (int) (getenv('VELIN_TRANSCODE_TIMEOUT_SECONDS') ?: 21_600)));
            $timeout = $remainingSeconds > 0
                ? min($timeoutCeiling, max(60, ($remainingSeconds * 2) + 60))
                : $timeoutCeiling;
            $maxOutputBytes = $remainingSeconds > 0
                ? (int) min(2_147_483_648, max(8_388_608, ceil($remainingSeconds * $bitRate * 125 * 1.5) + 1_048_576))
                : 536_870_912;
            $baseName = (string) pathinfo($media->downloadName, PATHINFO_FILENAME);
            $persistentCacheKey = $persistentCache && $estimate
                ? $this->persistentCacheKey($media, $command)
                : null;

            return new TranscodeResponse(new TranscodePlan(
                command: $command,
                contentType: $contentType,
                downloadName: ($baseName === '' ? 'audio' : $baseName) . '.' . $extension,
                requestId: $requestId,
                spoolBeforeSend: $estimate,
                timeoutSeconds: $timeout,
                maxOutputBytes: $maxOutputBytes,
                lease: $this->admission->acquire((int) $limits['maxConcurrentTranscodes']),
                input: $input,
                attachment: $attachment,
                persistentCacheKey: $persistentCacheKey,
                cacheOnly: $cacheOnly && $persistentCacheKey !== null,
                responseEtag: $persistentCacheKey === null ? null : '"' . $persistentCacheKey . '"',
            ));
        } catch (Throwable $throwable) {
            $input->close();
            throw $throwable;
        }
    }

    /**
     * 生成与媒体来源定位方式无关的接收器转码缓存键。
     *
     * 前置条件：command 由本服务固定构造并且只含一个 `-i` 输入；媒体 ETag 已由统一读取层绑定歌曲 ID、
     * 本地 inode/mtime 或 WebDAV ETag/大小/mtime，并在每次请求中重新验证。方法只在内存副本中把真实输入
     * 替换为固定标记，最终摘要仍覆盖 FFmpeg 路径、seek、codec、码率、滤镜、速度参数和 muxer，任何影响
     * 输出字节的计划变化都会产生新键。这样 WebDAV 每次变化的回环端口和秘密路径不会破坏命中，本地绝对
     * 路径也不会成为缓存身份。
     *
     * 缓存保存的是可删除、可重建的 MP3/AAC/Opus 完整输出，不是 WebDAV 原始文件；发布与并发 no-replace
     * 仍由 FfmpegTranscodeRunner 负责。命令结构异常时失败关闭，不允许多个输入错误共享同一缓存。
     *
     * @param list<string> $command 已完成白名单构造的 FFmpeg argv。
     */
    private function persistentCacheKey(PlayableMedia $media, array $command): string
    {
        $inputIndexes = array_keys($command, '-i', true);
        if (count($inputIndexes) !== 1) {
            throw new SubsonicRequestInvalid('Transcode cache input is invalid.');
        }
        $inputIndex = $inputIndexes[0] + 1;
        if (!isset($command[$inputIndex]) || !is_string($command[$inputIndex])) {
            throw new SubsonicRequestInvalid('Transcode cache input is invalid.');
        }
        $command[$inputIndex] = 'velin:verified-media-input';

        return hash('sha256', "velin-receiver-transcode-v2\0" . $media->etag . "\0"
            . json_encode($command, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Accepts null or one lowercase allowlisted output token. */
    private function format(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new SubsonicRequestInvalid('Stream format is invalid.');
        }
        $format = strtolower($value);
        if (!in_array($format, ['raw', 'mp3', 'aac', 'opus', 'flac', 'm4a', 'm4b', 'ogg', 'oga'], true)) {
            throw new SubsonicRequestInvalid('Requested stream format is unsupported.');
        }

        return $format;
    }

    /** Chooses a supported output while preserving a supported source codec when downsampling. */
    private function targetFormat(?string $requested, string $source): string
    {
        if (in_array($requested, ['mp3', 'aac', 'opus'], true)) {
            return $requested;
        }
        if ($requested !== null && $requested !== $source) {
            throw new SubsonicRequestInvalid('Requested source container cannot be a transcoding target.');
        }

        return match ($source) {
            'mp3' => 'mp3',
            'aac', 'm4a', 'm4b' => 'aac',
            'opus', 'ogg', 'oga' => 'opus',
            default => 'mp3',
        };
    }

    /** @return array{string,string,string,string,int,list<string>} Closed FFmpeg profile definition. */
    private function profile(string $format): array
    {
        return match ($format) {
            'mp3' => ['libmp3lame', 'mp3', 'audio/mpeg', 'mp3', 320, ['-write_xing', '0']],
            'aac' => ['aac', 'adts', 'audio/aac', 'aac', 256, []],
            'opus' => ['libopus', 'ogg', 'audio/ogg', 'opus', 192, ['-vbr', 'on', '-application', 'audio']],
            default => throw new SubsonicRequestInvalid('Requested stream format is unsupported.'),
        };
    }

    /** Resolves the administrator-controlled executable and rejects absent/non-executable paths. */
    private function executable(): string
    {
        $configured = (string) (getenv('VELIN_FFMPEG_PATH') ?: base_path('bin/ffmpeg'));
        $resolved = realpath($configured);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
            throw new SubsonicRequestInvalid('Transcoding is unavailable.');
        }

        return $resolved;
    }

    /** Parses aliases as one non-negative second offset and rejects conflicting duplicates. */
    private function offset(array $parameters): int
    {
        $values = [];
        foreach (['timeOffset', 'offset'] as $name) {
            if (array_key_exists($name, $parameters)) {
                $values[] = $this->integer($parameters[$name], 0, 86_400, 'Stream offset');
            }
        }
        if (count(array_unique($values)) > 1) {
            throw new SubsonicRequestInvalid('Stream offset aliases conflict.');
        }

        return $values[0] ?? 0;
    }

    /** Parses one bounded canonical decimal without PHP numeric-string coercion. */
    private function integer(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new SubsonicRequestInvalid($label . ' is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new SubsonicRequestInvalid($label . ' is outside the supported range.');
        }

        return $integer;
    }

    /** Accepts only explicit protocol booleans, never general PHP truthiness. */
    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 'true' => true,
            false, 'false' => false,
            default => throw new SubsonicRequestInvalid('A stream boolean parameter is invalid.'),
        };
    }
}
