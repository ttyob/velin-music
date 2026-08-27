<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use RuntimeException;

/** 表示预览后版本、文件身份、目标占用或幂等归属发生冲突。 */
final class LyricsWritebackConflict extends RuntimeException
{
}
