<?php

declare(strict_types=1);

namespace app\application\Artwork;

use InvalidArgumentException;

/** 表示封面管理命令的图片、裁剪框、版本或实体作用域无效。 */
final class ArtworkAdminInvalid extends InvalidArgumentException
{
}
