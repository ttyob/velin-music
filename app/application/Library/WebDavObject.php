<?php

declare(strict_types=1);

namespace app\application\Library;

/** 网络库返回的规范化根内对象事实；历史类名保留协议兼容，不携带 URL、远端 ID 或凭据。 */
final readonly class WebDavObject
{
    public function __construct(
        public string $relativePath,
        public bool $directory,
        public int $size,
        public int $modifiedAt,
        public string $etag,
    ) {
    }
}
