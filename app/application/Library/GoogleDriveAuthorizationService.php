<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use app\application\System\NetworkProxyRequestOptions;
use app\application\System\PublicUrlConfig;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 编排 Google OAuth 2.0 Authorization Code + PKCE，并提供绑定 actor 的一次性授权 grant。
 *
 * start 仅接受 Google OAuth Web 客户端身份，回调 URI固定从可信 `VELIN_PUBLIC_URL` 构造，绝不读取 Host。
 * 随机 state 只把原值放入 Google 授权 URL，数据库保存 SHA-256；PKCE verifier、client secret 与 refresh
 * token 分别加密。回调按 state 定位短命会话、兑换 code、验证受控读写 scope，并用 Drive about 取得不对外
 * 投影的 permission ID。会话完成后只能由同一 actor 消费一次，消费时清除会话秘密。
 *
 * OAuth 与 Drive HTTP 发生在数据库事务外，随后使用条件更新提交终态；上游已签发 grant 但本地更新失败
 * 时不会尝试远端撤销，因为撤销会影响同一用户对该 OAuth 客户端的其他连接。重试使用新会话，所有上游
 * 错误正文都被丢弃并映射为稳定错误。
 */
final readonly class GoogleDriveAuthorizationService
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive';
    private const MAX_JSON_BYTES = 1_048_576;

    public function __construct(
        private GoogleDriveOAuthCipher $cipher = new GoogleDriveOAuthCipher(),
        private ?ClientInterface $http = null,
    ) {
    }

    /**
     * 创建十分钟授权会话并返回一次性 Google 授权 URL。
     *
     * 同一 actor 十分钟最多启动五次，防止刷新或脚本持续创建 state。返回值不包含 client secret、verifier
     * 或 state 的独立字段；authorizationUrl 到期后不可复用，调用方不得写入持久化浏览器存储。
     *
     * @param array<string,mixed> $actor 已通过 `manage_library` 校验的管理员
     * @return array<string,mixed>
     */
    public function start(string $clientId, string $clientSecret, array $actor, ?string $proxyProfileId = null): array
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        $actorId = (string) ($actor['id'] ?? '');
        if (!$this->clientId($clientId) || !$this->secret($clientSecret) || !$this->identifier($actorId, 64)) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_INPUT_INVALID', 'Google OAuth 客户端配置无效。');
        }
        $recent = Db::table('google_drive_authorizations')->where('actor_user_id', $actorId)
            ->where('created_at', '>=', gmdate('Y-m-d\TH:i:s\Z', time() - 600))->count();
        if ($recent >= 5) {
            throw new GoogleDriveAuthorizationFailed(
                'GOOGLE_DRIVE_AUTHORIZATION_RATE_LIMITED', '授权请求过于频繁，请稍后重试。', 429,
            );
        }
        try {
            $origin = PublicUrlConfig::publicOrigin();
        } catch (Throwable) {
            $origin = null;
        }
        if (!is_string($origin) || $origin === '') {
            throw new GoogleDriveAuthorizationFailed(
                'GOOGLE_DRIVE_PUBLIC_URL_REQUIRED', '请先配置有效的 VELIN_PUBLIC_URL。', 422,
            );
        }
        $redirectUri = $origin . '/api/v1/admin/google-drive/oauth/callback';
        $state = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(64));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $id = (string) new Ulid();
        $now = time();
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $now + 600);
        $proxy = $proxyProfileId === null ? null : (new NetworkProxyProfileService())->connection($proxyProfileId);
        if (is_array($proxy) && $proxy['password'] !== '') sodium_memzero($proxy['password']);
        Db::table('google_drive_authorizations')->insert([
            'id' => $id,
            'actor_user_id' => $actorId,
            'client_id' => $clientId,
            'client_secret_ciphertext' => $this->cipher->encryptClientSecret($clientSecret),
            'pkce_verifier_ciphertext' => $this->cipher->encryptPkceVerifier($verifier),
            'state_digest' => hash('sha256', $state),
            'redirect_uri' => $redirectUri,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'refresh_token_ciphertext' => null,
            'account_id' => null,
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'proxy_profile_id' => $proxyProfileId,
        ]);
        sodium_memzero($verifier);
        sodium_memzero($clientSecret);
        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return [
            'id' => $id,
            'status' => 'pending',
            'authorizationUrl' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $query,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * 处理 Google 回调并把会话原子推进到 authorized/denied/expired。
     *
     * state 未知时不泄露会话是否存在；code 只兑换一次。成功要求 refresh token 和受控读写 scope，缺失通常
     * 表示 Google 未显示同意页或 OAuth 客户端配置错误，必须重新发起授权，不能借用旧 token。
     */
    public function complete(string $state, ?string $code, ?string $oauthError): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $state) !== 1) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_STATE_INVALID', 'Google 授权状态无效。', 400);
        }
        /** @var stdClass|null $row */
        $row = Db::table('google_drive_authorizations')->where('state_digest', hash('sha256', $state))->first();
        if (!$row instanceof stdClass || (string) $row->status !== 'pending') {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_STATE_INVALID', 'Google 授权状态无效或已使用。', 400);
        }
        if (strtotime((string) $row->expires_at) <= time()) {
            $this->finishWithoutGrant((string) $row->id, 'expired');
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_EXPIRED', 'Google 授权已过期。', 400);
        }
        if ($oauthError !== null) {
            $this->finishWithoutGrant((string) $row->id, 'denied');
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_DENIED', 'Google 授权已取消或拒绝。', 400);
        }
        if (!is_string($code) || $code === '' || strlen($code) > 8192) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_CODE_INVALID', 'Google 授权码无效。', 400);
        }
        $proxy = $row->proxy_profile_id === null ? null
            : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id);
        try {
            $secret = $this->cipher->decryptClientSecret((string) $row->client_secret_ciphertext);
            $verifier = $this->cipher->decryptPkceVerifier((string) $row->pkce_verifier_ciphertext);
            $response = $this->request('POST', 'https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'client_id' => (string) $row->client_id,
                    'client_secret' => $secret,
                    'code' => $code,
                    'code_verifier' => $verifier,
                    'redirect_uri' => (string) $row->redirect_uri,
                    'grant_type' => 'authorization_code',
                ],
            ], $proxy);
        } finally {
            if (isset($secret) && is_string($secret) && $secret !== '') sodium_memzero($secret);
            if (isset($verifier) && is_string($verifier) && $verifier !== '') sodium_memzero($verifier);
        }
        if ($response->getStatusCode() !== 200) {
            $this->finishWithoutGrant((string) $row->id, 'denied');
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_TOKEN_EXCHANGE_FAILED', 'Google 无法完成授权，请重新开始。', 400);
        }
        $payload = $this->json($response);
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $scope = $payload['scope'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 65_536
            || !is_string($refreshToken) || $refreshToken === '' || strlen($refreshToken) > 65_536
            || !is_string($scope) || !in_array(self::SCOPE, preg_split('/\s+/', trim($scope)) ?: [], true)) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_RESPONSE_INVALID', 'Google 返回了无效或缺少受控写权限的授权结果。', 503);
        }
        try {
            $accountId = $this->accountId($accessToken, $proxy);
            $refreshCiphertext = $this->cipher->encryptRefreshToken($refreshToken);
        } finally {
            sodium_memzero($accessToken);
            sodium_memzero($refreshToken);
            if (is_array($proxy) && $proxy['password'] !== '') sodium_memzero($proxy['password']);
        }
        $changed = Db::table('google_drive_authorizations')->where('id', (string) $row->id)
            ->where('status', 'pending')->update([
                'status' => 'authorized',
                'pkce_verifier_ciphertext' => null,
                'refresh_token_ciphertext' => $refreshCiphertext,
                'account_id' => $accountId,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($changed !== 1) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_CONFLICT', '授权状态已经变化，请重新开始。', 409);
        }
    }

    /** @param array<string,mixed> $actor 返回同一 actor 的脱敏状态，不访问 Google。 */
    public function status(string $authorizationId, array $actor): array
    {
        $row = $this->authorization($authorizationId, (string) ($actor['id'] ?? ''));
        if (in_array((string) $row->status, ['pending', 'authorized'], true)
            && strtotime((string) $row->expires_at) <= time()) {
            $this->finishWithoutGrant((string) $row->id, 'expired');
            $row = $this->authorization($authorizationId, (string) ($actor['id'] ?? ''));
        }
        return $this->projection($row);
    }

    /** 返回已完成且未过期的 grant；调用方必须清零两个明文秘密。 */
    public function completedGrant(string $authorizationId, array $actor): GoogleDriveAuthorizationGrant
    {
        $row = $this->authorization($authorizationId, (string) ($actor['id'] ?? ''));
        if ((string) $row->status !== 'authorized' || strtotime((string) $row->expires_at) <= time()) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_NOT_READY', '请先完成 Google 授权。');
        }
        try {
            $clientSecret = $this->cipher->decryptClientSecret((string) $row->client_secret_ciphertext);
            $refreshToken = $this->cipher->decryptRefreshToken((string) $row->refresh_token_ciphertext);
        } catch (Throwable) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_UNAVAILABLE', 'Google 授权不可用，请重新开始。');
        }
        return new GoogleDriveAuthorizationGrant(
            (string) $row->id,
            (string) $row->client_id,
            $clientSecret,
            (string) $row->client_secret_ciphertext,
            $refreshToken,
            (string) $row->refresh_token_ciphertext,
            (string) $row->account_id,
            $row->proxy_profile_id === null ? null : (string) $row->proxy_profile_id,
        );
    }

    /** 在音乐库连接写入的同一事务内一次性消费，并清除授权会话秘密。 */
    public function consume(string $authorizationId, array $actor): void
    {
        $changed = Db::table('google_drive_authorizations')->where('id', $authorizationId)
            ->where('actor_user_id', (string) ($actor['id'] ?? ''))->where('status', 'authorized')->update([
                'status' => 'consumed',
                'client_secret_ciphertext' => null,
                'pkce_verifier_ciphertext' => null,
                'refresh_token_ciphertext' => null,
                'account_id' => null,
                'consumed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($changed !== 1) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_CONFLICT', 'Google 授权已被使用或失效。', 409);
        }
    }

    private function finishWithoutGrant(string $id, string $status): void
    {
        Db::table('google_drive_authorizations')->where('id', $id)->where('status', 'pending')->update([
            'status' => $status,
            'client_secret_ciphertext' => null,
            'pkce_verifier_ciphertext' => null,
            'refresh_token_ciphertext' => null,
            'account_id' => null,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private function accountId(string $accessToken, ?array $proxy): string
    {
        $response = $this->request('GET', 'https://www.googleapis.com/drive/v3/about', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
            'query' => ['fields' => 'user(permissionId)'],
        ], $proxy);
        $payload = $response->getStatusCode() === 200 ? $this->json($response) : [];
        $id = $payload['user']['permissionId'] ?? null;
        if (!is_string($id) || !$this->identifier($id, 256)) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_IDENTITY_UNAVAILABLE', '无法验证 Google Drive 账号身份。', 503);
        }
        return $id;
    }

    private function authorization(string $id, string $actorId): stdClass
    {
        if (!$this->ulid($id) || !$this->identifier($actorId, 64)) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_NOT_FOUND', 'Google 授权不存在。', 404);
        }
        /** @var stdClass|null $row */
        $row = Db::table('google_drive_authorizations')->where('id', $id)
            ->where('actor_user_id', $actorId)->first();
        if (!$row instanceof stdClass) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_NOT_FOUND', 'Google 授权不存在。', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function projection(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'expiresAt' => in_array((string) $row->status, ['pending', 'authorized'], true)
                ? (string) $row->expires_at : null,
        ];
    }

    private function request(string $method, string $uri, array $options, ?array $proxy = null): ResponseInterface
    {
        try {
            return ($this->http ?? new Client())->request($method, $uri, NetworkProxyRequestOptions::apply($options + [
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 20,
                'verify' => true,
            ], $proxy));
        } catch (Throwable) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_UNAVAILABLE', 'Google 授权服务暂时不可用。', 503);
        }
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_JSON_BYTES) {
            $body .= $stream->read(min(8192, self::MAX_JSON_BYTES + 1 - strlen($body)));
        }
        try {
            $payload = strlen($body) <= self::MAX_JSON_BYTES
                ? json_decode($body, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new GoogleDriveAuthorizationFailed('GOOGLE_DRIVE_AUTHORIZATION_RESPONSE_INVALID', 'Google 授权响应无效。', 503);
        }
        return $payload;
    }

    private function clientId(string $value): bool
    {
        return strlen($value) <= 255
            && preg_match('/^[A-Za-z0-9._-]{12,220}\.apps\.googleusercontent\.com$/', $value) === 1;
    }

    private function secret(string $value): bool
    {
        return strlen($value) >= 16 && strlen($value) <= 512
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }

    private function identifier(string $value, int $maximum): bool
    {
        return $value !== '' && strlen($value) <= $maximum && !str_contains($value, "\0");
    }

    private function ulid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) === 1;
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
