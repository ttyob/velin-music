<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use RuntimeException;

/** 表示歌曲、歌词或方案在当前音乐库管理范围内不可见。 */
final class LyricsWritebackNotFound extends RuntimeException
{
}
