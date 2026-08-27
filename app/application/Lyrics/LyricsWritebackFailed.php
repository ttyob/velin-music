<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use RuntimeException;

/**
 * 携带不含路径和歌词正文的稳定 Worker 失败码。
 *
 * HTTP 层不直接抛出本异常；Worker 只把 `reasonCode` 持久化到任务和操作日志，消息保持固定，
 * 避免文件名、服务器目录或歌词正文经错误链进入日志。
 */
final class LyricsWritebackFailed extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
