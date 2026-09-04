<?php

declare(strict_types=1);

namespace app\http;

use RuntimeException;

/** 文件在跨进程投递前无法满足路径、身份、范围或加密协议约束。 */
final class MediaDeliveryUnavailable extends RuntimeException
{
}
