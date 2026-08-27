<?php

declare(strict_types=1);

namespace app\application\Upload;

use RuntimeException;

/** 表示上传清单、分片、哈希、相对路径或状态命令不符合固定协议。 */
final class UploadInvalid extends RuntimeException
{
}
