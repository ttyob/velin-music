<?php

declare(strict_types=1);

namespace app\application\Scrape;

use app\infrastructure\Media\FfprobeMediaProbe;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * 使用 FFmpeg 重封装整理结果并以同目录原子替换写入描述标签。
 *
 * 本类只接收已经通过整理器根目录和身份校验的绝对目标路径。它先把目标重命名为任务专属备份，再
 * 从备份以 `-map 0 -c copy` 重封装到同目录临时文件，覆盖白名单描述标签后通过 FFprobe 复核标题、
 * 艺人和时长，最后原子发布。该方式不会重新编码音频；硬链接写入时会写时复制，软链接会物化为
 * 普通文件，二者都不会写穿到刮削源文件。
 *
 * 失败时立即恢复原目标。成功后备份仍保留到调用方数据库事务提交，因此移动模式即使后续持久化
 * 失败也能恢复原字节和原标签。路径、FFmpeg stderr 和元数据值不会进入日志或异常消息。
 */
final class FfmpegAudioMetadataWriter implements AudioMetadataWriter, AudioLyricsWriter
{
    /** 首期已经用合成音频验证 FFmpeg 写入与 FFprobe 读回行为的容器扩展名。 */
    private const LYRICS_CONTAINERS = ['mp3', 'flac', 'opus', 'm4a', 'm4b', 'mp4'];

    public function __construct(
        private readonly ?string $binaryPath = null,
        private readonly FfprobeMediaProbe $probe = new FfprobeMediaProbe(),
    ) {
    }

    /**
     * 创建一个可提交或回滚的标签替换。
     *
     * @throws ScrapePipelineFailed FFmpeg 不可用、容器不支持、验证失败或原子替换失败
     */
    public function rewrite(string $targetPath, ScrapeMetadataCandidate $candidate, string $jobId): AudioMetadataRewrite
    {
        $metadata = $candidate->metadata;
        return $this->rewriteTags(
            $targetPath,
            $this->tags($candidate),
            $jobId,
            static fn (\app\application\Media\MediaMetadata $original, \app\application\Media\MediaMetadata $written): bool =>
                $written->title === (string) $metadata['title']
                && $written->artists !== []
                && in_array((string) $metadata['artists'][0], $written->artists, true)
                && abs($written->durationMs - $original->durationMs) <= 1_000,
        );
    }

