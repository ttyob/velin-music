<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;
use Throwable;

/**
 * 携带不含路径、标签值和命令输出的音频标签写回稳定失败码。
 *
 * Worker 只把 `reasonCode` 写入任务、操作日志和安全投影；内部异常通过 previous 保留给进程级诊断，
 * 但不得把异常原文返回浏览器。调用方可用 stale 分类决定方案是过期还是普通执行失败。
 */
final class AudioTagWritebackFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
