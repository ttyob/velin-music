<?php

declare(strict_types=1);

namespace app\application\Media;

use Throwable;

/**
 * 管理网络音乐库下一首的私有完整原文件缓存。
 *
 * 缓存键只由统一媒体 ETag 派生，不包含路径、URL、账号或凭据。后台下载按 1 MiB 已验证 Range 写入同目录
 * 临时文件，完整长度落盘并 fsync 后才原子改名；失败删除临时文件，永不发布部分音频。读取命中本身不授
 * 权，MediaStreamService 每次仍先复验账号、库授权和远端对象身份，再把来源替换为本地缓存。
 *
 * 容量采用固定代码策略而非部署环境变量：单曲最多 1 GiB，总量最多 8 GiB/128 首，七天未命中按 LRU
 * 淘汰。空间不足、超限或 I/O 失败只放弃本次性能优化，不能阻断当前歌曲播放，也不能删除非固定命名文件。
 */
final readonly class RemotePlaybackCache
{
    private const DEFAULT_MAX_ITEM_BYTES = 1_073_741_824;
    private const DEFAULT_MAX_TOTAL_BYTES = 8_589_934_592;
    private const DEFAULT_MAX_FILES = 128;
    private const DEFAULT_TTL_SECONDS = 604_800;
    private const MIN_FREE_BYTES = 268_435_456;

    private string $root;

    public function __construct(
        ?string $root = null,
        private int $maxItemBytes = self::DEFAULT_MAX_ITEM_BYTES,
        private int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
        private int $maxFiles = self::DEFAULT_MAX_FILES,
        private int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        // Docker 用 `/app/runtime` 指向固定的 `/data/runtime`；先解析运行时根，才能让后续缓存目录的
        // 真实路径校验比较同一套字符串。环境变量不存在、目标尚未创建或 realpath 失败时保留原值，
        // 由 directory() 在创建/读取边界统一失败关闭，不把任意路径转换成可写缓存根。
        $resolvedRuntime = realpath($runtime);
        if (is_string($resolvedRuntime)) $runtime = $resolvedRuntime;
        $this->root = $root ?? rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'remote-playback-cache';
    }

    /**
     * 在远端身份复验后尝试切换到已完整发布的缓存；无命中原样返回远端媒体。
     *
     * 命中只更新文件 mtime 作为 LRU 访问时间。损坏或身份异常的固定缓存项会被尽力删除并按未命中处理，
     * 当前请求继续读取已经复验的远端源，不因可重建缓存损坏而失败。
     */
    public function useCached(PlayableMedia $media): PlayableMedia
    {
        if ($media->source->localPath() !== null) return $media;
        $directory = $this->directory(false);
        if ($directory === null) return $media;
        $path = $directory . DIRECTORY_SEPARATOR . $this->key($media) . '.audio';
        if (!$this->validEntry($path, $media->fileSize)) {
            $this->discardFixedEntry($path);
            return $media;
        }
        @touch($path);
        try {
            $source = new CachedMediaSource($path, $media->fileSize);
        } catch (MediaStreamUnavailable) {
            $this->discardFixedEntry($path);
            return $media;
        }
        return $this->withSource($media, $source);
    }

    /**
     * 把一个已经实时复验的远端对象完整、原子地写入缓存。
     *
     * 相同媒体已命中时幂等返回；本地源不复制。调用方必须在 SQLite 写事务外执行，并把异常作为可重试的
     * 后台性能任务处理。对象在下载中变化会由每段 Range 的 If-Match/Content-Range 校验中止。
     */
    public function ensure(PlayableMedia $media): bool
    {
        if ($media->source instanceof CachedMediaSource) return true;
        if ($media->source->localPath() !== null) return false;
        if ($media->fileSize < 1 || $media->fileSize > $this->maxItemBytes) {
            throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_ITEM_TOO_LARGE');
        }
        $directory = $this->directory(true);
        if ($directory === null) throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_DIRECTORY_UNAVAILABLE');
        $key = $this->key($media);
        $target = $directory . DIRECTORY_SEPARATOR . $key . '.audio';
        if ($this->validEntry($target, $media->fileSize)) {
            @touch($target);
            return true;
        }
        $this->discardFixedEntry($target);
        $this->prune($directory, $key);
        $free = @disk_free_space($directory);
        if (!is_float($free) && !is_int($free)) {
            throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_SPACE_UNKNOWN');
        }
        if ($free < $media->fileSize + self::MIN_FREE_BYTES) {
            throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_SPACE_LOW');
        }

        try {
            $suffix = bin2hex(random_bytes(12));
        } catch (Throwable) {
            throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_RANDOM_FAILED');
        }
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . $key . '.' . $suffix . '.part';
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_TEMP_OPEN_FAILED');
        @chmod($temporary, 0600);
        try {
            try {
                for ($offset = 0; $offset < $media->fileSize;) {
                    $length = min(1_048_576, $media->fileSize - $offset);
                    $bytes = $media->source->readRange($offset, $length);
                    if (strlen($bytes) !== $length) {
                        throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_SOURCE_SHORT_READ');
                    }
                    $this->writeAll($handle, $bytes);
                    $offset += $length;
                }
                if (function_exists('fsync') && !@fsync($handle)) {
                    throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_SYNC_FAILED');
                }
            } finally {
                fclose($handle);
            }
        } catch (Throwable $failure) {
            @unlink($temporary);
            throw $failure;
        }

        clearstatcache(true, $temporary);
        if (!$this->validEntry($temporary, $media->fileSize) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_PUBLISH_FAILED');
        }
        @chmod($target, 0640);
        $this->prune($directory, $key);
        return true;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        for ($written = 0, $length = strlen($bytes); $written < $length;) {
            $next = fwrite($handle, substr($bytes, $written));
            if (!is_int($next) || $next < 1) {
                throw new RemotePlaybackCacheUnavailable('REMOTE_PLAYBACK_CACHE_WRITE_FAILED');
            }
            $written += $next;
        }
    }

    /** 创建时只允许应用拥有的真实一级目录；读取路径绝不跟随目录符号链接。 */
    private function directory(bool $create): ?string
    {
        if ($create && !is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) return null;
        if (!is_dir($this->root) || is_link($this->root) || ($create && !is_writable($this->root))) return null;
        $resolved = realpath($this->root);
        return is_string($resolved) && $resolved === $this->root ? $resolved : null;
    }

    /** 统一媒体 ETag 已含歌曲、远端版本、大小和修改时间；再次域分离后才可成为内部文件名。 */
    private function key(PlayableMedia $media): string
    {
        return hash('sha256', "velin-remote-playback-cache-v1\0" . $media->etag);
    }

    private function validEntry(string $path, int $expectedSize): bool
    {
        $stat = @lstat($path);
        return is_array($stat) && !is_link($path) && is_file($path) && is_readable($path)
            && $expectedSize > 0 && (int) ($stat['size'] ?? -1) === $expectedSize;
    }

    /** 只删除本缓存类自己生成的固定摘要文件，不接触部分文件、子目录或未知目录项。 */
    private function discardFixedEntry(string $path): void
    {
        if (dirname($path) !== $this->root || preg_match('/^[a-f0-9]{64}\.audio$/D', basename($path)) !== 1) return;
        if (is_file($path) || is_link($path)) @unlink($path);
    }

    /**
     * 按访问 mtime 淘汰可重建缓存，并回收一小时前遗留的严格命名临时文件。
     *
     * 当前发布键始终受保护；即使它单独逼近上限，也不会在返回成功前自删。删除不可回滚但只影响性能，
     * 已打开的文件描述符继续可读，下一次请求未命中时重新走远端身份复验和 Range。
     */
    private function prune(string $directory, string $protectedKey): void
    {
        $names = @scandir($directory);
        if (!is_array($names)) return;
        $now = time();
        $entries = [];
        foreach ($names as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || !is_file($path)) continue;
            if (preg_match('/^\.[a-f0-9]{64}\.[a-f0-9]{24}\.part$/D', $name) === 1) {
                if ($now - (int) $stat['mtime'] > 3600) @unlink($path);
                continue;
            }
            if (preg_match('/^([a-f0-9]{64})\.audio$/D', $name, $matches) !== 1) continue;
            $entries[] = ['key' => $matches[1], 'path' => $path, 'size' => max(0, (int) $stat['size']),
                'mtime' => (int) $stat['mtime']];
        }
        usort($entries, static fn (array $left, array $right): int => $left['mtime'] <=> $right['mtime']);
        $bytes = array_sum(array_column($entries, 'size'));
        $files = count($entries);
        foreach ($entries as $entry) {
            $expired = $now - $entry['mtime'] > $this->ttlSeconds;
            $overLimit = $files > $this->maxFiles || $bytes > $this->maxTotalBytes;
            if ((!$expired && !$overLimit) || $entry['key'] === $protectedKey) continue;
            if (@unlink($entry['path'])) {
                --$files;
                $bytes -= $entry['size'];
            }
        }
    }

    private function withSource(PlayableMedia $media, MediaReadableSource $source): PlayableMedia
    {
        return new PlayableMedia(
            songId: $media->songId,
            title: $media->title,
            source: $source,
            downloadName: $media->downloadName,
            mimeType: $media->mimeType,
            fileSize: $media->fileSize,
            modifiedAt: $media->modifiedAt,
            etag: $media->etag,
            durationMs: $media->durationMs,
            bitrate: $media->bitrate,
            replayGainTrackGain: $media->replayGainTrackGain,
            replayGainTrackPeak: $media->replayGainTrackPeak,
            replayGainAlbumGain: $media->replayGainAlbumGain,
            replayGainAlbumPeak: $media->replayGainAlbumPeak,
        );
    }
}
