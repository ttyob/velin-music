<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\application\Library\LibraryAccessResolver;
use app\application\Theme\ThemeService;
use stdClass;
use support\Db;

/**
 * 从当前数据库事实生成 Cookie、PAT 与 App Bearer 共用的账号投影。
 *
 * 调用方只能传入已经由不可伪造凭据绑定的 userId。本服务仍重新检查停用、软删除和账号到期，并实时
 * 解析角色能力、音乐库授权及主题回退，因此长期存在的会话或短期 token 不会冻结旧权限。返回值不含
 * 密码散列、令牌摘要、文件路径和设备秘密；账号无效统一返回 null，避免认证适配器泄露具体原因。
 */
final readonly class UserActorProjector
{
    public function __construct(
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private ThemeService $themes = new ThemeService(),
    ) {
    }

    /** @return array<string,mixed>|null 返回实时账号快照；无效账号不产生任何写入。 */
    public function project(string $userId): ?array
    {
        /** @var stdClass|null $row */
        $row = Db::table('users')
            ->leftJoin('user_preferences as preferences', 'preferences.user_id', '=', 'users.id')
            ->where('users.id', $userId)
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->where(static function ($query): void {
                $query->whereNull('users.account_expires_at')
                    ->orWhere('users.account_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
            })
            ->first([
                'users.id', 'users.username', 'users.display_name', 'users.email', 'users.is_super_admin',
                'users.permission_version', 'users.locale as user_locale', 'users.timezone as user_timezone',
                'preferences.theme_id', 'preferences.locale as preference_locale',
                'preferences.timezone as preference_timezone', 'preferences.reduce_motion',
                'preferences.version as preference_version',
            ]);
        if (!$row instanceof stdClass) return null;

        $isSuper = (int) $row->is_super_admin === 1;
        $theme = $this->themes->resolvePreference($row->theme_id === null ? null : (string) $row->theme_id);
        return [
            'id' => (string) $row->id,
            'username' => (string) $row->username,
            'displayName' => (string) $row->display_name,
            'email' => $row->email === null ? null : (string) $row->email,
            'isSuperAdmin' => $isSuper,
            'permissionVersion' => (int) $row->permission_version,
            'capabilities' => $this->capabilities->resolve((string) $row->id, $isSuper),
            'libraries' => $this->libraries->resolve((string) $row->id, $isSuper),
            'preferences' => [
                'themeId' => $theme['themeId'],
                'themeFallbackFrom' => $theme['themeFallbackFrom'],
                'locale' => (string) ($row->preference_locale ?? $row->user_locale),
                'timezone' => (string) ($row->preference_timezone ?? $row->user_timezone),
                'reduceMotion' => (int) ($row->reduce_motion ?? 0) === 1,
                'version' => (int) ($row->preference_version ?? 1),
            ],
        ];
    }
}
