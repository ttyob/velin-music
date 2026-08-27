<?php

declare(strict_types=1);

namespace app\application\SystemError;

/**
 * 校验异常记录 API 的有限筛选与乐观锁命令。
 *
 * 校验器不访问数据库。未知值在进入查询前失败，避免空值或近似拼写意外扩大系统错误范围；自由搜索
 * 仅允许无控制字符的短文本并由查询构造器绑定，不能成为 SQL 结构或日志正文。
 */
final class SystemErrorValidator
{
    /** 缺省只展示待处理错误；明确 `all` 才扩大到历史已解决记录。 */
    public function status(mixed $value): string
    {
        $status = is_string($value) && $value !== '' ? $value : 'open';
        if (!in_array($status, ['all', 'open', 'resolved'], true)) {
            throw new SystemErrorInvalid('Unsupported system error status.');
        }
        return $status;
    }

    /** 返回可选严重度，数据库只允许 error/critical。 */
    public function severity(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !in_array($value, ['error', 'critical'], true)) {
            throw new SystemErrorInvalid('Unsupported system error severity.');
        }
        return $value;
    }

    /** 返回可选来源；http 和 worker 不接受客户端自定义通道名。 */
    public function source(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !in_array($value, ['http', 'worker'], true)) {
            throw new SystemErrorInvalid('Unsupported system error source.');
        }
        return $value;
    }

    /** 状态命令只允许解决或重新打开，不提供浏览器删除诊断事实的能力。 */
    public function commandStatus(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['open', 'resolved'], true)) {
            throw new SystemErrorInvalid('Unsupported system error command status.');
        }
        return $value;
    }

    /** 校验不透明记录 ULID；非法格式按不存在处理，避免探测内部主键规则。 */
    public function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SystemErrorNotFound('System error record not found.');
        }
        return $value;
    }

    /** 返回非负版本；状态更新必须使用页面刚读取的快照。 */
    public function version(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new SystemErrorInvalid('System error version is invalid.');
        }
        return (int) $value;
    }

    /** 返回最多 100 字符的可选搜索词，控制字符和无效 UTF-8 直接拒绝。 */
    public function search(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new SystemErrorInvalid('System error search is invalid.');
        }
        $search = trim($value);
        if ($search === '' || strlen($search) > 300) {
            throw new SystemErrorInvalid('System error search is outside the supported range.');
        }
        return $search;
    }

    /** 返回有界分页整数；只有缺省值使用调用方默认值。 */
    public function page(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') return $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new SystemErrorInvalid('System error pagination must be an integer.');
        }
        $number = (int) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new SystemErrorInvalid('System error pagination is outside the supported range.');
        }
        return $number;
    }
}
