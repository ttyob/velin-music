<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 包装已经由 MediaStreamService 复验的本地普通文件。
 *
 * 构造后的路径只在当前请求内部交给 Workerman 或固定 FFmpeg argv，不进入公开投影。readRange 仍会
 * 重新要求普通可读非链接文件，并用文件描述符按偏移读取；调用方通常优先使用 Workerman withFile。
 */
final readonly class LocalMediaSource implements MediaReadableSource
{
    public function __construct(private string $path)
    {
    }

    /** 返回请求内已复验的规范绝对路径。 */
    public function localPath(): ?string
    {
        return $this->path;
    }

    /** 有界读取本地范围；短读或路径替换统一失败，不把部分正文当作完整范围。 */
    public function readRange(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1 || $length > 1_048_576 || is_link($this->path)
            || !is_file($this->path) || !is_readable($this->path)) {
            throw new MediaStreamUnavailable('MEDIA_SOURCE_RANGE_INVALID');
        }
        $handle = @fopen($this->path, 'rb');
        if (!is_resource($handle)) throw new MediaStreamUnavailable('MEDIA_SOURCE_UNAVAILABLE');
        try {
            if (fseek($handle, $offset) !== 0) throw new MediaStreamUnavailable('MEDIA_SOURCE_RANGE_INVALID');
            $bytes = fread($handle, $length);
            if (!is_string($bytes) || strlen($bytes) !== $length) {
                throw new MediaStreamUnavailable('MEDIA_SOURCE_SHORT_READ');
            }
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** 本地 FFmpeg 直接读取已复验路径，不创建副本或临时文件。 */
    public function openTranscodeInput(): MediaInputLease
    {
        return new MediaInputLease($this->path);
    }
}
