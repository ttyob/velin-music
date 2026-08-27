<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 表示一个已经批准播放、但底层可来自本地或 WebDAV 的内部媒体描述。
 *
 * 读取细节封装在 MediaReadableSource：本地源暴露内部规范路径，WebDAV 源只提供有界 Range 和一次性
 * 回环 FFmpeg 输入；后台下一首任务也可在实时远端身份复验后替换为有界私有缓存源。源对象及其秘密不得
 * 进入 JSON、日志、响应头或审计。
 * ETag 是稳定媒体身份的摘要，不泄露文件系统或远端坐标。
 * Duration、码率与 ReplayGain 来自同一条已完成扫描记录，只用于有界转码协商；无论直放还是转换，
 * 开始前都必须重新验证运行时文件身份。四个增益值不会进入公开 JSON 或响应头。
 */
final readonly class PlayableMedia
{
    public function __construct(
        public string $songId,
        public string $title,
        public MediaReadableSource $source,
        public string $downloadName,
        public string $mimeType,
        public int $fileSize,
        public int $modifiedAt,
        public string $etag,
        public int $durationMs,
        public ?int $bitrate,
        public ?float $replayGainTrackGain = null,
        public ?float $replayGainTrackPeak = null,
        public ?float $replayGainAlbumGain = null,
        public ?float $replayGainAlbumPeak = null,
    ) {
    }
}
