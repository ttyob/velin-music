<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use RuntimeException;

/** 表示外部 Scrobble 连接命令违反公开字段、版本或安全 URL 约束。 */
final class ScrobbleInvalid extends RuntimeException
{
}
