<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/** 表示不可变方案、歌词版本、文件身份或幂等意图已经发生冲突。 */
final class LyricsAudioTagWritebackConflict extends \RuntimeException
{
}
