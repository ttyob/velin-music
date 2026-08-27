<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** 当前账号不存在可续租或释放的播放租约。 */
final class PlaybackLeaseNotFound extends RuntimeException
{
}
