<?php

declare(strict_types=1);

namespace app\application\Playback;

use RuntimeException;

/** 表示播放器档案 ID、版本、名称、格式或码率不符合封闭契约。 */
final class PlayerProfileInvalid extends RuntimeException
{
}
