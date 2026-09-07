<?php

declare(strict_types=1);

namespace app\application\Auth;

/** 初始化后由已认证超级管理员提交的默认音乐库目录。 */
final readonly class SetupLibraryInput
{
    public function __construct(public string $rootPath)
    {
    }
}
