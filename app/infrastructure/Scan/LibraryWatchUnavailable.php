<?php

declare(strict_types=1);

namespace app\infrastructure\Scan;

use RuntimeException;

/** 表示 watcher 缺失、退出或违反版本化协议；reasonCode 可安全写日志且不包含路径。 */
final class LibraryWatchUnavailable extends RuntimeException
{
    /**
     * 使用固定机器码描述监督边界失败。
     *
     * reasonCode 只能由内部白名单产生，可进入结构化日志；原始进程错误仅保留为异常链供本地诊断，
     * 不得返回 HTTP 或记录其消息，以免 stderr 或系统路径越过脱敏边界。
     */
    public function __construct(public readonly string $reasonCode, ?\Throwable $previous = null)
    {
        parent::__construct('Library watch helper is unavailable.', previous: $previous);
    }
}
