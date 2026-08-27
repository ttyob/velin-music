<?php

declare(strict_types=1);

namespace app\application\Preference;

/**
 * 在数据库操作前校验当前账号可自行修改的有界偏好命令。
 *
 * 语言枚举与 Web 客户端随版本发布的语言包一致，主题命令则严格拒绝旧显示模式和未知字段。校验器
 * 不读取账号、不判断权限，也不修改状态；身份边界和主题发布状态分别由 Controller 与领域服务复核。
 * 所有标量保持 JSON 原生类型，数字字符串和任意 BCP-47 标签不能进入账号状态。
 */
final class UserPreferenceValidator
{
    /**
     * 校验语言和共享偏好乐观版本。
     *
     * 输入来自已认证请求解析后的 JSON 对象，只允许随客户端发布的 `zh-CN|en-US`，不执行隐式大小写
     * 或地区回退。字段缺失、类型错误和未知语言均在事务前失败，不产生数据库或审计副作用。
     *
     * @param array<string, mixed> $payload 已认证请求解析后的 JSON 对象。
     * @return array{expectedVersion: int, locale: string} 已校验的乐观锁命令。
     * @throws UserPreferenceInvalid 缺失版本、类型错误或语言不受支持。
     */
    public function locale(array $payload): array
    {
        $expectedVersion = $payload['expectedVersion'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new UserPreferenceInvalid('expectedVersion must be a positive integer.');
        }

        $locale = $payload['locale'] ?? null;
        if (!is_string($locale) || !in_array($locale, ['zh-CN', 'en-US'], true)) {
            throw new UserPreferenceInvalid('locale is not supported.');
        }

        return ['expectedVersion' => $expectedVersion, 'locale' => $locale];
    }

    /**
     * 校验仅包含基础色主题和乐观版本的选择命令（API-THEME-003）。
     *
     * 请求必须精确包含 `themeId` 与 `expectedVersion`；历史 `mode` 和其他未知字段直接拒绝，避免客户端
     * 误以为浅色或系统模式仍会生效。主题是否仍发布由服务在写事务前实时复核，失效主题不能进入账号
     * 状态。该方法不读库、不修改偏好。
     *
     * @return array{expectedVersion: int, themeId: string}
     */
    public function theme(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['expectedVersion', 'themeId']) {
            throw new UserPreferenceInvalid('Theme command contains unsupported fields.');
        }
        $expectedVersion = $payload['expectedVersion'] ?? null;
        $themeId = $payload['themeId'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new UserPreferenceInvalid('expectedVersion must be a positive integer.');
        }
        if (!is_string($themeId)
            || preg_match('/^(?:[a-z][a-z0-9-]{1,31}|[0-9A-HJKMNP-TV-Z]{26})$/', $themeId) !== 1) {
            throw new UserPreferenceInvalid('Theme ID is invalid.');
        }

        return ['expectedVersion' => $expectedVersion, 'themeId' => $themeId];
    }

    /** 校验账号级减少动效开关；只接受 JSON boolean 和共享正整数版本。 */
    public function motion(array $payload): array
    {
        $expectedVersion = $payload['expectedVersion'] ?? null;
        $reduceMotion = $payload['reduceMotion'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1 || !is_bool($reduceMotion)) {
            throw new UserPreferenceInvalid('Motion preference is invalid.');
        }
        return ['expectedVersion' => $expectedVersion, 'reduceMotion' => $reduceMotion];
    }
}
