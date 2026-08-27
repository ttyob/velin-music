<?php

declare(strict_types=1);

namespace app\application\Account;

/** Web 与 Subsonic 共用的内存头像响应投影；不包含源上传、邮箱或文件路径。 */
final readonly class AccountAvatarImage
{
    public function __construct(
        public string $bytes,
        public string $etag,
        public int $updatedAt,
        public bool $custom,
    ) {
    }
}
