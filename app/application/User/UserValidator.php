<?php

declare(strict_types=1);

namespace app\application\User;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validates administrator-created local accounts before database locking or password hashing.
 *
 * 用户名和密码规则与首次初始化保持一致。roleKey 只允许 administrator 和 listener 两个内置基础角色，
 * 服务端投放通过单独的 directCapabilities 绑定，不能通过请求创建超级管理员或选择未来未知系统角色。到期日规范化为该
 * UTC 自然日结束时间，确保各时区比较结果一致；校验失败不访问数据库、不散列密码，也没有写副作用。
 */
final class UserValidator
{
    /** 用户可直授的能力白名单；高风险管理能力仍只能由角色或超级管理员产生。 */
    private const DIRECT_CAPABILITIES = ['cast'];

    /** @param array<string, mixed> $payload Parsed untrusted JSON body. */
    public function validateCreate(array $payload): UserValidationResult
    {
        $username = trim($this->stringValue($payload, 'username'));
        $displayName = trim($this->stringValue($payload, 'displayName'));
        $email = trim($this->stringValue($payload, 'email'));
        $password = $this->stringValue($payload, 'password');
        $roleKey = trim($this->stringValue($payload, 'roleKey'));
        $locale = trim($this->stringValue($payload, 'locale')) ?: 'zh-CN';
        $timezone = trim($this->stringValue($payload, 'timezone')) ?: 'Asia/Shanghai';
        $expiry = trim($this->stringValue($payload, 'accountExpiresOn'));
        $errors = [];
        $directCapabilities = $this->directCapabilities($payload['directCapabilities'] ?? null, $errors);

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
        if (!in_array($roleKey, ['administrator', 'listener'], true)) {
            $errors['roleKey'][] = '请选择可分配的内置角色。';
        }
        if (!in_array($locale, ['zh-CN', 'en-US'], true)) {
            $errors['locale'][] = '暂不支持所选语言。';
        }
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'][] = '请输入有效的 IANA 时区。';
        }

        $accountExpiresAt = null;
        if ($expiry !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, new DateTimeZone('UTC'));
            $validDate = $date !== false && $date->format('Y-m-d') === $expiry;
            if (!$validDate) {
                $errors['accountExpiresOn'][] = '请输入有效的账户到期日期。';
            } elseif ($date->getTimestamp() < strtotime('today UTC')) {
                $errors['accountExpiresOn'][] = '账户到期日期不能早于今天。';
            } else {
                $accountExpiresAt = $date->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z');
            }
        }

        if ($errors !== []) {
            return new UserValidationResult(null, $errors);
        }

        return new UserValidationResult(new UserCreateInput(
            username: $username,
            displayName: $displayName,
            email: $email === '' ? null : $email,
            password: $password,
            roleKey: $roleKey,
            locale: $locale,
            timezone: $timezone,
            accountExpiresAt: $accountExpiresAt,
            directCapabilities: $directCapabilities,
        ), []);
    }

    /**
     * 校验后台编辑弹窗允许修改的账号资料和并发令牌。
     *
     * 请求不接受用户名、密码、状态、授权或限额。到期日仍按 UTC 自然日末规范化；roleKey 允许 null，
     * 但仅用于超级管理员或当前操作者不能改角色的投影，领域服务会结合目标身份再次判断。校验失败
     * 不访问数据库且没有写副作用，字段错误可直接映射回弹窗。
     *
     * @param array<string,mixed> $payload 未可信 JSON 请求对象
     */
    public function validateUpdate(array $payload): UserUpdateValidationResult
    {
        $displayName = trim($this->stringValue($payload, 'displayName'));
        $email = trim($this->stringValue($payload, 'email'));
        $locale = trim($this->stringValue($payload, 'locale'));
        $timezone = trim($this->stringValue($payload, 'timezone'));
        $expiry = trim($this->stringValue($payload, 'accountExpiresOn'));
        $roleKey = $payload['roleKey'] ?? null;
        $permissionVersion = $payload['expectedPermissionVersion'] ?? null;
        $profileVersion = $payload['expectedProfileVersion'] ?? null;
        $errors = [];
        $directCapabilities = $this->directCapabilities($payload['directCapabilities'] ?? null, $errors);

        if ($this->length($displayName) < 1 || $this->length($displayName) > 80) {
            $errors['displayName'][] = '显示名称需为 1-80 个字符。';
        }
        if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'][] = '请输入有效的邮箱地址。';
        }
        if (!in_array($locale, ['zh-CN', 'en-US'], true)) {
            $errors['locale'][] = '暂不支持所选语言。';
        }
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $errors['timezone'][] = '请输入有效的 IANA 时区。';
        }
        if ($roleKey !== null && (!is_string($roleKey)
            || !in_array($roleKey, ['administrator', 'listener'], true))) {
            $errors['roleKey'][] = '请选择可分配的内置角色。';
        }
        if (!is_int($permissionVersion) || $permissionVersion < 1) {
            $errors['expectedPermissionVersion'][] = '用户权限版本无效。';
        }
        if (!is_int($profileVersion) || $profileVersion < 1) {
            $errors['expectedProfileVersion'][] = '用户资料版本无效。';
        }

        $accountExpiresAt = null;
        if ($expiry !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, new DateTimeZone('UTC'));
            $validDate = $date !== false && $date->format('Y-m-d') === $expiry;
            if (!$validDate) {
                $errors['accountExpiresOn'][] = '请输入有效的账户到期日期。';
            } elseif ($date->getTimestamp() < strtotime('today UTC')) {
                $errors['accountExpiresOn'][] = '账户到期日期不能早于今天。';
            } else {
                $accountExpiresAt = $date->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z');
            }
        }

        if ($errors !== []) return new UserUpdateValidationResult(null, $errors);

        return new UserUpdateValidationResult(new UserUpdateInput(
            displayName: $displayName,
            email: $email === '' ? null : $email,
            locale: $locale,
            timezone: $timezone,
            accountExpiresAt: $accountExpiresAt,
            roleKey: is_string($roleKey) ? $roleKey : null,
            directCapabilities: $directCapabilities,
            expectedPermissionVersion: $permissionVersion,
            expectedProfileVersion: $profileVersion,
        ), []);
    }

    /** @param array<string, mixed> $payload */
    private function stringValue(array $payload, string $key): string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : '';
    }

    /**
     * 校验用户直授能力的精确数组结构。
     *
     * 目前只开放 cast，避免管理员借此接口绕过角色边界授予 manage_users、manage_system 或历史
     * upload_media 等能力。上传已经收口到具有 manage_storage 的后台，不再属于用户直授范围。列表
     * 去重并排序后进入事务，空列表表示撤销全部用户直授；非法输入不会访问数据库。
     *
     * @param array<string,list<string>> $errors
     * @return list<string>
     */
    private function directCapabilities(mixed $value, array &$errors): array
    {
        // 旧客户端未发送该可选字段时保持“无直授”，由 Controller 的新协议校验负责要求正式请求显式传值。
        if ($value === null) return [];
        if (!is_array($value) || !array_is_list($value)) {
            $errors['directCapabilities'][] = '用户权限格式无效。';
            return [];
        }
        $capabilities = [];
        foreach ($value as $capability) {
            if (!is_string($capability) || !in_array($capability, self::DIRECT_CAPABILITIES, true)) {
                $errors['directCapabilities'][] = '包含不可直接授予的权限。';
                continue;
            }
            $capabilities[] = $capability;
        }
        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);
        return $capabilities;
    }

    /** Counts user-visible characters with a safe fallback for minimal PHP installations. */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
