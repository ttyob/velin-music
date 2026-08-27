<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 管理当前账号的个人访问令牌（AUTH-007/API-AUTH-004）。
 *
 * 明文令牌由服务端高熵随机数生成，只在 create 返回一次；持久化层仅保存用途分离 HMAC 摘要。允许范围
 * 固定为客户端型非管理能力，并且必须是账号当前真实拥有能力的子集。管理员能力、播放隐私、删除和
 * 元数据维护能力不能委托给个人令牌，防止一个普通连接凭据成为后台管理入口。
 */
final readonly class PersonalAccessTokenService
{
    /** @var list<string> */
    private const ALLOWED_SCOPES = [
        'play', 'download', 'create_playlist', 'jukebox',
    ];

    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /** @param array<string,mixed> $actor @return array{tokens:list<array<string,mixed>>,availableScopes:list<string>} */
    public function list(array $actor): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('personal_access_tokens')->where('user_id', (string) $actor['id'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)
            ->get(['id', 'name', 'scopes_json', 'expires_at', 'last_used_at', 'revoked_at', 'created_at'])->all();

        return [
            'tokens' => array_map(fn (stdClass $row): array => $this->projection($row), $rows),
            'availableScopes' => $this->availableScopes($actor),
        ];
    }

    /**
     * 创建令牌并返回一次性明文；随机数生成和 JSON 规范化均在短事务之前完成。
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $payload
     * @return array{token:array<string,mixed>,plainTextToken:string}
     */
    public function create(array $actor, array $payload, string $requestId): array
    {
        $name = $this->name($payload['name'] ?? null);
        $scopes = $this->scopes($payload['scopes'] ?? null, $actor);
        $expiresAt = $this->expiresAt($payload['expiresAt'] ?? null);
        $id = (string) new Ulid();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plainText = 'velin_pat_' . $id . '_' . $secret;
        $digest = self::digest($plainText);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $scopesJson = json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        Db::transaction(function () use (
            $actor, $digest, $expiresAt, $id, $name, $now, $requestId, $scopes, $scopesJson,
        ): void {
            // 事务内重新解析能力，避免预校验后角色被并发收回仍签发越权范围。
            $liveScopes = $this->availableScopes($actor);
            if (array_diff($scopes, $liveScopes) !== []) {
                throw new PersonalAccessTokenInvalid('令牌权限范围已经变化，请刷新后重试。');
            }
            Db::table('personal_access_tokens')->insert([
                'id' => $id,
                'user_id' => (string) $actor['id'],
                'name' => $name,
                'secret_digest' => $digest,
                'scopes_json' => $scopesJson,
                'expires_at' => $expiresAt,
                'last_used_at' => null,
                'revoked_at' => null,
                'revoked_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'personal_token.create', 'personal_access_token',
                $id, 'success', $requestId, ['scopeCount' => count($scopes), 'expires' => $expiresAt !== null]);
        });

        /** @var stdClass $row */
        $row = Db::table('personal_access_tokens')->where('id', $id)->first([
            'id', 'name', 'scopes_json', 'expires_at', 'last_used_at', 'revoked_at', 'created_at',
        ]);
        return ['token' => $this->projection($row), 'plainTextToken' => $plainText];
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function revoke(array $actor, string $tokenId, string $requestId): array
    {
        $this->ulid($tokenId);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return Db::transaction(function () use ($actor, $now, $requestId, $tokenId): array {
            /** @var stdClass|null $row */
            $row = Db::table('personal_access_tokens')->where('id', $tokenId)
                ->where('user_id', (string) $actor['id'])->first([
                    'id', 'name', 'scopes_json', 'expires_at', 'last_used_at', 'revoked_at', 'created_at',
                ]);
            if (!$row instanceof stdClass) throw new PersonalAccessTokenInvalid('个人令牌不存在。');
            if ($row->revoked_at === null) {
                Db::table('personal_access_tokens')->where('id', $tokenId)->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'revoked_reason' => 'user_revoked',
                    'updated_at' => $now,
                ]);
                $row->revoked_at = $now;
                $this->audit->record((string) $actor['id'], 'personal_token.revoke', 'personal_access_token',
                    $tokenId, 'success', $requestId);
            }
            return $this->projection($row);
        });
    }

    /** 计算令牌明文的用途分离摘要；数据库泄露不能直接还原可用 Bearer 值。 */
    public static function digest(string $plainText): string
    {
        return hash_hmac(
            'sha256',
            "velin-personal-access-token-v1\0" . $plainText,
            RequestContext::authenticationHashKey(),
        );
    }

    /** @param array<string,mixed> $actor @return list<string> */
    private function availableScopes(array $actor): array
    {
        $owned = (new CapabilityResolver())->resolve(
            (string) $actor['id'],
            (bool) ($actor['isSuperAdmin'] ?? false),
        );
        return array_values(array_intersect(self::ALLOWED_SCOPES, $owned));
    }

    /** @param mixed $value */
    private function name(mixed $value): string
    {
        if (!is_string($value)) throw new PersonalAccessTokenInvalid('令牌名称无效。');
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 1 || $length > 80 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new PersonalAccessTokenInvalid('令牌名称无效。');
        }
        return $value;
    }

    /** @param mixed $value @param array<string,mixed> $actor @return list<string> */
    private function scopes(mixed $value, array $actor): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []
            || count($value) > count(self::ALLOWED_SCOPES)) {
            throw new PersonalAccessTokenInvalid('至少选择一个令牌权限范围。');
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || !in_array($scope, self::ALLOWED_SCOPES, true)) {
                throw new PersonalAccessTokenInvalid('令牌权限范围无效。');
            }
            $scopes[$scope] = true;
        }
        $normalized = array_keys($scopes);
        sort($normalized, SORT_STRING);
        if (array_diff($normalized, $this->availableScopes($actor)) !== []) {
            throw new PersonalAccessTokenInvalid('不能授予账号当前不具备的令牌权限。');
        }
        return $normalized;
    }

    /** @param mixed $value */
    private function expiresAt(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            throw new PersonalAccessTokenInvalid('令牌到期时间无效。');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= time() + 60 || $timestamp > time() + 31_536_000) {
            throw new PersonalAccessTokenInvalid('令牌到期时间必须在未来一分钟至一年内。');
        }
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function ulid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new PersonalAccessTokenInvalid('个人令牌标识无效。');
        }
    }

    /** @return array<string,mixed> */
    private function projection(stdClass $row): array
    {
        try {
            $scopes = json_decode((string) $row->scopes_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $scopes = [];
        }
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'scopes' => is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
            'lastUsedAt' => $row->last_used_at === null ? null : (string) $row->last_used_at,
            'revokedAt' => $row->revoked_at === null ? null : (string) $row->revoked_at,
            'createdAt' => (string) $row->created_at,
        ];
    }
}
