<?php

declare(strict_types=1);

namespace app\application\Scan;

/** 在进入授权敏感的数据库操作前校验公开扫描词汇与库内相对目录。 */
final class ScanValidator
{
    /**
     * @param array<string, mixed> $payload 未信任请求正文
     *
     * 目录为空或缺省表示整库；非空目录必须是 UTF-8、最多 768 字节，不含点段、空段、
     * 反斜杠、绝对路径或控制字符。全量扫描拒绝目录范围，避免调用方误以为它会只重建
     * 子树。失败只返回字段错误，不触碰权限、任务或文件系统。
     */
    public function validateCreate(array $payload): ScanValidationResult
    {
        $scanType = is_string($payload['scanType'] ?? null) ? trim($payload['scanType']) : '';
        if (!in_array($scanType, ['incremental', 'full'], true)) {
            return new ScanValidationResult(null, [
                'scanType' => ['扫描类型必须是增量扫描或强制全量扫描。'],
            ]);
        }

        $rawPath = $payload['relativePath'] ?? null;
        if ($rawPath !== null && !is_string($rawPath)) {
            return new ScanValidationResult(null, ['relativePath' => ['扫描目录格式无效。']]);
        }
        $relativePath = is_string($rawPath) ? trim($rawPath) : '';
        if ($relativePath !== '' && !$this->validRelativePath($relativePath)) {
            return new ScanValidationResult(null, ['relativePath' => ['扫描目录必须是音乐库内的有效相对目录。']]);
        }
        if ($scanType === 'full' && $relativePath !== '') {
            return new ScanValidationResult(null, ['relativePath' => ['全量扫描不能指定子目录。']]);
        }

        return new ScanValidationResult(new ScanCreateInput($scanType, $relativePath === '' ? null : $relativePath), []);
    }

    private function validRelativePath(string $path): bool
    {
        if (strlen($path) > 768 || !mb_check_encoding($path, 'UTF-8')
            || str_contains($path, '\\') || str_starts_with($path, '/') || str_ends_with($path, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return false;
        }
        return true;
    }
}
