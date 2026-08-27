<?php

declare(strict_types=1);

namespace app\application\Account;

use DateTimeZone;

/**
 * 校验当前账号自助资料与改密命令的有界公开输入。
 *
 * 本类不读取数据库和 Session；调用者必须先从已复验 Session 获取身份。自由文本在进入事务前完成
 * UTF-8 长度、邮箱和时区白名单检查，密码只在请求内存中短暂存在且绝不能进入日志或异常消息。
 */
final class SelfAccountValidator
{
    /**
     * @param array<string, mixed> $payload 已解析的 JSON 对象
     * @return array{displayName: string, email: ?string, timezone: string, expectedVersion: int}
     */
    public function profile(array $payload): array
    {
        $displayName = is_string($payload['displayName'] ?? null) ? trim($payload['displayName']) : '';
        $emailInput = $payload['email'] ?? null;
        $email = is_string($emailInput) && trim($emailInput) !== '' ? strtolower(trim($emailInput)) : null;
        $timezone = $payload['timezone'] ?? null;
        $expectedVersion = $payload['expectedVersion'] ?? null;

        if ($displayName === '' || mb_strlen($displayName) > 100) {
            throw new SelfAccountInvalid('displayName is invalid.');
        }
        if ($emailInput !== null && !is_string($emailInput)) {
            throw new SelfAccountInvalid('email is invalid.');
        }
        if ($email !== null && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new SelfAccountInvalid('email is invalid.');
        }
        if (!is_string($timezone) || strlen($timezone) > 64 || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new SelfAccountInvalid('timezone is invalid.');
        }
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new SelfAccountInvalid('expectedVersion is invalid.');
        }

        return compact('displayName', 'email', 'timezone', 'expectedVersion');
    }

    /**
     * @param array<string, mixed> $payload 已解析的 JSON 对象
     * @return array{currentPassword: string, newPassword: string}
     */
    public function password(array $payload): array
    {
        $currentPassword = $payload['currentPassword'] ?? null;
        $newPassword = $payload['newPassword'] ?? null;
        if (!is_string($currentPassword) || strlen($currentPassword) < 1 || strlen($currentPassword) > 1024) {
            throw new SelfAccountInvalid('currentPassword is invalid.');
        }
        if (!is_string($newPassword) || strlen($newPassword) < 8 || strlen($newPassword) > 1024) {
            throw new SelfAccountInvalid('newPassword is invalid.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new SelfAccountInvalid('newPassword must differ.');
        }

        return compact('currentPassword', 'newPassword');
    }
}
