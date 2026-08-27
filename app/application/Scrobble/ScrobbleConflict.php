<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use RuntimeException;

/** 表示连接已经被另一请求修改，调用方必须刷新脱敏快照后再提交。 */
final class ScrobbleConflict extends RuntimeException
{
}
