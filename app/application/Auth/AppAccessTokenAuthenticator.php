<?php

declare(strict_types=1);

namespace app\application\Auth;

use JsonException;
use stdClass;
use support\Db;
use support\Request;

/**
 * 从 Bearer 头验证短期 App access token，并把令牌范围与账号实时权限相交。
 *
 * 显式 Bearer 的所有失败统一返回 null；调用方不得回退 Cookie。每次请求复查 token、令牌族、账号状态、
 * 角色和音乐库授权，因而设备撤销、refresh 重用、账号停用或权限收回会立即阻断后续请求。数据库只按
 * ULID 查找摘要，不记录明文 Header；last_used_at 最多五分钟写一次以限制 SQLite 写竞争。
 */
final readonly class AppAccessTokenAuthenticator
{
    public function __construct(private UserActorProjector $actors = new UserActorProjector())
    {
    }

    /** @return array<string,mixed>|null */
    public function authenticate(Request $request): ?array
    {
        $header = $request->header('authorization');
        if (!is_string($header) || preg_match(
            '/^Bearer (velin_app_at_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43})$/D',
            $header,
            $matches,
        ) !== 1) return null;
        /** @var stdClass|null $row */
        $row = Db::table('app_tokens as tokens')->join('app_token_families as families',
            'families.id', '=', 'tokens.family_id')->where('tokens.id', $matches[2])->first([
                'tokens.id', 'tokens.family_id', 'tokens.user_id', 'tokens.secret_digest', 'tokens.scopes_json',
                'tokens.expires_at', 'tokens.revoked_at', 'families.client_id', 'families.last_used_at',
                'families.expires_at as family_expires_at', 'families.revoked_at as family_revoked_at',
            ]);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!$row instanceof stdClass || $row->revoked_at !== null || $row->family_revoked_at !== null
            || (string) $row->expires_at <= $now || (string) $row->family_expires_at <= $now
            || !hash_equals((string) $row->secret_digest, AppAuthenticationService::digest('access', $matches[1]))) {
            return null;
        }
        $actor = $this->actors->project((string) $row->user_id);
        if ($actor === null) return null;
        try {
            $scopes = json_decode((string) $row->scopes_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($scopes) || !array_is_list($scopes)) return null;
        $actor['capabilities'] = array_values(array_intersect(
            array_values(array_filter($scopes, 'is_string')),
            is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [],
        ));
        $actor['authenticationType'] = 'app_access_token';
        $actor['appTokenId'] = (string) $row->id;
        $actor['appTokenFamilyId'] = (string) $row->family_id;
        $actor['appClientId'] = (string) $row->client_id;
        $lastUsed = strtotime((string) $row->last_used_at) ?: 0;
        if ($lastUsed <= time() - 300) {
            Db::table('app_token_families')->where('id', (string) $row->family_id)->whereNull('revoked_at')->update([
                'last_used_at' => $now, 'updated_at' => $now,
            ]);
        }
        return $actor;
    }
}
