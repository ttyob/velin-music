<?php

declare(strict_types=1);

namespace app\application\Library;

/** 目标路径已被不同内容占用；调用方可以改用确定性冲突名，但绝不能覆盖。 */
final class RemotePublicationConflict extends RemoteLibraryUnavailable
{
    public function __construct()
    {
        parent::__construct('REMOTE_PUBLICATION_TARGET_EXISTS', '远程音乐库目标文件已存在。');
    }
}
