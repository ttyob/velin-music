<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * 初始化后由已认证超级管理员提交的默认音乐库配置。
 *
 * 三个字段必须来自同一次受 CSRF 保护的请求：目录决定扫描边界，派生资源策略决定是否需要媒体目录
 * 写权限，扫描模式决定后续自动化入口。对象只承载已通过白名单校验的值，不读取目录或写数据库。
 */
final readonly class SetupLibraryInput
{
    public function __construct(
        public string $rootPath,
        public string $scrapeStorageMode,
        public string $scanMode,
    ) {
    }
}
