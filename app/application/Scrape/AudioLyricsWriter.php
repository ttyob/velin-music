<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 定义歌词写入音频容器标签的文件系统边界。
 *
 * 歌词标签写回与普通描述标签写回拥有不同的方案、确认文本和权限意图，但必须共享同一套完整备份、
 * 无重编码重封装、原子发布与补偿实现。调用方只能传入已经确定性序列化并通过许可检查的 UTF-8
 * 普通或逐行歌词；逐字歌词不得在这里静默降级。
 */
interface AudioLyricsWriter
{
    /**
     * 创建尚未提交的歌词标签替换，并在返回前逐字节复验容器中的 `lyrics` 标签。
     *
     * @throws ScrapePipelineFailed 容器不支持、FFmpeg 失败、标签复验失败或原子发布失败
     */
    public function rewriteLyrics(
        string $targetPath,
        string $lyrics,
        string $lyricKind,
        string $jobId,
    ): AudioMetadataRewrite;

    /** 数据库事实提交后删除当前任务的精确完整备份。 */
    public function commit(AudioMetadataRewrite $rewrite): void;

    /** 数据库提交前失败时恢复原字节和原链接身份。 */
    public function rollback(AudioMetadataRewrite $rewrite): void;
}
