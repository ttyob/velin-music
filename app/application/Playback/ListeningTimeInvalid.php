<?php

declare(strict_types=1);

namespace app\application\Playback;

use InvalidArgumentException;

/** 听歌时长的周期、日期、时区或内部区间不满足封闭契约。 */
final class ListeningTimeInvalid extends InvalidArgumentException
{
}
