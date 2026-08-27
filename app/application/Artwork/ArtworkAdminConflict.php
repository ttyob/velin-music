<?php

declare(strict_types=1);

namespace app\application\Artwork;

use RuntimeException;

/** 表示封面选择版本已变化或候选不再属于当前实体/音乐库。 */
final class ArtworkAdminConflict extends RuntimeException
{
}
