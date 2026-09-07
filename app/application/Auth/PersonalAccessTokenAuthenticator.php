<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\application\Library\LibraryAccessResolver;
use JsonException;
use stdClass;
use support\Db;
use support\Request;

/**
 * 从 Authorization Bearer 头解析个人访问令牌并生成实时裁剪身份。
 *
 * 令牌范围每次与账号当前能力取交集，音乐库范围每次重新解析；停用账号、撤销/过期令牌、角色或库授权
 * 变化都会在下一请求立即生效。任何失败原因统一返回 null，避免枚举令牌 ID、账号或撤销状态。
 */
final readonly class PersonalAccessTokenAuthenticator
{
    public function __construct(
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
    ) {
    }

    /** @return array<string,mixed>|null */
    public function authenticate(Request $request): ?array
    {
        $header = $request->header('authorization');
        if (!is_string($header) || preg_match(
            '/^Bearer (velin_pat_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43})$/D',
            $header,
            $matches,
        ) !== 1) return null;

        /** @var stdClass|null $row */
        $row = Db::table('personal_access_tokens as tokens')
            ->join('users', 'users.id', '=', 'tokens.user_id')
            ->where('tokens.id', $matches[2])->first([
                'tokens.id as token_id', 'tokens.secret_digest', 'tokens.scopes_json', 'tokens.expires_at',
                'tokens.last_used_at', 'tokens.revoked_at', 'users.id', 'users.username', 'users.display_name',
                'users.email', 'users.is_super_admin', 'users.permission_version', 'users.status',
                'users.deleted_at', 'users.account_expires_at',
            ]);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!$row instanceof stdClass || !hash_equals((string) $row->secret_digest,
            PersonalAccessTokenService::digest($matches[1])) || $row->revoked_at !== null
            || ($row->expires_at !== null && (string) $row->expires_at <= $now)
            || (string) $row->status !== 'active' || $row->deleted_at !== null
            || ($row->account_expires_at !== null && (string) $row->account_expires_at <= $now)) {
            return null;
        }
        try {
            $granted = json_decode((string) $row->scopes_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($granted) || !array_is_list($granted)) return null;
        $userId = (string) $row->id;
        $isSuper = (int) $row->is_super_admin === 1;
        $effective = array_values(array_intersect(
            array_values(array_filter($granted, 'is_string')),
            $this->capabilities->resolve($userId, $isSuper),
        ));

        $lastUsed = $row->last_used_at === null ? 0 : (strtotime((string) $row->last_used_at) ?: 0);
        if ($lastUsed <= time() - 300) {
            Db::table('personal_access_tokens')->where('id', (string) $row->token_id)
                ->whereNull('revoked_at')->update(['last_used_at' => $now, 'updated_at' => $now]);
        }

        return [
            'id' => $userId,
            'username' => (string) $row->username,
            'displayName' => (string) $row->display_name,
            'email' => $row->email === null ? null : (string) $row->email,
            'isSuperAdmin' => $isSuper,
            'librarySetupRequired' => !(new \app\application\Library\DefaultLibraryService())->isConfigured(),
            'permissionVersion' => (int) $row->permission_version,
            'capabilities' => $effective,
            'libraries' => $this->libraries->resolve($userId, $isSuper),
            'authenticationType' => 'personal_token',
            'personalTokenId' => (string) $row->token_id,
        ];
    }
}
