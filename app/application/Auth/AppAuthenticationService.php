<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use JsonException;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 实现原生 App 的 S256 PKCE、短期 access token 与 refresh token 轮换。
 *
 * 该流程是面向受控 Velin 客户端的 Authorization Code 等价实现：密码只进入现有统一凭据校验器，成功
 * 后返回五分钟单次授权码；只有持有原始 code_verifier 的客户端能交换设备令牌族。所有随机秘密均为
 * 256 bit，持久化前使用用途分离 HMAC。刷新消费和新 token 插入在 BEGIN IMMEDIATE 中完成；重复提交
 * 一个已经轮换的 refresh token 会撤销整族，宁可要求该设备重新登录也不允许攻击者与真实客户端并行
 * 延长会话。数据库或审计失败会回滚整个状态变化，绝不返回未持久化的可用令牌。
 */
final readonly class AppAuthenticationService
{
    private const ACCESS_TTL = 900;
    private const REFRESH_TTL = 2_592_000;
    private const FAMILY_TTL = 7_776_000;

    /** @var list<string> App 允许请求的非管理能力；服务端始终再与账号实时能力取交集。 */
    private const APP_SCOPES = ['play', 'download', 'create_playlist', 'jukebox', 'cast'];
    private const RETIRED_SCOPES = ['transcode'];

    public function __construct(
        private CredentialService $credentials = new CredentialService(),
        private UserActorProjector $actors = new UserActorProjector(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 校验账号密码并签发一次性 PKCE 授权码。
     *
     * @param array<string,mixed> $payload 严格设备与 challenge 声明；未知字段失败关闭。
     * @return array{authorizationCode:string,expiresIn:int}
     */
    public function authorize(array $payload, string $requestId): array
    {
        $this->assertKeys($payload, ['clientId', 'username', 'password', 'codeChallenge', 'device'], ['scopes']);
        $clientId = $this->boundedIdentifier($payload['clientId'] ?? null, 80, '客户端标识无效。');
        $username = is_string($payload['username'] ?? null) ? $payload['username'] : '';
        $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
        $challenge = $payload['codeChallenge'] ?? null;
        if (strlen($username) > 254 || strlen($password) > 1024
            || !is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            throw new AppAuthenticationInvalid('APP_AUTH_REQUEST_INVALID', 'App 登录请求无效。');
        }
        $device = $this->device($payload['device'] ?? null);
        $decision = $this->credentials->authenticate($username, $password, $requestId);
        if ($decision->isRateLimited()) {
            throw new AppAuthenticationInvalid('APP_LOGIN_RATE_LIMITED', (string) $decision->retryAfterSeconds);
        }
        if (!$decision->authenticated || $decision->userId === null) {
            throw new AppAuthenticationInvalid('INVALID_CREDENTIALS', '用户名或密码错误。');
        }
        $actor = $this->actors->project($decision->userId);
        if ($actor === null) throw new AppAuthenticationInvalid('INVALID_CREDENTIALS', '用户名或密码错误。');
        $scopes = $this->requestedScopes($payload['scopes'] ?? null, $actor);

        $id = (string) new Ulid();
        $plain = 'velin_app_code_' . $id . '_' . self::secret();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($challenge, $clientId, $decision, $device, $id, $now, $plain,
            $requestId, $scopes): void {
            // 清理限制在已过期的少量授权事实；不会撤销活动设备，也不扩大当前写事务到媒体或网络。
            $expiredCodes = Db::table('app_authorization_codes')->where('expires_at', '<=', $now)
                ->orderBy('expires_at')->limit(100)->pluck('id')->all();
            if ($expiredCodes !== []) Db::table('app_authorization_codes')->whereIn('id', $expiredCodes)->delete();
            $expiredFamilies = Db::table('app_token_families')->where('expires_at', '<=', $now)
                ->orderBy('expires_at')->limit(50)->pluck('id')->all();
            if ($expiredFamilies !== []) Db::table('app_token_families')->whereIn('id', $expiredFamilies)->delete();
            Db::table('app_authorization_codes')->insert([
                'id' => $id,
                'secret_digest' => self::digest('code', $plain),
                'user_id' => $decision->userId,
                'client_id' => $clientId,
                'code_challenge' => $challenge,
                'device_name' => $device['name'],
                'platform' => $device['platform'],
                'app_version' => $device['appVersion'],
                'push_token_digest' => $device['pushTokenDigest'],
                'scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
                'consumed_at' => null,
                'created_at' => $now,
            ]);
            $this->audit->record($decision->userId, 'app.authorization_code.create', 'app_authorization_code',
                $id, 'success', $requestId, ['clientId' => $clientId, 'platform' => $device['platform']]);
        });
        return ['authorizationCode' => $plain, 'expiresIn' => 300];
    }

    /**
     * 交换授权码或轮换 refresh token；两种 grant 的请求字段均严格封闭。
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function token(array $payload, string $requestId): array
    {
        $grant = $payload['grantType'] ?? null;
        if ($grant === 'authorization_code') {
            $this->assertKeys($payload, ['grantType', 'clientId', 'authorizationCode', 'codeVerifier']);
            return $this->exchangeCode(
                $this->boundedIdentifier($payload['clientId'] ?? null, 80, '客户端标识无效。'),
                is_string($payload['authorizationCode'] ?? null) ? $payload['authorizationCode'] : '',
                is_string($payload['codeVerifier'] ?? null) ? $payload['codeVerifier'] : '',
                $requestId,
            );
        }
        if ($grant === 'refresh_token') {
            $this->assertKeys($payload, ['grantType', 'clientId', 'refreshToken']);
            return $this->rotate(
                $this->boundedIdentifier($payload['clientId'] ?? null, 80, '客户端标识无效。'),
                is_string($payload['refreshToken'] ?? null) ? $payload['refreshToken'] : '',
                $requestId,
            );
        }
        throw new AppAuthenticationInvalid('APP_GRANT_INVALID', 'App token grant 无效。');
    }

    /** @param array<string,mixed> $actor @return list<array<string,mixed>> */
    public function sessions(array $actor): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('app_token_families')->where('user_id', (string) $actor['id'])
            ->whereNull('revoked_at')->where('expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'))
            ->orderByDesc('last_used_at')->limit(100)->get([
                'id', 'client_id', 'device_name', 'platform', 'app_version', 'last_used_at',
                'expires_at', 'created_at',
            ])->all();
        return array_map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'clientId' => (string) $row->client_id,
            'deviceName' => (string) $row->device_name,
            'platform' => (string) $row->platform,
            'appVersion' => (string) $row->app_version,
            'lastUsedAt' => (string) $row->last_used_at,
            'expiresAt' => (string) $row->expires_at,
            'createdAt' => (string) $row->created_at,
        ], $rows);
    }

    /**
     * 撤销当前账号拥有的设备令牌族；重复或跨账号 ID 统一按不存在处理。
     *
     * @param array<string,mixed> $actor
     */
    public function revokeFamily(array $actor, string $familyId, string $requestId, string $reason = 'user_revoked'): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $familyId) !== 1) {
            throw new AppAuthenticationInvalid('APP_SESSION_NOT_FOUND', 'App 设备会话不存在。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::transaction(function () use ($actor, $familyId, $now, $reason): int {
            $changed = Db::table('app_token_families')->where('id', $familyId)
                ->where('user_id', (string) $actor['id'])->whereNull('revoked_at')->update([
                    'revoked_at' => $now, 'revoked_reason' => $reason, 'updated_at' => $now,
                ]);
            if ($changed > 0) Db::table('app_tokens')->where('family_id', $familyId)->whereNull('revoked_at')->update([
                'revoked_at' => $now, 'revoked_reason' => 'family_revoked',
            ]);
            return $changed;
        });
        if ($changed < 1) throw new AppAuthenticationInvalid('APP_SESSION_NOT_FOUND', 'App 设备会话不存在。');
        $this->audit->record((string) $actor['id'], 'app.session.revoke', 'app_token_family',
            $familyId, 'success', $requestId, ['reason' => $reason]);
    }

    /** 使用一次性授权码创建一个固定设备的令牌族。 */
    private function exchangeCode(string $clientId, string $plainCode, string $verifier, string $requestId): array
    {
        if (preg_match('/^velin_app_code_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43}$/D', $plainCode, $matches) !== 1
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            throw new AppAuthenticationInvalid('APP_AUTHORIZATION_CODE_INVALID', '授权码无效或已过期。');
        }
        /** @var stdClass|null $code */
        $code = Db::table('app_authorization_codes')->where('id', $matches[1])->first();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if (!$code instanceof stdClass || $code->consumed_at !== null || (string) $code->expires_at <= $now
            || !hash_equals((string) $code->secret_digest, self::digest('code', $plainCode))
            || !hash_equals((string) $code->code_challenge, $challenge)
            || !hash_equals((string) $code->client_id, $clientId)) {
            throw new AppAuthenticationInvalid('APP_AUTHORIZATION_CODE_INVALID', '授权码无效或已过期。');
        }
        $actor = $this->actors->project((string) $code->user_id);
        if ($actor === null) throw new AppAuthenticationInvalid('APP_AUTHORIZATION_CODE_INVALID', '授权码无效或已过期。');
        $scopes = $this->liveScopes((string) $code->scopes_json, $actor);
        $familyId = (string) new Ulid();
        $pair = $this->newPair(time());

        $this->immediate(function () use ($code, $familyId, $now, $pair, $requestId, $scopes): void {
            $consumed = Db::table('app_authorization_codes')->where('id', (string) $code->id)
                ->whereNull('consumed_at')->where('expires_at', '>', $now)->update(['consumed_at' => $now]);
            if ($consumed !== 1) throw new AppAuthenticationInvalid(
                'APP_AUTHORIZATION_CODE_INVALID', '授权码无效或已过期。',
            );
            Db::table('app_token_families')->insert([
                'id' => $familyId, 'user_id' => (string) $code->user_id, 'client_id' => (string) $code->client_id,
                'device_name' => (string) $code->device_name, 'platform' => (string) $code->platform,
                'app_version' => (string) $code->app_version, 'push_token_digest' => $code->push_token_digest,
                'scopes_json' => json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'last_used_at' => $now, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::FAMILY_TTL),
                'revoked_at' => null, 'revoked_reason' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->insertPair($pair, (string) $code->user_id, $familyId, $scopes);
            $this->audit->record((string) $code->user_id, 'app.session.create', 'app_token_family',
                $familyId, 'success', $requestId, ['clientId' => (string) $code->client_id]);
        });
        return $this->pairResponse($pair, $familyId, $scopes);
    }

    /** 消费 refresh token 并原子换新；发现已轮换令牌重用时整族撤销。 */
    private function rotate(string $clientId, string $plainRefresh, string $requestId): array
    {
        if (preg_match('/^velin_app_rt_([0-9A-HJKMNP-TV-Z]{26})_[A-Za-z0-9_-]{43}$/D', $plainRefresh, $matches) !== 1) {
            throw new AppAuthenticationInvalid('APP_REFRESH_TOKEN_INVALID', '刷新令牌无效。');
        }
        /** @var stdClass|null $token */
        $token = Db::table('app_tokens as tokens')->join('app_token_families as families',
            'families.id', '=', 'tokens.family_id')->where('tokens.id', $matches[1])->first([
                'tokens.id', 'tokens.family_id', 'tokens.user_id', 'tokens.secret_digest', 'tokens.scopes_json',
                'tokens.expires_at as token_expires_at', 'tokens.used_at', 'tokens.revoked_at as token_revoked_at',
                'tokens.revoked_reason as token_revoked_reason', 'families.client_id',
                'families.expires_at as family_expires_at', 'families.revoked_at as family_revoked_at',
            ]);
        if (!$token instanceof stdClass || !hash_equals((string) $token->secret_digest, self::digest('refresh', $plainRefresh))
            || !hash_equals((string) $token->client_id, $clientId)) {
            throw new AppAuthenticationInvalid('APP_REFRESH_TOKEN_INVALID', '刷新令牌无效。');
        }
        if ($token->used_at !== null || $token->token_revoked_at !== null) {
            $this->revokeCompromisedFamily((string) $token->family_id, (string) $token->user_id, $requestId);
            throw new AppAuthenticationInvalid('APP_REFRESH_TOKEN_REUSED', '检测到刷新令牌重用，该设备已退出。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        if ($token->family_revoked_at !== null || (string) $token->token_expires_at <= $now
            || (string) $token->family_expires_at <= $now) {
            throw new AppAuthenticationInvalid('APP_REFRESH_TOKEN_INVALID', '刷新令牌无效。');
        }
        $actor = $this->actors->project((string) $token->user_id);
        if ($actor === null) throw new AppAuthenticationInvalid('APP_REFRESH_TOKEN_INVALID', '刷新令牌无效。');
        $scopes = $this->liveScopes((string) $token->scopes_json, $actor);
        $pair = $this->newPair(time());

        try {
            $this->immediate(function () use ($now, $pair, $requestId, $scopes, $token): void {
                $changed = Db::table('app_tokens')->where('id', (string) $token->id)
                    ->whereNull('used_at')->whereNull('revoked_at')->update([
                        'used_at' => $now, 'revoked_at' => $now, 'revoked_reason' => 'rotated',
                        'replaced_by_id' => $pair['refreshId'],
                    ]);
                if ($changed !== 1) throw new AppAuthenticationInvalid(
                    'APP_REFRESH_TOKEN_REUSED', '检测到刷新令牌重用，该设备已退出。',
                );
                $this->insertPair($pair, (string) $token->user_id, (string) $token->family_id, $scopes);
                $familyChanged = Db::table('app_token_families')->where('id', (string) $token->family_id)
                    ->whereNull('revoked_at')->where('expires_at', '>', $now)->update([
                        'last_used_at' => $now, 'updated_at' => $now,
                    ]);
                if ($familyChanged !== 1) throw new AppAuthenticationInvalid(
                    'APP_REFRESH_TOKEN_INVALID', '刷新令牌无效。',
                );
                $this->audit->record((string) $token->user_id, 'app.token.refresh', 'app_token_family',
                    (string) $token->family_id, 'success', $requestId);
            });
        } catch (AppAuthenticationInvalid $failure) {
            if ($failure->reasonCode === 'APP_REFRESH_TOKEN_REUSED') {
                $this->revokeCompromisedFamily((string) $token->family_id, (string) $token->user_id, $requestId);
            }
            throw $failure;
        }
        return $this->pairResponse($pair, (string) $token->family_id, $scopes);
    }

    /** 整族撤销与 token 撤销在同一短事务中完成；重复执行保持幂等。 */
    private function revokeCompromisedFamily(string $familyId, string $userId, string $requestId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($familyId, $now): void {
            Db::table('app_token_families')->where('id', $familyId)->whereNull('revoked_at')->update([
                'revoked_at' => $now, 'revoked_reason' => 'refresh_reuse', 'updated_at' => $now,
            ]);
            Db::table('app_tokens')->where('family_id', $familyId)->whereNull('revoked_at')->update([
                'revoked_at' => $now, 'revoked_reason' => 'family_revoked',
            ]);
        });
        $this->audit->record($userId, 'app.token.reuse_detected', 'app_token_family',
            $familyId, 'denied', $requestId);
    }

    /** @return array<string,mixed> */
    private function newPair(int $now): array
    {
        $accessId = (string) new Ulid();
        $refreshId = (string) new Ulid();
        return [
            'accessId' => $accessId,
            'access' => 'velin_app_at_' . $accessId . '_' . self::secret(),
            'accessExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + self::ACCESS_TTL),
            'refreshId' => $refreshId,
            'refresh' => 'velin_app_rt_' . $refreshId . '_' . self::secret(),
            'refreshExpiresAt' => gmdate('Y-m-d\TH:i:s\Z', $now + self::REFRESH_TTL),
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
    }

    /** @param array<string,mixed> $pair @param list<string> $scopes */
    private function insertPair(array $pair, string $userId, string $familyId, array $scopes): void
    {
        $json = json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::table('app_tokens')->insert([
            ['id' => $pair['accessId'], 'family_id' => $familyId, 'user_id' => $userId, 'token_type' => 'access',
                'secret_digest' => self::digest('access', $pair['access']), 'scopes_json' => $json,
                'expires_at' => $pair['accessExpiresAt'], 'used_at' => null, 'revoked_at' => null,
                'revoked_reason' => null, 'replaced_by_id' => null, 'created_at' => $pair['createdAt']],
            ['id' => $pair['refreshId'], 'family_id' => $familyId, 'user_id' => $userId, 'token_type' => 'refresh',
                'secret_digest' => self::digest('refresh', $pair['refresh']), 'scopes_json' => $json,
                'expires_at' => $pair['refreshExpiresAt'], 'used_at' => null, 'revoked_at' => null,
                'revoked_reason' => null, 'replaced_by_id' => null, 'created_at' => $pair['createdAt']],
        ]);
    }

    /** @param array<string,mixed> $pair @param list<string> $scopes @return array<string,mixed> */
    private function pairResponse(array $pair, string $familyId, array $scopes): array
    {
        return ['tokenType' => 'Bearer', 'accessToken' => $pair['access'], 'expiresIn' => self::ACCESS_TTL,
            'refreshToken' => $pair['refresh'], 'refreshExpiresIn' => self::REFRESH_TTL,
            'familyId' => $familyId, 'scopes' => $scopes];
    }

    /** @param array<string,mixed> $actor @return list<string> */
    private function requestedScopes(mixed $value, array $actor): array
    {
        $owned = array_values(array_intersect(self::APP_SCOPES,
            is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : []));
        if ($value === null) return $owned;
        if (!is_array($value) || !array_is_list($value)
            || count($value) > count(self::APP_SCOPES) + count(self::RETIRED_SCOPES)) {
            throw new AppAuthenticationInvalid('APP_SCOPE_INVALID', 'App 权限范围无效。');
        }
        $scopes = [];
        foreach ($value as $scope) {
            // 旧版 App 会显式申请 transcode。该权限取消后把它作为无权限语义的兼容字段忽略，
            // 避免滚动升级期间旧客户端无法登录；返回与持久化的 scope 都不会再包含它。
            if (is_string($scope) && in_array($scope, self::RETIRED_SCOPES, true)) continue;
            if (!is_string($scope) || !in_array($scope, self::APP_SCOPES, true)) {
                throw new AppAuthenticationInvalid('APP_SCOPE_INVALID', 'App 权限范围无效。');
            }
            $scopes[$scope] = true;
        }
        $scopes = array_keys($scopes);
        sort($scopes, SORT_STRING);
        if (array_diff($scopes, $owned) !== []) {
            throw new AppAuthenticationInvalid('APP_SCOPE_INVALID', '不能授予账号当前不具备的权限。');
        }
        return $scopes;
    }

    /** @param array<string,mixed> $actor @return list<string> */
    private function liveScopes(string $json, array $actor): array
    {
        try {
            $stored = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AppAuthenticationInvalid('APP_TOKEN_INVALID', '令牌权限数据无效。');
        }
        if (!is_array($stored) || !array_is_list($stored)) {
            throw new AppAuthenticationInvalid('APP_TOKEN_INVALID', '令牌权限数据无效。');
        }
        return array_values(array_intersect(array_values(array_filter($stored, 'is_string')),
            is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : []));
    }

    /** @return array{name:string,platform:string,appVersion:string,pushTokenDigest:?string} */
    private function device(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new AppAuthenticationInvalid('APP_DEVICE_INVALID', '设备信息无效。');
        }
        $this->assertKeys($value, ['name', 'platform', 'appVersion'], ['pushToken']);
        $name = $this->boundedText($value['name'] ?? null, 120, '设备名称无效。');
        $platform = $value['platform'] ?? null;
        if (!is_string($platform) || !in_array($platform, ['android', 'ios', 'windows', 'macos', 'linux'], true)) {
            throw new AppAuthenticationInvalid('APP_DEVICE_INVALID', '设备平台无效。');
        }
        $appVersion = $this->boundedIdentifier($value['appVersion'] ?? null, 40, 'App 版本无效。');
        $push = $value['pushToken'] ?? null;
        if ($push !== null && (!is_string($push) || strlen($push) < 16 || strlen($push) > 4096)) {
            throw new AppAuthenticationInvalid('APP_DEVICE_INVALID', '推送标识无效。');
        }
        return ['name' => $name, 'platform' => $platform, 'appVersion' => $appVersion,
            'pushTokenDigest' => is_string($push) ? self::digest('push', $push) : null];
    }

    private function boundedIdentifier(mixed $value, int $maximum, string $message): string
    {
        if (!is_string($value) || strlen($value) < 1 || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1) {
            throw new AppAuthenticationInvalid('APP_AUTH_REQUEST_INVALID', $message);
        }
        return $value;
    }

    private function boundedText(mixed $value, int $maximum, string $message): string
    {
        if (!is_string($value)) throw new AppAuthenticationInvalid('APP_DEVICE_INVALID', $message);
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 1 || $length > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new AppAuthenticationInvalid('APP_DEVICE_INVALID', $message);
        }
        return $value;
    }

    /** @param list<string> $required @param list<string> $optional */
    private function assertKeys(array $payload, array $required, array $optional = []): void
    {
        if (array_is_list($payload) || array_diff($required, array_keys($payload)) !== []
            || array_diff(array_keys($payload), [...$required, ...$optional]) !== []) {
            throw new AppAuthenticationInvalid('APP_AUTH_REQUEST_INVALID', 'App 认证请求字段无效。');
        }
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** 使用部署认证密钥和用途标签计算不可互换摘要。 */
    public static function digest(string $purpose, string $plain): string
    {
        $key = hash_hkdf('sha256', RequestContext::authenticationHashKey(), 32, 'velin-app-auth-key-v1');
        return hash_hmac('sha256', "velin-app-auth-v1\0" . $purpose . "\0" . $plain, $key);
    }

    /**
     * 在 SQLite 预先取得写保留锁，保证授权码消费和 refresh 轮换不会被两个 worker 同时成功提交。
     * 回调任意异常都会回滚；本方法内只允许数据库短操作，禁止密码、网络、媒体和随机数工作。
     */
    private function immediate(callable $operation): mixed
    {
        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $result = $operation();
            $pdo->exec('COMMIT');
            $open = false;
            return $result;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }
    }
}
