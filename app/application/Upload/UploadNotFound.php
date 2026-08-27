<?php

declare(strict_types=1);

namespace app\application\Upload;

use RuntimeException;

/** 合并上传对象不存在与当前账户无权访问，防止通过 ULID 枚举他人会话。 */
final class UploadNotFound extends RuntimeException
{
}
