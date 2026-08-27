<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use RuntimeException;

/** 歌词文件缺失、越界、身份漂移或内容损坏时使用的稳定领域异常。 */
final class LyricsFileUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
