<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 在不接触文件系统的前提下校验单目录音乐库输入。
 *
 * 目录存在性、可写性和真实路径包含关系交给 LibraryPathInspector，并在创建事务取得写保留后再次
 * 校验。本类只限制字符串与策略白名单，同时严格拒绝旧待入库字段，避免过期客户端继续创建双阶段
 * 配置。逻辑失败不执行文件探测，也不获取 SQLite 写锁。
 */
final class LibraryValidator
{
    /**
     * @param array<string, mixed> $payload Parsed untrusted JSON input.
     * @param bool $allowMissingRemoteSecret 仅用于更新既有网络库；缺失表示保留密文，不表示空秘密。
     */
    public function validateCreate(array $payload, bool $allowMissingRemoteSecret = false): LibraryValidationResult
    {
        $name = trim($this->stringValue($payload, 'name'));
        $rootPath = trim($this->stringValue($payload, 'rootPath'));
        $sourceType = trim($this->stringValue($payload, 'sourceType')) ?: 'local';
        $scrapeStorageMode = trim($this->stringValue($payload, 'scrapeStorageMode')) ?: 'managed_cache';
        $symlinkPolicy = trim($this->stringValue($payload, 'symlinkPolicy')) ?: 'ignore';
        $defaultLocale = trim($this->stringValue($payload, 'defaultLocale')) ?: 'zh-CN';
        $scanMode = trim($this->stringValue($payload, 'scanMode')) ?: 'manual';
        $remoteMetadataMode = trim($this->stringValue($payload, 'remoteMetadataMode')) ?: 'filename_only';
        $useProxy = $payload['useProxy'] ?? false;
        $proxyProfileId = $payload['proxyProfileId'] ?? null;
        $errors = [];

        $allowed = ['name', 'sourceType', 'rootPath', 'scrapeStorageMode', 'symlinkPolicy', 'defaultLocale',
            'scanMode', 'webDavBaseUrl', 'webDavUsername', 'webDavPassword', 'webDavVerifyTls',
            'oneDriveTenantId', 'oneDriveClientId', 'oneDriveAuthorizationId', 'remoteMetadataMode',
            'useProxy', 'proxyProfileId'];
        $allowed[] = 'googleDriveAuthorizationId';
        $allowed[] = 'googleDriveId';
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $errors['request'][] = '请求包含单目录音乐库不支持的字段。';
                break;
            }
        }

        if ($this->length($name) < 1 || $this->length($name) > 80) {
            $errors['name'][] = '音乐库名称需为 1-80 个字符。';
        }
        if (!in_array($sourceType, ['local', 'webdav', 'onedrive', 'google_drive'], true)) {
            $errors['sourceType'][] = '请选择本地、WebDAV、OneDrive 企业版或 Google Drive 音乐库。';
        }
        if ($rootPath === '' || strlen($rootPath) > 4096 || str_contains($rootPath, "\0")) {
            $errors['rootPath'][] = $sourceType === 'local' ? '请输入有效的音乐库绝对目录路径。' : '请输入有效的远端根路径。';
        }
        if (!in_array($scrapeStorageMode, ['managed_cache', 'adjacent'], true)) {
            $errors['scrapeStorageMode'][] = '请选择独立缓存或媒体同目录模式。';
        }
        if (!in_array($symlinkPolicy, ['ignore', 'within_root'], true)) {
            $errors['symlinkPolicy'][] = '请选择受支持的符号链接策略。';
        }
        if (!in_array($defaultLocale, ['zh-CN', 'en-US'], true)) {
            $errors['defaultLocale'][] = '暂不支持所选默认语言。';
        }
        if (!in_array($scanMode, ['manual', 'scheduled', 'watch'], true)) {
            $errors['scanMode'][] = '请选择受支持的扫描模式。';
        }
        if ($sourceType !== 'local'
            && !in_array($remoteMetadataMode, ['filename_only', 'range_probe'], true)) {
            $errors['remoteMetadataMode'][] = '请选择仅文件名或 Range 元数据探测模式。';
        }
        if (!is_bool($useProxy)
            || ($useProxy && (!is_string($proxyProfileId) || !$this->ulid($proxyProfileId)))
            || (!$useProxy && $proxyProfileId !== null)) {
            $errors['proxyProfileId'][] = '开启代理后必须选择一个有效代理；关闭时必须保持直连。';
        }
        $webDavBaseUrl = null;
        $webDavUsername = null;
        $webDavPassword = null;
        $webDavVerifyTls = true;
        if ($sourceType === 'webdav') {
            $webDavBaseUrl = $this->webDavBaseUrl($payload['webDavBaseUrl'] ?? null, $errors);
            $webDavUsername = trim($this->stringValue($payload, 'webDavUsername'));
            $passwordProvided = array_key_exists('webDavPassword', $payload);
            $webDavPassword = $passwordProvided && is_string($payload['webDavPassword'])
                ? $payload['webDavPassword'] : null;
            if ($webDavUsername === '' || strlen($webDavUsername) > 255 || str_contains($webDavUsername, "\0")) {
                $errors['webDavUsername'][] = 'WebDAV 用户名需为 1-255 个字符。';
            }
            if ((!$allowMissingRemoteSecret || $passwordProvided)
                && (!is_string($webDavPassword) || $webDavPassword === '' || strlen($webDavPassword) > 1024)) {
                $errors['webDavPassword'][] = '请输入有效的 WebDAV 密码。';
            }
            if (!is_bool($payload['webDavVerifyTls'] ?? true)) {
                $errors['webDavVerifyTls'][] = 'TLS 验证设置无效。';
            } else {
                $webDavVerifyTls = (bool) ($payload['webDavVerifyTls'] ?? true);
            }
            if (!$this->validRemoteRoot($rootPath)) $errors['rootPath'][] = 'WebDAV 根路径必须是无点段的绝对路径。';
            if ($scrapeStorageMode !== 'managed_cache') $errors['scrapeStorageMode'][] = 'WebDAV 音乐库只能使用独立缓存目录。';
            if ($symlinkPolicy !== 'ignore') $errors['symlinkPolicy'][] = 'WebDAV 音乐库不支持符号链接策略。';
            if ($scanMode === 'watch') $errors['scanMode'][] = 'WebDAV 音乐库不支持文件监听扫描。';
            foreach (['oneDriveTenantId', 'oneDriveClientId', 'oneDriveAuthorizationId'] as $field) {
                if (array_key_exists($field, $payload)) $errors['request'][] = 'WebDAV 音乐库不能包含 OneDrive 连接字段。';
            }
            foreach (['googleDriveAuthorizationId', 'googleDriveId'] as $field) {
                if (array_key_exists($field, $payload)) $errors['request'][] = 'WebDAV 音乐库不能包含 Google Drive 连接字段。';
            }
        } elseif ($sourceType === 'onedrive') {
            $tenantId = strtolower(trim($this->stringValue($payload, 'oneDriveTenantId')));
            $clientId = strtolower(trim($this->stringValue($payload, 'oneDriveClientId')));
            $authorizationProvided = array_key_exists('oneDriveAuthorizationId', $payload);
            $authorizationId = $authorizationProvided && is_string($payload['oneDriveAuthorizationId'])
                ? trim($payload['oneDriveAuthorizationId']) : null;
            if (!$this->uuid($tenantId)) $errors['oneDriveTenantId'][] = '请输入有效的 Microsoft Entra 租户 ID。';
            if (!$this->uuid($clientId)) $errors['oneDriveClientId'][] = '请输入有效的应用（客户端）ID。';
            if ((!$allowMissingRemoteSecret || $authorizationProvided)
                && (!is_string($authorizationId) || !$this->ulid($authorizationId))) {
                $errors['oneDriveAuthorizationId'][] = '请先完成 Microsoft 授权。';
            }
            if (!$this->validRemoteRoot($rootPath)) $errors['rootPath'][] = 'OneDrive 根路径必须是无点段的绝对路径。';
            if ($scrapeStorageMode !== 'managed_cache') $errors['scrapeStorageMode'][] = 'OneDrive 音乐库只能使用独立缓存目录。';
            if ($symlinkPolicy !== 'ignore') $errors['symlinkPolicy'][] = 'OneDrive 音乐库不支持符号链接策略。';
            if ($scanMode === 'watch') $errors['scanMode'][] = 'OneDrive 音乐库不支持文件监听扫描。';
            foreach (['webDavBaseUrl', 'webDavUsername', 'webDavPassword', 'webDavVerifyTls'] as $field) {
                if (array_key_exists($field, $payload)) $errors['request'][] = 'OneDrive 音乐库不能包含 WebDAV 连接字段。';
            }
            foreach (['googleDriveAuthorizationId', 'googleDriveId'] as $field) {
                if (array_key_exists($field, $payload)) $errors['request'][] = 'OneDrive 音乐库不能包含 Google Drive 连接字段。';
            }
        } elseif ($sourceType === 'google_drive') {
            $authorizationProvided = array_key_exists('googleDriveAuthorizationId', $payload);
            $googleAuthorizationId = $authorizationProvided && is_string($payload['googleDriveAuthorizationId'])
                ? trim($payload['googleDriveAuthorizationId']) : null;
            $googleDriveId = trim($this->stringValue($payload, 'googleDriveId')) ?: 'root';
            if ((!$allowMissingRemoteSecret || $authorizationProvided)
                && (!is_string($googleAuthorizationId) || !$this->ulid($googleAuthorizationId))) {
                $errors['googleDriveAuthorizationId'][] = '请先完成 Google 授权。';
            }
            if ($googleDriveId !== 'root'
                && preg_match('/^[A-Za-z0-9_-]{8,256}$/', $googleDriveId) !== 1) {
                $errors['googleDriveId'][] = 'Shared Drive ID 无效；我的云端硬盘请留空。';
            }
            if (!$this->validRemoteRoot($rootPath)) $errors['rootPath'][] = 'Google Drive 根路径必须是无点段的绝对路径。';
            if ($scrapeStorageMode !== 'managed_cache') $errors['scrapeStorageMode'][] = 'Google Drive 音乐库只能使用独立缓存目录。';
            if ($symlinkPolicy !== 'ignore') $errors['symlinkPolicy'][] = 'Google Drive 音乐库不支持符号链接策略。';
            if ($scanMode === 'watch') $errors['scanMode'][] = 'Google Drive 音乐库不支持文件监听扫描。';
            foreach (['webDavBaseUrl', 'webDavUsername', 'webDavPassword', 'webDavVerifyTls',
                'oneDriveTenantId', 'oneDriveClientId', 'oneDriveAuthorizationId'] as $field) {
                if (array_key_exists($field, $payload)) $errors['request'][] = 'Google Drive 音乐库不能包含其他来源连接字段。';
            }
        } else {
            foreach (['webDavBaseUrl', 'webDavUsername', 'webDavPassword', 'webDavVerifyTls',
                'oneDriveTenantId', 'oneDriveClientId', 'oneDriveAuthorizationId',
                'googleDriveAuthorizationId', 'googleDriveId', 'remoteMetadataMode'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $errors['request'][] = '本地音乐库不能包含远端连接字段。';
                    break;
                }
            }
            if ($useProxy || $proxyProfileId !== null) $errors['request'][] = '本地音乐库不能配置网络代理。';
        }

        if ($errors !== []) {
            return new LibraryValidationResult(null, $errors);
        }

        return new LibraryValidationResult(new LibraryCreateInput(
            name: $name,
            rootPath: $rootPath,
            scrapeStorageMode: $scrapeStorageMode,
            symlinkPolicy: $symlinkPolicy,
            defaultLocale: $defaultLocale,
            scanMode: $scanMode,
            sourceType: $sourceType,
            remoteMetadataMode: $remoteMetadataMode,
            webDavBaseUrl: $webDavBaseUrl,
            webDavUsername: $webDavUsername,
            webDavPassword: $webDavPassword,
            webDavVerifyTls: $webDavVerifyTls,
            oneDriveTenantId: $tenantId ?? null,
            oneDriveClientId: $clientId ?? null,
            oneDriveAuthorizationId: $authorizationId ?? null,
            googleDriveAuthorizationId: $googleAuthorizationId ?? null,
            googleDriveId: $googleDriveId ?? 'root',
            useProxy: $useProxy,
            proxyProfileId: is_string($proxyProfileId) ? $proxyProfileId : null,
        ), []);
    }

    /**
     * Validates an optimistic edit using a strict command-specific field allowlist.
     *
     * 默认库只接受刮削资源模式、符号链接、扫描模式和期望版本。接受后忽略多余字段会让受损或过期
     * 页面误以为已经修改受保护身份和路径，因此必须失败关闭。自定义库使用完整替换并复用创建约束。
     *
     * @param array<string, mixed> $payload Parsed untrusted JSON input.
     */
    public function validateUpdate(
        array $payload,
        bool $defaultOnly,
        string $currentSourceType = 'local',
    ): LibraryUpdateValidationResult
    {
        $allowed = $defaultOnly
            ? ['scrapeStorageMode', 'symlinkPolicy', 'scanMode', 'expectedVersion']
            : ['name', 'sourceType', 'rootPath', 'scrapeStorageMode', 'symlinkPolicy', 'defaultLocale',
                'scanMode', 'webDavBaseUrl', 'webDavUsername', 'webDavPassword', 'webDavVerifyTls',
                'oneDriveTenantId', 'oneDriveClientId', 'oneDriveAuthorizationId', 'remoteMetadataMode',
                'googleDriveAuthorizationId', 'googleDriveId',
                'useProxy', 'proxyProfileId',
                'expectedVersion'];
        $errors = [];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $errors['request'][] = '请求包含当前音乐库不允许修改的字段。';
                break;
            }
        }
        $expectedVersion = $this->integerValue($payload, 'expectedVersion', 0);
        if ($expectedVersion < 1) {
            $errors['expectedVersion'][] = '音乐库版本无效，请刷新后重试。';
        }
        $scanMode = trim($this->stringValue($payload, 'scanMode'));
        $scrapeStorageMode = trim($this->stringValue($payload, 'scrapeStorageMode'));
        if (!in_array($scanMode, ['manual', 'scheduled', 'watch'], true)) {
            $errors['scanMode'][] = '请选择受支持的扫描模式。';
        }
        if (!in_array($scrapeStorageMode, ['managed_cache', 'adjacent'], true)) {
            $errors['scrapeStorageMode'][] = '请选择独立缓存或媒体同目录模式。';
        }
        if ($defaultOnly) {
            $symlinkPolicy = trim($this->stringValue($payload, 'symlinkPolicy'));
            if (!in_array($symlinkPolicy, ['ignore', 'within_root'], true)) {
                $errors['symlinkPolicy'][] = '请选择受支持的符号链接策略。';
            }
            return $errors === []
                ? new LibraryUpdateValidationResult(new LibraryUpdateInput(
                    expectedVersion: $expectedVersion,
                    scanMode: $scanMode,
                    scrapeStorageMode: $scrapeStorageMode,
                    symlinkPolicy: $symlinkPolicy,
                    defaultOnly: true,
                ), [])
                : new LibraryUpdateValidationResult(null, $errors);
        }

        $replacement = $payload;
        unset($replacement['expectedVersion']);
        $create = $this->validateCreate($replacement, $currentSourceType !== 'local');
        if (!$create->isValid() || $create->input === null) {
            $errors = array_merge($errors, $create->errors);
        }
        if ($errors !== [] || $create->input === null) {
            return new LibraryUpdateValidationResult(null, $errors);
        }
        $input = $create->input;

        return new LibraryUpdateValidationResult(new LibraryUpdateInput(
            expectedVersion: $expectedVersion,
            scanMode: $input->scanMode,
            scrapeStorageMode: $input->scrapeStorageMode,
            symlinkPolicy: $input->symlinkPolicy,
            name: $input->name,
            rootPath: $input->rootPath,
            defaultLocale: $input->defaultLocale,
            sourceType: $input->sourceType,
            remoteMetadataMode: $input->remoteMetadataMode,
            webDavBaseUrl: $input->webDavBaseUrl,
            webDavUsername: $input->webDavUsername,
            webDavPassword: $input->webDavPassword,
            webDavVerifyTls: $input->webDavVerifyTls,
            oneDriveTenantId: $input->oneDriveTenantId,
            oneDriveClientId: $input->oneDriveClientId,
            oneDriveAuthorizationId: $input->oneDriveAuthorizationId,
            googleDriveAuthorizationId: $input->googleDriveAuthorizationId,
            googleDriveId: $input->googleDriveId,
            useProxy: $input->useProxy,
            proxyProfileId: $input->proxyProfileId,
        ), []);
    }

    /** @param array<string, mixed> $payload */
    private function stringValue(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }

    /** Reads a strict decimal integer without accepting floats, booleans, or partially numeric text. */
    private function integerValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return PHP_INT_MIN;
    }

    /** Counts visible name characters with a fallback for minimal PHP installations. */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** Entra 租户与应用标识只接受 UUID，避免把别名或 URL 带入固定认证端点。 */
    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** 一次性授权标识只接受规范 ULID；具体 actor、状态和消费边界由服务层数据库校验。 */
    private function ulid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    /** @param array<string,list<string>> $errors */
    private function webDavBaseUrl(mixed $value, array &$errors): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048 || str_contains($value, "\0")) {
            $errors['webDavBaseUrl'][] = '请输入有效的 WebDAV HTTP(S) 地址。';
            return null;
        }
        $parts = parse_url(trim($value));
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $errors['webDavBaseUrl'][] = 'WebDAV 地址必须是无凭据、查询参数和片段的 HTTP(S) 地址。';
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . $port . $path;
    }

    /** WebDAV 根只允许标准绝对路径片段；URL 编码由客户端在最后一步逐段完成。 */
    private function validRemoteRoot(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) return false;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') continue;
            if ($segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F]/u', $segment) === 1) return false;
        }
        return true;
    }
}
