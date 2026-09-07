<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 承载经过逻辑校验的单目录音乐库设置，供文件系统与重叠检查继续复验。
 *
 * 本地库的 `rootPath` 是服务进程可见的路径（Docker 中必须先挂载进容器）；网络库中它是远端根路径且必须与固定连接身份组合后才能访问。
 * WebDAV 密码只在请求调用栈内存在；OneDrive 使用绑定 actor 且只能消费一次的已完成授权 ID，管理服务
 * 会在测试连接后把服务端 refresh token 密文转入连接行。秘密绝不能进入投影或审计。
 * 网络库普通媒体操作固定只读、独立缓存和忽略符号链接；资源插件上传只能另经统一发布账本执行。
 * remoteMetadataMode 决定扫描是否允许读取音频 Range，
 * 默认 `filename_only` 必须完全跳过正文。调用方不得把本 DTO 当成连接或对象身份仍有效的证明。
 */
final readonly class LibraryCreateInput
{
    public function __construct(
        public string $name,
        public string $rootPath,
        public string $scrapeStorageMode,
        public string $symlinkPolicy,
        public string $defaultLocale,
        public string $scanMode,
        public string $sourceType = 'local',
        public string $remoteMetadataMode = 'filename_only',
        public ?string $webDavBaseUrl = null,
        public ?string $webDavUsername = null,
        public ?string $webDavPassword = null,
        public bool $webDavVerifyTls = true,
        public ?string $oneDriveTenantId = null,
        public ?string $oneDriveClientId = null,
        public ?string $oneDriveAuthorizationId = null,
        public ?string $googleDriveAuthorizationId = null,
        public string $googleDriveId = 'root',
        public bool $useProxy = false,
        public ?string $proxyProfileId = null,
    ) {
    }
}
