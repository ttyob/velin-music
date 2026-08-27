<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use InvalidArgumentException;

/** 表示歌词写回输入、许可或可序列化内容不满足公开契约。 */
final class LyricsWritebackInvalid extends InvalidArgumentException
{
}
