<?php

declare(strict_types=1);

namespace app\application\User;

/**
 * 承载后台用户编辑弹窗已经校验的可修改资料。
 *
 * 用户名、密码、状态、超级管理员标记、音乐库授权和限额不属于本命令，必须继续走各自安全边界。
 * roleKey 对超级管理员为 null；普通账号必须是可委派的固定内置角色。directCapabilities 只表达
 * 经过后台允许的用户直授能力，不改变角色继承能力；对象不包含任何认证秘密。
 */
final readonly class UserUpdateInput
{
    public function __construct(
        public string $displayName,
        public ?string $email,
        public string $locale,
        public string $timezone,
        public ?string $accountExpiresAt,
        public ?string $roleKey,
        /** @var list<string> 用户直授能力键；当前仅允许 cast。 */
        public array $directCapabilities,
        public int $expectedPermissionVersion,
        public int $expectedProfileVersion,
    ) {
    }
}
