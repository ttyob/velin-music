<?php

declare(strict_types=1);

namespace app\application\Media;

/**
 * 包装已经原子发布到私有 runtime 的远程播放缓存。
 *
 * 构造时冻结普通文件的 dev/inode/size，之后每次交给 Workerman、范围读取或 FFmpeg 前都重新核对。缓存是
 * 可删除重建的性能副本，不是授权事实；调用方必须先通过 MediaStreamService 复验实时账号、音乐库授权和
 * 远端对象 ETag。身份漂移时失败关闭，绝不回退读取被替换的 runtime 路径。
 */
final readonly class CachedMediaSource implements MediaReadableSource
{
    private int $device;
    private int $inode;

    public function __construct(private string $path, private int $expectedSize)
    {
        $stat = $this->validStat();
        $this->device = (int) $stat['dev'];
        $this->inode = (int) $stat['ino'];
    }

    /** 返回复验后的内部缓存路径；公开投影与日志不得使用该返回值。 */
    public function localPath(): ?string
    {
        $this->assertIdentity();
        return $this->path;
    }

    /** 按统一 1 MiB 上限读取缓存；短读和发布后替换均按媒体不可用处理。 */
    public function readRange(int $offset, int $length): string
    {
        $this->assertIdentity();
        if ($offset < 0 || $length < 1 || $length > 1_048_576 || $offset + $length > $this->expectedSize) {
            throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_RANGE_INVALID');
        }
        $handle = @fopen($this->path, 'rb');
        if (!is_resource($handle)) throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_UNAVAILABLE');
        try {
            if (fseek($handle, $offset) !== 0) {
                throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_RANGE_INVALID');
            }
            $bytes = fread($handle, $length);
            if (!is_string($bytes) || strlen($bytes) !== $length) {
                throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_SHORT_READ');
            }
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** FFmpeg 只取得复验后的私有缓存路径，不再建立远端回环代理。 */
    public function openTranscodeInput(): MediaInputLease
    {
        $this->assertIdentity();
        return new MediaInputLease($this->path);
    }

    /** @return array<string,int> */
    private function validStat(): array
    {
        $stat = @lstat($this->path);
        if (!is_array($stat) || is_link($this->path) || !is_file($this->path)
            || !is_readable($this->path) || (int) ($stat['size'] ?? -1) !== $this->expectedSize
            || $this->expectedSize < 1) {
            throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_IDENTITY_CHANGED');
        }
        return $stat;
    }

    /** 发布目录由应用独占，但仍防御同机进程或误操作在响应创建前替换缓存文件。 */
    private function assertIdentity(): void
    {
        $stat = $this->validStat();
        if ((int) $stat['dev'] !== $this->device || (int) $stat['ino'] !== $this->inode) {
            throw new MediaStreamUnavailable('REMOTE_PLAYBACK_CACHE_IDENTITY_CHANGED');
        }
    }
}
