<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * Validates the one-time setup form before acquiring SQLite's write lock.
 *
 * Validation is deliberately deterministic and side-effect free. Username rules use ASCII
 * identifiers because SQLite NOCASE only guarantees ASCII case folding; accepting arbitrary
 * Unicode here would create inconsistent uniqueness behavior before the future MySQL migration.
 */
final class SetupValidator
{
    /**
     * Maps an untrusted JSON/form payload to a SetupInput.
     *
     * Unknown fields are ignored, passwords are capped to bound Argon2 input work, and validation
     * errors never include the submitted values. Password confirmation is checked here but is not
     * retained in the DTO.
     *
     * @param array<string, mixed> $payload Parsed request body.
     */
    public function validate(array $payload): SetupValidationResult
    {
        $username = trim($this->stringValue($payload, 'username'));
        $displayName = trim($this->stringValue($payload, 'displayName'));
        $email = trim($this->stringValue($payload, 'email'));
        $password = $this->stringValue($payload, 'password');
        $passwordConfirmation = $this->stringValue($payload, 'passwordConfirmation');
        $siteName = trim($this->stringValue($payload, 'siteName'));
        $locale = trim($this->stringValue($payload, 'locale')) ?: 'zh-CN';
        $timezone = trim($this->stringValue($payload, 'timezone')) ?: 'Asia/Shanghai';

        $errors = [];
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/', $username) !== 1) {
            $errors['username'][] = '用户名需为 3-64 位字母、数字、点、下划线或短横线。';
        }
        if ($this->length($displayName) < 1 || $this->length($displayName) > 80) {
            $errors['displayName'][] = '显示名称需为 1-80 个字符。';
        }
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'][] = '请输入有效的邮箱地址。';
        }
        if (strlen($password) < 8 || strlen($password) > 128) {
            $errors['password'][] = '密码长度需为 8-128 个字符。';
        }
        if ($password !== $passwordConfirmation) {
            $errors['passwordConfirmation'][] = '两次输入的密码不一致。';
        }
        if ($this->length($siteName) < 1 || $this->length($siteName) > 80) {
            $errors['siteName'][] = '站点名称需为 1-80 个字符。';
        }
        if (!in_array($locale, ['zh-CN', 'en-US'], true)) {
            $errors['locale'][] = '暂不支持所选语言。';
        }
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'][] = '请输入有效的 IANA 时区。';
        }

        if ($errors !== []) {
            return new SetupValidationResult(null, $errors);
        }

        return new SetupValidationResult(
            new SetupInput(
                username: $username,
                displayName: $displayName,
                email: $email === '' ? null : $email,
                password: $password,
                siteName: $siteName,
                locale: $locale,
                timezone: $timezone,
            ),
            [],
        );
    }

    /** @param array<string, mixed> $payload */
    private function stringValue(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }

    /** Counts user-visible characters while retaining a safe fallback without mbstring. */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
