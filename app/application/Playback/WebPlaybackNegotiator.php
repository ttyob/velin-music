<?php

declare(strict_types=1);

namespace app\application\Playback;

use app\application\Media\PlayableMedia;
use app\application\Preference\PlaybackPreferenceService;
use app\application\Subsonic\SubsonicTranscodeService;
use app\http\TranscodeResponse;

/**
 * 将账号音质偏好、App 单次选择、管理员上限与歌曲扫描标签协商为直放或受监督 FFmpeg 响应。
 *
 * Web 端不提交音质、format 或 bitrate 查询参数；App 可用短期 Bearer 提交 `original|standard`，但格式
 * 与码率仍取服务端偏好及实时管理员上限。该类复用 Subsonic 的封闭编码器配置、并发租约、超时与输出
 * 字节上限，不为移动端建立行为不同的第二套转码实现。
 */
final readonly class WebPlaybackNegotiator
{
    public function __construct(
        private PlaybackPreferenceService $preferences = new PlaybackPreferenceService(),
        private SubsonicTranscodeService $transcodes = new SubsonicTranscodeService(),
        private PlayerProfileService $players = new PlayerProfileService(),
    ) {
    }

    /**
     * 返回 NULL 表示保留 Range/ETag 原始直放；返回响应表示已持有转码槽并交给异步 HTTP supervisor。
     *
     * 账号或 App 选择原始音质时直接返回 NULL，标准音质明确强制统一格式与码率。播放器档案只设置最大
     * 码率而未指定格式时仍使用内部自动协商：仅在源码率明确超过上限时转换，未知源码率不会因猜测浪费 CPU。任何
     * 转换只继承调用方已经完成的 `play` 与音乐库授权，不再设置独立账号权限；管理员码率、并发槽、
     * FFmpeg 白名单和输出上限仍在每次协商时强制执行。曲目模式使用曲目标签，专辑模式优先专辑标签，
     * 缺失时回退曲目标签；防削波根据 peak 计算安全增益。
     *
     * requestedQuality 是 Controller 在认证后解析的 App 单次选择；NULL 表示继续使用账号/播放器默认。
     * `original` 优先于转码偏好并保留 Range，`standard` 优先于播放器 raw 档案。调用方不得把未经认证
     * 的查询值直接传入；领域层仍复验枚举，失败不打开媒体输入。
     *
     * timeOffsetSeconds 只用于客户端对不可随机读取的实时转码执行重新定位。它不会强迫原本可直放的
     * 媒体转码，也不能覆盖格式或码率；直放仍由浏览器通过 Range seek。偏移必须位于歌曲时长内，
     * 校验失败时不得打开输入或取得转码槽。
     *
     * @param array<string,mixed> $actor 已复验且具备 play 能力的 Session 投影。
     * @throws WebPlaybackRequestInvalid 偏移超出协议上限或歌曲有效时长。
     * @throws PlaybackQualityRequestInvalid 内部调用传入了未知音质枚举。
     */
    public function negotiate(
        array $actor,
        PlayableMedia $media,
        string $requestId,
        ?string $playerId = null,
        ?int $timeOffsetSeconds = null,
        ?string $requestedQuality = null,
    ): ?TranscodeResponse
    {
        if ($requestedQuality !== null && !in_array($requestedQuality, ['original', 'standard'], true)) {
            throw new PlaybackQualityRequestInvalid('播放音质参数无效。');
        }
        if ($timeOffsetSeconds !== null && ($timeOffsetSeconds < 0 || $timeOffsetSeconds > 86_400
            || ($media->durationMs > 0 && $timeOffsetSeconds * 1000 >= $media->durationMs))) {
            throw new WebPlaybackRequestInvalid('播放跳转位置无效。');
        }
        $preference = $this->preferences->snapshot($actor);
        $overrides = $playerId === null ? ['preferredFormat' => null, 'maxBitrateKbps' => null]
            : $this->players->webOverrides($actor, $playerId);
        if ($requestedQuality === 'original') return null;
        if ($overrides['preferredFormat'] === 'raw' && $requestedQuality === null) return null;
        $streamMode = (string) $preference['streamMode'];
        $format = (string) $preference['transcodeFormat'];
        if (in_array($overrides['preferredFormat'], ['mp3', 'aac', 'opus'], true)) {
            $streamMode = 'transcode';
            $format = (string) $overrides['preferredFormat'];
        }
        if ($requestedQuality === 'standard') $streamMode = 'transcode';
        if ($overrides['maxBitrateKbps'] !== null && $streamMode === 'direct') $streamMode = 'auto';
        if ($streamMode === 'direct') return null;

        $gainDb = $this->gain($media, $preference['replayGainMode'], $preference['preventClipping']);
        $selectedBitrate = $overrides['maxBitrateKbps'] ?? $preference['maxBitrateKbps'];
        $bitrateLimit = min((int) $selectedBitrate, (int) $preference['administratorMaxBitrateKbps']);
        $sourceExceedsLimit = $media->bitrate !== null && $media->bitrate > $bitrateLimit * 1000;
        $requiresConversion = $streamMode === 'transcode' || $sourceExceedsLimit || $gainDb !== null;
        if (!$requiresConversion) return null;

        $parameters = [
            'format' => $format,
            'maxBitRate' => $bitrateLimit,
            'converted' => true,
        ];
        if ($timeOffsetSeconds !== null && $timeOffsetSeconds > 0) {
            $parameters['timeOffset'] = $timeOffsetSeconds;
        }

        return $this->transcodes->negotiate(
            $media,
            $parameters,
            $requestId,
            $actor,
            false,
            $gainDb,
        );
    }

    /** 计算最终 dB 调整；返回 NULL 代表没有可信标签，自动模式可以继续原始直放。 */
    private function gain(PlayableMedia $media, string $mode, bool $preventClipping): ?float
    {
        if ($mode === 'off') return null;
        if ($mode === 'album') {
            $gain = $media->replayGainAlbumGain ?? $media->replayGainTrackGain;
            $peak = $media->replayGainAlbumGain !== null
                ? $media->replayGainAlbumPeak
                : $media->replayGainTrackPeak;
        } else {
            $gain = $media->replayGainTrackGain;
            $peak = $media->replayGainTrackPeak;
        }
        if ($gain === null || !is_finite($gain)) return null;
        $gain = max(-30.0, min(30.0, $gain));
        if (!$preventClipping || $peak === null || !is_finite($peak) || $peak <= 0.0) return $gain;

        $maximumWithoutClipping = -20.0 * log10($peak);
        return min($gain, $maximumWithoutClipping);
    }
}
