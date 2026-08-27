<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** 内嵌封面无法安全物化时携带稳定、无路径错误码，调用方不得记录音频路径或子进程原始输出。 */
final class EmbeddedArtworkExtractionFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('内嵌封面暂时不可用。');
    }
}
