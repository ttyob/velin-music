<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 表示一次尚未提交的音频标签原子替换或软链接原子物化。
 *
 * backupPath 和 targetPath 只在 Worker 内存中存在，禁止写入日志、数据库或 API。调用方必须在业务
 * 事务成功后调用 writer::commit，或在任何失败路径调用 writer::rollback；两者均可重复调用而不会
 * 推断或删除其他任务创建的文件。detachedLink 表示原结果曾为硬/软链接，写时复制后已成为普通文件。
 */
final readonly class AudioMetadataRewrite
{
    public function __construct(
        public string $targetPath,
        public string $backupPath,
        public bool $detachedLink,
    ) {
    }
}
