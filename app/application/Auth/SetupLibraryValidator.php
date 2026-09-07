<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * 校验首次默认音乐库目录的请求格式。
 *
 * 这里只做无副作用的字符串检查；目录存在性、真实路径、权限和缓存隔离由 SetupService 在事务前后
 * 复验。禁止相对路径和 NUL，避免把路径解析责任交给客户端。
 */
final class SetupLibraryValidator
{
    /** @param array<string,mixed> $payload */
    public function validate(array $payload): SetupLibraryValidationResult
    {
        $allowed = ['rootPath'];
        $errors = array_diff(array_keys($payload), $allowed) === [] ? [] : ['request' => ['请求包含不支持的字段。']];
        $rootPath = is_string($payload['rootPath'] ?? null) ? trim($payload['rootPath']) : '';
        if ($rootPath === '' || strlen($rootPath) > 4096 || !str_starts_with($rootPath, '/') || str_contains($rootPath, "\0")) {
            $errors['rootPath'][] = '请输入服务端可见的绝对音乐库目录。';
        }
        return new SetupLibraryValidationResult(
            $errors === [] ? new SetupLibraryInput($rootPath) : null,
            $errors,
        );
    }
}
