<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/**
 * 保存一次成功发布的内部文件身份证据，供数据库提交失败时精确补偿。
 *
 * 绝对路径只在同一 Worker 调用栈内存在，禁止进入 API、数据库、审计或日志。补偿必须同时匹配
 * device、inode、大小与 SHA-256，任何一项变化都停止删除，避免移除并发创建或修改的文件。
 */
final readonly class LyricsSidecarPublishResult
{
    public function __construct(
        public string $absoluteTarget,
        public int $device,
        public int $inode,
        public int $size,
        public string $sha256,
        /** 替换任务的旧文件备份；新建任务始终为 null，且该路径绝不持久化。 */
        public ?string $absoluteBackup = null,
        public ?int $originalDevice = null,
        public ?int $originalInode = null,
        public ?int $originalSize = null,
        public ?string $originalSha256 = null,
    ) {
    }

    /** 是否为“已有目标 -> 新内容”的双态替换，而不是独占新建。 */
    public function replacedExisting(): bool
    {
        return $this->absoluteBackup !== null;
    }
}
