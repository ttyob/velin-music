<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * 校验首次默认音乐库配置的请求格式。
 *
 * 这里只做无副作用的字符串与枚举检查；目录存在性、真实路径、权限和缓存隔离由 SetupService 在
 * 事务前后复验。三个字段都必须显式提交，避免旧页面看似完成初始化、实际却悄悄采用未知默认值。
 * 禁止相对路径和 NUL，避免把路径解析责任交给客户端。
 */
final class SetupLibraryValidator
{
    /** @param array<string,mixed> $payload */
    public function validate(array $payload): SetupLibraryValidationResult
    {
        $allowed = ['rootPath', 'scrapeStorageMode', 'scanMode'];
        $errors = array_diff(array_keys($payload), $allowed) === [] ? [] : ['request' => ['请求包含不支持的字段。']];
        $rootPath = is_string($payload['rootPath'] ?? null) ? trim($payload['rootPath']) : '';
        $scrapeStorageMode = is_string($payload['scrapeStorageMode'] ?? null)
            ? trim($payload['scrapeStorageMode']) : '';
        $scanMode = is_string($payload['scanMode'] ?? null) ? trim($payload['scanMode']) : '';
        if ($rootPath === '' || strlen($rootPath) > 4096 || !str_starts_with($rootPath, '/') || str_contains($rootPath, "\0")) {
            $errors['rootPath'][] = '请输入服务端可见的绝对音乐库目录。';
        }
        if (!in_array($scrapeStorageMode, ['managed_cache', 'adjacent'], true)) {
            $errors['scrapeStorageMode'][] = '请选择独立缓存或音乐同目录模式。';
        }
        if (!in_array($scanMode, ['manual', 'scheduled', 'watch'], true)) {
            $errors['scanMode'][] = '请选择手动、计划或监听扫描模式。';
        }
        return new SetupLibraryValidationResult(
            $errors === [] ? new SetupLibraryInput($rootPath, $scrapeStorageMode, $scanMode) : null,
            $errors,
        );
    }
}
