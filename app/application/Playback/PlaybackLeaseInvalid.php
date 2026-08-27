<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** 播放租约命令的歌曲、播放器或租约标识不符合固定协议。 */
final class PlaybackLeaseInvalid extends RuntimeException
{
}
