<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/** 表示端点存在，但请求的协议媒体类型或可选子能力不属于 Velin Music 的产品范围。 */
final class SubsonicFeatureUnsupported extends RuntimeException
{
}
