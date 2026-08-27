<?php

declare(strict_types=1);

namespace app\application\Notification;

/**
 * 在通知查询和命令进入 SQL 结构前校验其有限词汇。
 *
 * 所有值只从固定白名单返回；未知筛选直接失败，不能静默扩大收件箱范围。分页使用硬上限，防止客户端
 * 请求无界用户通知。校验器不访问数据库、不做兼容回退，异常由控制器映射为稳定 422/404。
 */
final class NotificationValidator
{
    /** 返回 `all` 或 `unread`；缺省和空值明确使用全部通知，不接受近似拼写。 */
    public function status(mixed $value): string
    {
        $status = is_string($value) && $value !== '' ? $value : 'all';
        if (!in_array($status, ['all', 'unread'], true)) {
            throw new NotificationInvalid('Unsupported notification status.');
        }

        return $status;
    }

    /** 从持久 schema 白名单返回可选严重度；未知值在生成 SQL 前拒绝。 */
    public function severity(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, ['info', 'warning', 'error', 'critical'], true)) {
            throw new NotificationInvalid('Unsupported notification severity.');
        }

        return $value;
    }

    /** 从完整持久通知词汇返回可选类型，包含只属于后台的刮削待确认事件。 */
    public function optionalType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, [
            'scan', 'scrape', 'security', 'permission', 'storage', 'destructive',
        ], true)) {
            throw new NotificationInvalid('Unsupported notification type.');
        }

        return $value;
    }

    /** 校验唯一可变静音偏好类型；不可静音的安全和运维域始终失败关闭。 */
    public function type(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['scan'], true)) {
            throw new NotificationInvalid('Unsupported notification type.');
        }

        return $value;
    }

    /** 校验不透明通知 ULID；格式错误与不存在使用同一异常，避免枚举。 */
    public function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new NotificationNotFound('Notification not found.');
        }

        return $value;
    }

    /** 只接受真实 JSON 布尔值，明确拒绝数字和字符串真值，避免意外静音。 */
    public function muted(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new NotificationInvalid('Muted must be boolean.');
        }

        return $value;
    }

    /** 返回有界整数；仅缺省时使用调用方默认值，越界和非整数都拒绝。 */
    public function page(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new NotificationInvalid('Pagination must be an integer.');
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new NotificationInvalid('Pagination is outside the supported range.');
        }

        return $integer;
    }
}
