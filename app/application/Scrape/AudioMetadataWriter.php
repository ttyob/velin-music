<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 定义音频描述标签原子替换的文件系统边界。
 *
 * 实现必须使用无重编码方式生成同目录临时文件，并在返回前完成标签和音频时长复验。成功返回的
 * `AudioMetadataRewrite` 仍持有完整原文件备份：调用方只有在数据库事实提交后才能 `commit`，任意
 * 较早失败都必须 `rollback`。绝对路径和备份位置只能存在于 Worker 内存，禁止写入数据库或日志。
 */
interface AudioMetadataWriter
{
    /**
     * 创建尚未提交的原子标签替换；硬链接必须写时断链，不能修改共享 inode 的其他路径。
     *
     * @throws ScrapePipelineFailed 路径、容器、FFmpeg、发布或验证失败，且实现已恢复原目标。
     */
    public function rewrite(string $targetPath, ScrapeMetadataCandidate $candidate, string $jobId): AudioMetadataRewrite;

    /** 数据库成功提交后删除当前任务的精确备份；重复调用不得影响目标或其他临时文件。 */
    public function commit(AudioMetadataRewrite $rewrite): void;

    /** 数据库提交前失败时恢复原字节与原链接身份；重复调用不得删除其他任务创建的文件。 */
    public function rollback(AudioMetadataRewrite $rewrite): void;
}
