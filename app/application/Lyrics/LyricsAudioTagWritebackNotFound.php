<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/** 对不存在和实时失权统一使用，避免枚举歌曲、歌词或方案。 */
final class LyricsAudioTagWritebackNotFound extends \RuntimeException
{
}
