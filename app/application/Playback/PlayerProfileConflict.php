<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** 表示播放器档案已由其他页面修改或删除，调用方必须刷新。 */
final class PlayerProfileConflict extends RuntimeException
{
}
