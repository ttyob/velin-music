<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/** Worker 内部稳定失败；reasonCode 可以持久化，异常消息和 previous 不得对外展示。 */
final class LyricsAudioTagWritebackFailed extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
