<?php

declare(strict_types=1);

namespace app\application\Scan;

/** Validates the public scan vocabulary before authorization-sensitive database work. */
final class ScanValidator
{
    /** @param array<string, mixed> $payload Parsed untrusted request body. */
    public function validateCreate(array $payload): ScanValidationResult
    {
        $scanType = is_string($payload['scanType'] ?? null) ? trim($payload['scanType']) : '';
        if (!in_array($scanType, ['incremental', 'full'], true)) {
            return new ScanValidationResult(null, [
                'scanType' => ['扫描类型必须是增量扫描或强制全量扫描。'],
            ]);
        }

        return new ScanValidationResult(new ScanCreateInput($scanType), []);
    }
}
