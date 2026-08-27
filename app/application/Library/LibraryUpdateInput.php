<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * Carries a validated optimistic library update.
 *
 * 默认库只允许修改刮削资源、符号链接和扫描策略；身份、路径及语言保持为空，服务层会拒绝夹带受
 * 保护字段。自定义库使用完整替换，网络库的远端元数据模式也必须明确冻结，避免旧页面静默恢复
 * 会访问音频正文的 Range 探测。
 */
final readonly class LibraryUpdateInput
{
    public function __construct(
        public int $expectedVersion,
        public string $scanMode,
        public string $scrapeStorageMode,
        public ?string $symlinkPolicy = null,
        public ?string $name = null,
        public ?string $rootPath = null,
        public ?string $defaultLocale = null,
        public bool $defaultOnly = false,
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