    /**
     * 把普通、逐行或逐字歌词写入容器的统一 `lyrics` 标签。
     *
     * FFmpeg 会按容器映射到 ID3/Vorbis/MP4 的实际字段。这里不依赖字段在 format 或 stream 层的
     * 具体位置，而是通过规范化后的原始标签快照查找并逐字节比较。内容只存在于参数、子进程参数和
     * 临时文件中，异常与日志均不得携带正文。逐字内容必须由上层确定性序列化器生成；
     * 本层仍以完整 UTF-8 字节读回为成功条件，防止容器映射吞掉尖括号时间标记。
     */
    public function rewriteLyrics(string $targetPath, string $lyrics, string $lyricKind, string $jobId): AudioMetadataRewrite
    {
        $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::LYRICS_CONTAINERS, true)) {
            throw new ScrapePipelineFailed('LYRICS_AUDIO_CONTAINER_UNSUPPORTED', '音频容器不支持歌词标签写入。');
        }
        if (!in_array($lyricKind, ['plain', 'line', 'word'], true)
            || $lyrics === '' || strlen($lyrics) > 1_048_576 || !mb_check_encoding($lyrics, 'UTF-8')) {
            throw new ScrapePipelineFailed('LYRICS_AUDIO_CONTENT_INVALID', '歌词标签内容无效。');
        }

        return $this->rewriteTags(
            $targetPath,
            // 写入统一键并显式清除历史别名，避免容器同时保留两套相互冲突的歌词。
            ['lyrics' => $lyrics, 'unsyncedlyrics' => '', 'syncedlyrics' => ''],
            $jobId,
            static function (\app\application\Media\MediaMetadata $original, \app\application\Media\MediaMetadata $written) use ($lyrics): bool {
                $found = null;
                foreach ($written->rawTags as $tags) {
                    if (is_array($tags) && is_string($tags['lyrics'] ?? null)) {
                        $found = $tags['lyrics'];
                        break;
                    }
                }
                return $found === rtrim($lyrics, "\r\n")
                    && abs($written->durationMs - $original->durationMs) <= 1_000;
            },
            'LYRICS_AUDIO',
        );
    }

    /**
     * 把已经校验的图片作为 attached picture 嵌入音频，并保留可回滚的原子替换记录。
     *
     * artworkPath 必须位于调用方任务专属暂存区，且只能是 JPEG/PNG 普通文件；本方法不会访问网络，也
     * 不把图片复制到音乐库目录。FFmpeg 只复制音频和图片流，写入后通过 FFprobe 复验存在视频附加流；
     * 任一步失败都会恢复原文件，调用方只有在任务数据库事实提交后才能 commit 删除备份。
     */
    public function rewriteArtwork(string $targetPath, string $artworkPath, string $mimeType, string $jobId): AudioMetadataRewrite
    {
        if (!is_file($artworkPath) || is_link($artworkPath) || !is_readable($artworkPath)
            || !in_array(strtolower($mimeType), ['image/jpeg', 'image/png'], true)) {
            throw new ScrapePipelineFailed('ARTWORK_INPUT_INVALID', '封面暂存文件无效。');
        }
        return $this->rewriteTagsWithArtwork($targetPath, $artworkPath, $jobId);
    }

    /** @return AudioMetadataRewrite 仅供 rewriteArtwork 使用的原子图片流替换。 */
    private function rewriteTagsWithArtwork(string $targetPath, string $artworkPath, string $jobId): AudioMetadataRewrite
    {
        $binary = $this->binaryPath ?? (getenv('VELIN_FFMPEG_PATH') ?: base_path('bin/ffmpeg'));
        $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)
            || $extension === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new ScrapePipelineFailed('ARTWORK_WRITER_UNAVAILABLE', '封面写入器不可用。');
        }
        $directory = dirname($targetPath);
        $backup = $directory . DIRECTORY_SEPARATOR . '.velin-artwork-backup-' . $jobId . '.' . $extension;
        $temporary = $directory . DIRECTORY_SEPARATOR . '.velin-artwork-output-' . $jobId . '.' . $extension;
        if (file_exists($backup) || file_exists($temporary) || is_link($backup) || is_link($temporary)
            || !rename($targetPath, $backup)) {
            throw new ScrapePipelineFailed('ARTWORK_BACKUP_FAILED', '无法准备封面写入备份。');
        }
        try {
            $arguments = [$binary, '-v', 'error', '-nostdin', '-i', $backup, '-i', $artworkPath,
                '-map', '0', '-map', '1', '-map_metadata', '0', '-c:a', 'copy', '-c:v', 'copy',
                '-disposition:v:0', 'attached_pic', '-metadata:s:v:0', 'title=Album cover', '-n', $temporary];
            $process = new Process($arguments);
            $process->setTimeout(120.0);
            if ($process->run() !== 0 || !is_file($temporary) || filesize($temporary) <= 0) {
                throw new ScrapePipelineFailed('ARTWORK_WRITE_REJECTED', '音频容器拒绝封面写入。');
            }
            $this->flush($temporary);
            $written = $this->probe->probe($temporary, pathinfo($targetPath, PATHINFO_FILENAME));
            if (abs($written->durationMs - $this->probe->probe($backup, pathinfo($targetPath, PATHINFO_FILENAME))->durationMs) > 1_000
                || !rename($temporary, $targetPath)) {
                throw new ScrapePipelineFailed('ARTWORK_WRITE_VERIFY_FAILED', '写入后的封面校验失败。');
            }
            return new AudioMetadataRewrite($targetPath, $backup, false);
        } catch (Throwable $throwable) {
            if (file_exists($temporary) || is_link($temporary)) @unlink($temporary);
            if (file_exists($targetPath) || is_link($targetPath)) @unlink($targetPath);
            @rename($backup, $targetPath);
            if ($throwable instanceof ScrapePipelineFailed) throw $throwable;
            throw new ScrapePipelineFailed('ARTWORK_WRITE_FAILED', '封面写入失败。', $throwable);
        }
    }

    /**
     * 共享的音频标签文件替换原语。
     *
     * 描述标签和歌词标签都必须经过完全相同的备份、重封装、刷新、权限继承、验证和原子发布流程；
     * `verify` 只定义业务字段如何复验，不能改变文件操作顺序。返回前保留完整备份，数据库提交后才
     * 允许调用 commit 删除。绝对路径、stderr 和标签值都不会进入异常消息。
     *
     * @param array<string,string> $tags 已由上层固定白名单产生的标签
     * @param callable(\app\application\Media\MediaMetadata,\app\application\Media\MediaMetadata):bool $verify
     */
    private function rewriteTags(
        string $targetPath,
        array $tags,
        string $jobId,
        callable $verify,
        string $errorPrefix = 'TAG',
    ): AudioMetadataRewrite {
        $binary = $this->binaryPath ?? (getenv('VELIN_FFMPEG_PATH') ?: base_path('bin/ffmpeg'));
        if (!str_starts_with($binary, '/') || !is_file($binary) || !is_executable($binary)) {
            throw new ScrapePipelineFailed($errorPrefix . '_WRITER_UNAVAILABLE', '音频标签写入器不可用。');
        }
        if (!str_starts_with($targetPath, '/') || (!is_file($targetPath) && !is_link($targetPath))) {
            throw new ScrapePipelineFailed('TAG_TARGET_UNAVAILABLE', '待写入标签的整理结果不可用。');
        }
        $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if ($extension === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new ScrapePipelineFailed('TAG_CONTAINER_UNSUPPORTED', '音频容器不支持标签写入。');
        }
        $directory = dirname($targetPath);
        $backup = $directory . DIRECTORY_SEPARATOR . '.velin-tag-backup-' . $jobId . '.' . $extension;
        $temporary = $directory . DIRECTORY_SEPARATOR . '.velin-tag-output-' . $jobId . '.' . $extension;
        if (file_exists($backup) || is_link($backup) || file_exists($temporary) || is_link($temporary)) {
            throw new ScrapePipelineFailed('TAG_TEMP_CONFLICT', '标签写入临时文件发生冲突。');
        }
        $before = @stat($targetPath);
        $detachedLink = is_link($targetPath) || (is_array($before) && (int) ($before['nlink'] ?? 1) > 1);
        if (!rename($targetPath, $backup)) {
            throw new ScrapePipelineFailed('TAG_BACKUP_FAILED', '无法准备标签写入备份。');
        }

        try {
            $input = realpath($backup);
            if ($input === false || !is_file($input) || !is_readable($input)) {
                throw new ScrapePipelineFailed('TAG_INPUT_UNAVAILABLE', '标签写入输入文件不可用。');
            }
            $original = $this->probe->probe($input, pathinfo($targetPath, PATHINFO_FILENAME));
            $arguments = [$binary, '-v', 'error', '-nostdin', '-i', $input, '-map', '0', '-c', 'copy', '-map_metadata', '0'];
            foreach ($tags as $key => $value) {
                $arguments[] = '-metadata';
                $arguments[] = $key . '=' . $value;
            }
            $arguments[] = '-n';
            $arguments[] = $temporary;
            $process = new Process($arguments);
            $process->setTimeout(120.0);
            try {
                $exitCode = $process->run();
            } catch (ProcessTimedOutException $exception) {
                throw new ScrapePipelineFailed('TAG_WRITE_TIMEOUT', '音频标签写入超时。', $exception);
            }
            if ($exitCode !== 0 || !is_file($temporary) || filesize($temporary) === 0) {
                throw new ScrapePipelineFailed('TAG_WRITE_REJECTED', '音频容器拒绝标签写入。');
            }
            $this->flush($temporary);
            if (is_array($before)) {
                @chmod($temporary, (int) $before['mode'] & 0777);
            }
            $written = $this->probe->probe($temporary, pathinfo($targetPath, PATHINFO_FILENAME));
            if (!$verify($original, $written)) {
                throw new ScrapePipelineFailed($errorPrefix . '_WRITE_VERIFY_FAILED', '写入后的音频标签或时长校验失败。');
            }
            if (!rename($temporary, $targetPath)) {
                throw new ScrapePipelineFailed('TAG_PUBLISH_FAILED', '无法原子发布已写入标签的文件。');
            }

            return new AudioMetadataRewrite($targetPath, $backup, $detachedLink);
        } catch (Throwable $throwable) {
            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            if (file_exists($targetPath) || is_link($targetPath)) {
                @unlink($targetPath);
            }
            @rename($backup, $targetPath);
            if ($throwable instanceof ScrapePipelineFailed) {
                throw $throwable;
            }
            throw new ScrapePipelineFailed('TAG_WRITE_FAILED', '音频标签写入失败。', $throwable);
        }
    }

    /**
     * 把整理结果中的软链接物化为内容相同的普通文件，但不修改任何音频标签。
     *
     * 软链接不能直接交给音乐库：源目录后续清理会使结果失效，而任何原地标签工具还可能沿链接写穿
     * 源文件。本方法先把链接本身改名为任务备份，再从其只读目标复制到同目录临时文件，刷新并原子
     * 发布。事务失败时 rollback 会恢复原软链接；提交后 commit 只删除该任务精确备份。
     */
    public function materializeSymlink(string $targetPath, string $jobId): AudioMetadataRewrite
    {
        if (!str_starts_with($targetPath, '/') || !is_link($targetPath)) {
            throw new ScrapePipelineFailed('TAG_TARGET_NOT_SYMLINK', '待物化的整理结果不是软链接。');
        }
        $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if ($extension === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new ScrapePipelineFailed('TAG_CONTAINER_UNSUPPORTED', '音频容器不支持物化。');
        }
        $directory = dirname($targetPath);
        $backup = $directory . DIRECTORY_SEPARATOR . '.velin-tag-backup-' . $jobId . '.' . $extension;
        $temporary = $directory . DIRECTORY_SEPARATOR . '.velin-tag-output-' . $jobId . '.' . $extension;
        if (file_exists($backup) || is_link($backup) || file_exists($temporary) || is_link($temporary)) {
            throw new ScrapePipelineFailed('TAG_TEMP_CONFLICT', '软链接物化临时文件发生冲突。');
        }
        $source = realpath($targetPath);
        if ($source === false || !is_file($source) || !is_readable($source)) {
            throw new ScrapePipelineFailed('TAG_INPUT_UNAVAILABLE', '软链接指向的音频文件不可用。');
        }
        $before = @stat($source);
        if (!rename($targetPath, $backup)) {
            throw new ScrapePipelineFailed('TAG_BACKUP_FAILED', '无法准备软链接物化备份。');
        }

        try {
            if (!copy($source, $temporary) || !is_file($temporary)) {
                throw new ScrapePipelineFailed('TAG_MATERIALIZE_FAILED', '无法物化软链接整理结果。');
            }
            $this->flush($temporary);
            if (is_array($before)) {
                @chmod($temporary, (int) $before['mode'] & 0777);
            }
            if (!rename($temporary, $targetPath)) {
                throw new ScrapePipelineFailed('TAG_PUBLISH_FAILED', '无法原子发布物化后的文件。');
            }
            return new AudioMetadataRewrite($targetPath, $backup, true);
        } catch (Throwable $throwable) {
            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            if (file_exists($targetPath) || is_link($targetPath)) {
                @unlink($targetPath);
            }
            @rename($backup, $targetPath);
            if ($throwable instanceof ScrapePipelineFailed) {
                throw $throwable;
            }
            throw new ScrapePipelineFailed('TAG_MATERIALIZE_FAILED', '软链接物化失败。', $throwable);
        }
    }

    /** 数据库提交后删除当前任务精确备份；删除失败保留文件并由维护任务处理，不能回滚已提交结果。 */
    public function commit(AudioMetadataRewrite $rewrite): void
    {
        if (file_exists($rewrite->backupPath) || is_link($rewrite->backupPath)) {
            @unlink($rewrite->backupPath);
        }
    }

    /** 数据库提交前失败时恢复原字节；仅操作 rewrite 记录的任务专属路径。 */
    public function rollback(AudioMetadataRewrite $rewrite): void
    {
        if (!file_exists($rewrite->backupPath) && !is_link($rewrite->backupPath)) {
            return;
        }
        if (file_exists($rewrite->targetPath) || is_link($rewrite->targetPath)) {
            @unlink($rewrite->targetPath);
        }
        @rename($rewrite->backupPath, $rewrite->targetPath);
    }

    /** @return array<string,string> 把最终候选映射到 FFmpeg 支持的白名单标签键。 */
    private function tags(ScrapeMetadataCandidate $candidate): array
    {
        $metadata = $candidate->metadata;
        $tags = [
            'title' => (string) $metadata['title'],
            'artist' => implode('; ', $metadata['artists']),
            'album_artist' => implode('; ', $metadata['albumArtists']),
            'album' => (string) $metadata['albumTitle'],
        ];
        $optional = [
            'genre' => is_array($metadata['genres'] ?? null) ? implode('; ', $metadata['genres']) : null,
            'date' => $metadata['releaseDate'] ?? null,
            'composer' => $metadata['composer'] ?? null,
            'isrc' => $metadata['isrc'] ?? null,
            'musicbrainz_trackid' => $metadata['musicbrainzTrackId'] ?? null,
            'musicbrainz_artistid' => $metadata['musicbrainzArtistId'] ?? null,
            'musicbrainz_albumid' => $metadata['musicbrainzReleaseId'] ?? null,
            'musicbrainz_releasegroupid' => $metadata['musicbrainzReleaseGroupId'] ?? null,
        ];
        foreach ($optional as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $tags[$key] = trim($value);
            }
        }
        if (is_int($metadata['trackNumber'] ?? null)) {
            $tags['track'] = (string) $metadata['trackNumber']
                . (is_int($metadata['trackTotal'] ?? null) ? '/' . $metadata['trackTotal'] : '');
        }
        if (is_int($metadata['discNumber'] ?? null)) {
            $tags['disc'] = (string) $metadata['discNumber']
                . (is_int($metadata['discTotal'] ?? null) ? '/' . $metadata['discTotal'] : '');
        }
        return $tags;
    }

    /** 刷新同目录临时文件，确保原子改名之前数据已经交给内核落盘。 */
    private function flush(string $path): void
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new ScrapePipelineFailed('TAG_FLUSH_FAILED', '无法刷新标签写入结果。');
        }
        try {
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ScrapePipelineFailed('TAG_FLUSH_FAILED', '无法刷新标签写入结果。');
            }
        } finally {
            fclose($handle);
        }
    }
}
