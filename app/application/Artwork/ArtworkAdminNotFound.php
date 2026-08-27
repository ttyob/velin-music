<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** 合并实体不存在与实时管理权限不足，避免通过管理接口枚举媒体。 */
final class ArtworkAdminNotFound extends RuntimeException
{
}
