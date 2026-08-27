<?php

declare(strict_types=1);

namespace app\application\Metadata;

use RuntimeException;

/** 对不存在与当前操作者不可管理的媒体使用相同异常，避免对象枚举。 */
final class MediaMetadataNotFound extends RuntimeException
{
}
