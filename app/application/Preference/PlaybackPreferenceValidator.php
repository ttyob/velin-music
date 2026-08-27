<?php

declare(strict_types=1);

namespace app\application\Preference;

/**
 * 校验 Web 播放器可公开提交的封闭配置，拒绝 PHP 隐式类型转换。
 *
 * 新界面只写入 `direct|transcode`。为兼容已打开的旧标签页，`auto` 请求仍被接受但立即规范为
 * `direct`，后续持久化和响应不再产生第三种用户音质，避免滚动发布期间出现无效保存。
 */
final class PlaybackPreferenceValidator
{
    /**
     * @param array<string,mixed> $payload 已解析 JSON 对象。
     * @return array{expectedVersion:int,streamMode:string,transcodeFormat:string,maxBitrateKbps:int,replayGainMode:string,preventClipping:bool}
     */
    public function validate(array $payload): array
    {
        $expectedVersion = $payload['expectedVersion'] ?? null;
        $streamMode = $payload['streamMode'] ?? null;
        $format = $payload['transcodeFormat'] ?? null;
        $bitrate = $payload['maxBitrateKbps'] ?? null;
        $replayGainMode = $payload['replayGainMode'] ?? null;
        $preventClipping = $payload['preventClipping'] ?? null;

        if (!is_int($expectedVersion) || $expectedVersion < 1
            || !is_string($streamMode) || !in_array($streamMode, ['direct', 'auto', 'transcode'], true)
            || !is_string($format) || !in_array($format, ['mp3', 'aac', 'opus'], true)
            || !is_int($bitrate) || !in_array($bitrate, [64, 96, 128, 160, 192, 256, 320], true)
            || !is_string($replayGainMode) || !in_array($replayGainMode, ['off', 'track', 'album'], true)
            || !is_bool($preventClipping)) {
            throw new UserPreferenceInvalid('Playback preference is invalid.');
        }
        if ($streamMode === 'direct' && $replayGainMode !== 'off') {
            throw new UserPreferenceInvalid('ReplayGain requires automatic or transcoded playback.');
        }

        return [
            'expectedVersion' => $expectedVersion,
            'streamMode' => $streamMode === 'auto' ? 'direct' : $streamMode,
            'transcodeFormat' => $format,
            'maxBitrateKbps' => $bitrate,
            'replayGainMode' => $replayGainMode,
            'preventClipping' => $preventClipping,
        ];
    }
}
