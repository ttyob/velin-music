<?php

declare(strict_types=1);

namespace app\infrastructure\Observability;

use Monolog\Formatter\JsonFormatter;
use Throwable;

/**
 * 为 Velin Music 后端生成带固定服务标识的单行结构化 JSON 日志（NFR-OPS-001）。
 *
 * 格式化器是最后一道防泄露边界：命中密码、令牌、Cookie、Authorization、密钥、明文正文或物理路径
 * 语义的 context 键会被替换为 `[redacted]`；Throwable 只保留类名，不序列化消息和堆栈。普通字符串
 * 限制为 512 字节，数组限制深度与元素数，避免外部响应或超大元数据撑满日志。调用点仍必须主动只传
 * request_id、job_id、error_code 和有界聚合值，不能把本格式化器当作允许记录秘密的理由。
 */
final class StructuredLogFormatter extends JsonFormatter
{
    private const REDACTED_KEYS = '/password|secret|token|cookie|authorization|credential|cipher|plaintext|body|path|filename/i';

    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_JSON, true, true, false);
    }

    /** 注入固定 service 字段并清洗 context/extra；时间、级别、频道和消息由 Monolog 标准结构保留。 */
    public function format(array $record): string
    {
        $record['context'] = $this->sanitize($record['context'] ?? [], 0);
        $record['extra'] = $this->sanitize($record['extra'] ?? [], 0);
        $record['extra']['service'] = 'velin-backend';
        return parent::format($record);
    }

    /**
     * 递归保留 JSON 安全标量；未知对象只输出类名，敏感键无论值类型都直接遮蔽。
     *
     * @return array<string|int,mixed>|bool|float|int|string|null
     */
    private function sanitize(mixed $value, int $depth): array|bool|float|int|string|null
    {
        if ($value instanceof Throwable) return ['exception_class' => $value::class];
        if (is_string($value)) return strlen($value) <= 512 ? $value : substr($value, 0, 512) . '[truncated]';
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
        if (!is_array($value) || $depth >= 3) return is_object($value) ? ['object_class' => $value::class] : null;
        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 32) break;
            $safeKey = is_int($key) ? $key : substr((string) $key, 0, 64);
            $result[$safeKey] = is_string($safeKey) && preg_match(self::REDACTED_KEYS, $safeKey) === 1
                ? '[redacted]' : $this->sanitize($item, $depth + 1);
        }
        return $result;
    }
}
