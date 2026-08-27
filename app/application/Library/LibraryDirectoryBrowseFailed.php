<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/** 表示音乐库只读目录浏览被输入、文件边界或当前存储状态拒绝；错误正文不得包含任何物理路径。 */
final class LibraryDirectoryBrowseFailed extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
