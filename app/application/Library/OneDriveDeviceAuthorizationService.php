<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use app\application\System\NetworkProxyRequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 编排 Microsoft OAuth 2.0 Device Code Flow，并把完成的 delegated grant 交给音乐库服务一次性消费。
 *
 * 授权会话绑定发起管理员；start 与 poll 都要求 Controller 先验证 `manage_library` 和 CSRF。服务端持久化
 * Microsoft 指定的最早轮询时间，避免刷新页面或并发请求突破上游 interval；同一 actor 十分钟最多启动
 * 五次。浏览器仅获得 user code、固定 Microsoft 验证地址和状态，永不获得 device/access/refresh token、
 * account ID、drive ID 或 UPN。所有 HTTP 异常和原始响应均收敛为稳定错误，不进入日志。
 *
 * 成功授权只接受企业 `business` drive。refresh token 先以用途隔离密文写入授权会话，音乐库创建或重新
 * 授权在自己的数据库事务中条件消费；消费后会话中的全部秘密和身份立即清空。远端 OAuth 与 Graph 请求
 * 无法随 SQLite 回滚，失败重试依靠未消费会话；授权服务本身绝不撤销或修改远端文件，后续写入只能由
 * 已授权资源插件任务经核心发布账本执行。
 */
final readonly class OneDriveDeviceAuthorizationService
{
    private const SCOPES = 'offline_access Files.ReadWrite User.Read';
    private const MAX_JSON_BYTES = 1_048_576;

    public function __construct(
        private OneDriveOAuthCipher $cipher = new OneDriveOAuthCipher(),
        private ?ClientInterface $http = null,
    ) {
    }

    /**
     * 向固定租户端点申请 device code，并创建绑定 actor 的短命会话。
     *
     * @param array<string,mixed> $actor 已通过 `manage_library` 校验的当前用户快照
     * @return array<string,mixed> 可安全显示的设备登录指令
     */
    public function start(string $tenantId, string $clientId, array $actor, ?string $proxyProfileId = null): array
    {
        $tenantId = strtolower(trim($tenantId));
        $clientId = strtolower(trim($clientId));
        $actorId = (string) ($actor['id'] ?? '');
        if (!$this->uuid($tenantId) || !$this->uuid($clientId) || !$this->identifier($actorId, 64)) {
            throw new OneDriveAuthorizationFailed('ONEDRIVE_AUTHORIZATION_INPUT_INVALID', '租户 ID 或客户端 ID 无效。');
        }
        $tenMinutesAgo = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
        $recent = Db::table('onedrive_device_authorizations')->where('actor_user_id', $actorId)
            ->where('created_at', '>=', $tenMinutesAgo)->count();
        if ($recent >= 5) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_RATE_LIMITED', '授权请求过于频繁，请稍后重试。', 429,
            );
        }

        $proxy = $proxyProfileId === null ? null : (new NetworkProxyProfileService())->connection($proxyProfileId);
        try {
            $response = $this->request('POST', $this->loginEndpoint($tenantId, 'devicecode'), [
                'form_params' => ['client_id' => $clientId, 'scope' => self::SCOPES],
            ], 'ONEDRIVE_AUTHORIZATION_START_FAILED', $proxy);
        } finally {
            if (is_array($proxy) && $proxy['password'] !== '') sodium_memzero($proxy['password']);
        }
        if ($response->getStatusCode() !== 200) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_START_FAILED', 'Microsoft 暂时无法启动 OneDrive 授权。', 503,
            );
        }
        $payload = $this->json($response, 'ONEDRIVE_AUTHORIZATION_START_FAILED');
        $deviceCode = $payload['device_code'] ?? null;
        $userCode = $payload['user_code'] ?? null;
        $verificationUri = $payload['verification_uri'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        $interval = $payload['interval'] ?? 5;
        if (!is_string($deviceCode) || $deviceCode === '' || strlen($deviceCode) > 8192
            || !is_string($userCode) || preg_match('/^[A-Z0-9-]{4,24}$/i', $userCode) !== 1
            || !$this->verificationUri($verificationUri)
            || !is_int($expiresIn) || $expiresIn < 60 || $expiresIn > 1800
            || !is_int($interval) || $interval < 1 || $interval > 60) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_RESPONSE_INVALID', 'Microsoft 返回了无效的授权指令。', 503,
            );
        }
        $id = (string) new Ulid();
        $now = time();
        Db::table('onedrive_device_authorizations')->insert([
            'id' => $id,
            'actor_user_id' => $actorId,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'device_code_ciphertext' => $this->cipher->encryptDeviceCode($deviceCode),
            'user_code' => strtoupper($userCode),
            'verification_uri' => $verificationUri,
            'status' => 'pending',
            'interval_seconds' => $interval,
            'next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', $now + $interval),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + $expiresIn),
            'refresh_token_ciphertext' => null,
            'account_id' => null,
            'drive_id' => null,
            'consumed_at' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'proxy_profile_id' => $proxyProfileId,
        ]);
        sodium_memzero($deviceCode);
        return $this->projection($id, 'pending', strtoupper($userCode), $verificationUri, $now + $expiresIn, $interval);
    }

    /**
     * 按持久化 interval 推进一次授权；过早轮询仅返回 pending，不访问 Microsoft。
     *
     * Microsoft 的 `authorization_pending` 不改变间隔，`slow_down` 永久增加五秒；拒绝、到期以及未开启
     * public client flow 都会清除 device code。Microsoft 对客户端类型错误可能返回 400 或 401，两者都只
     * 读取稳定 OAuth error 字段，不向日志或浏览器透传 description。并发 poll 先以条件更新抢占下一轮询
     * 时间，只有一个请求能出站，其余看到 pending 快照。
     *
     * @param array<string,mixed> $actor 当前已授权管理员
     * @return array<string,mixed> 不含任何 OAuth 凭据或稳定 Microsoft 身份的状态投影
     */
    public function poll(string $authorizationId, array $actor): array
    {
        $actorId = (string) ($actor['id'] ?? '');
        $row = $this->authorization($authorizationId, $actorId);
        $proxy = $row->proxy_profile_id === null ? null
            : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id);
        $now = time();
        if ((string) $row->status !== 'pending') return $this->rowProjection($row);
        if (strtotime((string) $row->expires_at) <= $now) {
            $this->finishWithoutGrant($authorizationId, $actorId, 'expired');
            return $this->projection($authorizationId, 'expired', null, null, null, (int) $row->interval_seconds);
        }
        if (strtotime((string) $row->next_poll_at) > $now) return $this->rowProjection($row);

        $nextPollAt = gmdate('Y-m-d\TH:i:s\Z', $now + (int) $row->interval_seconds);
        $claimed = Db::table('onedrive_device_authorizations')->where('id', $authorizationId)
            ->where('actor_user_id', $actorId)->where('status', 'pending')
            ->where('next_poll_at', '<=', gmdate('Y-m-d\TH:i:s\Z', $now))->update([
                'next_poll_at' => $nextPollAt,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            ]);
        if ($claimed !== 1) return $this->rowProjection($this->authorization($authorizationId, $actorId));

        try {
            $deviceCode = $this->cipher->decryptDeviceCode((string) $row->device_code_ciphertext);
            $response = $this->request('POST', $this->loginEndpoint((string) $row->tenant_id, 'token'), [
                'form_params' => [
                    'client_id' => (string) $row->client_id,
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                    'device_code' => $deviceCode,
                ],
            ], 'ONEDRIVE_AUTHORIZATION_POLL_FAILED', $proxy);
        } finally {
            if (isset($deviceCode) && is_string($deviceCode) && $deviceCode !== '') sodium_memzero($deviceCode);
        }
        if (in_array($response->getStatusCode(), [400, 401], true)) {
            return $this->handleTokenError($row, $response, $actorId);
        }
        if ($response->getStatusCode() !== 200) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_POLL_FAILED', 'Microsoft 暂时无法确认 OneDrive 授权。', 503,
            );
        }

        $payload = $this->json($response, 'ONEDRIVE_AUTHORIZATION_RESPONSE_INVALID');
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 65_536
            || !is_string($refreshToken) || $refreshToken === '' || strlen($refreshToken) > 65_536) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_RESPONSE_INVALID', 'Microsoft 返回了无效的授权结果。', 503,
            );
        }
        try {
            [$accountId, $driveId] = $this->enterpriseIdentity($accessToken, $proxy);
            $refreshCiphertext = $this->cipher->encryptRefreshToken($refreshToken);
        } finally {
            sodium_memzero($accessToken);
            sodium_memzero($refreshToken);
            if (is_array($proxy) && $proxy['password'] !== '') sodium_memzero($proxy['password']);
        }
        $updated = Db::table('onedrive_device_authorizations')->where('id', $authorizationId)
            ->where('actor_user_id', $actorId)->where('status', 'pending')->update([
                'status' => 'authorized',
                'refresh_token_ciphertext' => $refreshCiphertext,
                'account_id' => $accountId,
                'drive_id' => $driveId,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        if ($updated !== 1) {
            throw new OneDriveAuthorizationFailed('ONEDRIVE_AUTHORIZATION_CONFLICT', '授权状态已经变化，请重新开始。', 409);
        }
        return $this->projection($authorizationId, 'authorized', null, null, null, (int) $row->interval_seconds);
    }

    /** 返回一个尚未消费的 grant；调用者使用完明文后必须尽快清零。 */
    public function completedGrant(string $authorizationId, array $actor): OneDriveAuthorizationGrant
    {
        $actorId = (string) ($actor['id'] ?? '');
        $row = $this->authorization($authorizationId, $actorId);
        if ((string) $row->status === 'authorized' && strtotime((string) $row->expires_at) <= time()) {
            Db::table('onedrive_device_authorizations')->where('id', $authorizationId)
                ->where('actor_user_id', $actorId)->where('status', 'authorized')->update([
                    'status' => 'expired',
                    'device_code_ciphertext' => null,
                    'refresh_token_ciphertext' => null,
                    'account_id' => null,
                    'drive_id' => null,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_EXPIRED', 'OneDrive 授权已过期，请重新授权。', 409,
            );
        }
        if ((string) $row->status !== 'authorized' || $row->consumed_at !== null
            || !is_string($row->refresh_token_ciphertext) || !is_string($row->account_id) || !is_string($row->drive_id)) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_NOT_READY', '请先完成 Microsoft 授权。', 409,
            );
        }
        try {
            $refreshToken = $this->cipher->decryptRefreshToken($row->refresh_token_ciphertext);
        } catch (Throwable) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_UNAVAILABLE', 'OneDrive 授权凭据不可用，请重新授权。', 409,
            );
        }
        return new OneDriveAuthorizationGrant(
            (string) $row->id,
            (string) $row->tenant_id,
            (string) $row->client_id,
            $refreshToken,
            $row->refresh_token_ciphertext,
            $row->account_id,
            $row->drive_id,
            $row->proxy_profile_id === null ? null : (string) $row->proxy_profile_id,
        );
    }

    /**
     * 在音乐库写事务内消费 grant 并清除会话秘密。
     *
     * 条件更新同时校验 actor、状态与未消费标记；并发创建只有一个成功，失败随外层事务回滚。调用者必须
     * 已把 refresh token 密文复制到目标连接行，且不得在独立事务外提前消费。
     */
    public function consume(string $authorizationId, array $actor): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $changed = Db::table('onedrive_device_authorizations')->where('id', $authorizationId)
            ->where('actor_user_id', (string) ($actor['id'] ?? ''))->where('status', 'authorized')
            ->whereNull('consumed_at')->update([
                'status' => 'consumed',
                'device_code_ciphertext' => null,
                'refresh_token_ciphertext' => null,
                'account_id' => null,
                'drive_id' => null,
                'consumed_at' => $now,
                'updated_at' => $now,
            ]);
        if ($changed !== 1) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_AUTHORIZATION_CONSUMED', '该 OneDrive 授权已经使用，请重新授权。', 409,
            );
        }
    }

    /** @return array{0:string,1:string} */
    private function enterpriseIdentity(string $accessToken, ?array $proxy): array
    {
        $headers = ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'];
        $accountResponse = $this->request('GET', 'https://graph.microsoft.com/v1.0/me', [
            'headers' => $headers, 'query' => ['$select' => 'id'],
        ], 'ONEDRIVE_ACCOUNT_LOOKUP_FAILED', $proxy);
        $driveResponse = $this->request('GET', 'https://graph.microsoft.com/v1.0/me/drive', [
            'headers' => $headers, 'query' => ['$select' => 'id,driveType'],
        ], 'ONEDRIVE_DRIVE_LOOKUP_FAILED', $proxy);
        if ($accountResponse->getStatusCode() !== 200 || $driveResponse->getStatusCode() !== 200) {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_DELEGATED_PERMISSION_REQUIRED', '授权账号缺少 OneDrive 受控读写权限。', 422,
            );
        }
        $account = $this->json($accountResponse, 'ONEDRIVE_ACCOUNT_LOOKUP_FAILED');
        $drive = $this->json($driveResponse, 'ONEDRIVE_DRIVE_LOOKUP_FAILED');
        $accountId = $account['id'] ?? null;
        $driveId = $drive['id'] ?? null;
        if (!$this->identifier($accountId, 1024) || !$this->identifier($driveId, 1024)
            || ($drive['driveType'] ?? null) !== 'business') {
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_ENTERPRISE_ACCOUNT_REQUIRED', '请使用 Microsoft 365 企业版 OneDrive 账号授权。', 422,
            );
        }
        return [$accountId, $driveId];
    }

    /**
     * 处理 token 端点的稳定 OAuth 错误，不读取或透传 Microsoft 的 description。
     *
     * `invalid_client|unauthorized_client` 在 device code 已成功签发后表示应用不允许作为公共客户端兑换
     * token，继续轮询不会恢复，因此清除 device code 并返回可操作错误。pending/slow_down 保留会话；
     * 用户拒绝和代码过期进入终态。其他 400/401 只返回稳定拒绝错误，避免外部正文进入日志或响应。
     */
    private function handleTokenError(stdClass $row, ResponseInterface $response, string $actorId): array
    {
        $payload = $this->json($response, 'ONEDRIVE_AUTHORIZATION_RESPONSE_INVALID');
        $error = $payload['error'] ?? null;
        $id = (string) $row->id;
        if ($error === 'authorization_pending') return $this->rowProjection($this->authorization($id, $actorId));
        if ($error === 'slow_down') {
            $interval = min(60, (int) $row->interval_seconds + 5);
            Db::table('onedrive_device_authorizations')->where('id', $id)->where('actor_user_id', $actorId)
                ->where('status', 'pending')->update([
                    'interval_seconds' => $interval,
                    'next_poll_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $interval),
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            return $this->rowProjection($this->authorization($id, $actorId));
        }
        if ($error === 'authorization_declined') {
            $this->finishWithoutGrant($id, $actorId, 'denied');
            return $this->projection($id, 'denied', null, null, null, (int) $row->interval_seconds);
        }
        if ($error === 'expired_token' || $error === 'bad_verification_code') {
            $this->finishWithoutGrant($id, $actorId, 'expired');
            return $this->projection($id, 'expired', null, null, null, (int) $row->interval_seconds);
        }
        if ($error === 'invalid_client' || $error === 'unauthorized_client') {
            $this->finishWithoutGrant($id, $actorId, 'denied');
            throw new OneDriveAuthorizationFailed(
                'ONEDRIVE_PUBLIC_CLIENT_FLOW_REQUIRED',
                'Entra 应用未允许公共客户端流，请在“身份验证 > 高级设置”中开启后重新授权。',
                422,
            );
        }
        throw new OneDriveAuthorizationFailed(
            'ONEDRIVE_AUTHORIZATION_REJECTED', 'Microsoft 拒绝了 OneDrive 授权，请重新开始。', 422,
        );
    }

    /** 以单次更新终止未完成会话，并立即删除 device code 密文。 */
    private function finishWithoutGrant(string $id, string $actorId, string $status): void
    {
        Db::table('onedrive_device_authorizations')->where('id', $id)->where('actor_user_id', $actorId)
            ->where('status', 'pending')->update([
                'status' => $status,
                'device_code_ciphertext' => null,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
    }

    /** 读取 actor 自己的会话；不存在、越权和已消费统一为不可枚举 404。 */
    private function authorization(string $id, string $actorId): stdClass
    {
        if (!Ulid::isValid($id) || !$this->identifier($actorId, 64)) {
            throw new OneDriveAuthorizationFailed('ONEDRIVE_AUTHORIZATION_NOT_FOUND', 'OneDrive 授权不存在。', 404);
        }
        /** @var stdClass|null $row */
        $row = Db::table('onedrive_device_authorizations')->where('id', $id)
            ->where('actor_user_id', $actorId)->whereNull('consumed_at')->first();
        if (!$row instanceof stdClass) {
            throw new OneDriveAuthorizationFailed('ONEDRIVE_AUTHORIZATION_NOT_FOUND', 'OneDrive 授权不存在。', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function rowProjection(stdClass $row): array
    {
        $pending = (string) $row->status === 'pending';
        return $this->projection(
            (string) $row->id,
            (string) $row->status,
            $pending ? (string) $row->user_code : null,
            $pending ? (string) $row->verification_uri : null,
            $pending ? strtotime((string) $row->expires_at) : null,
            (int) $row->interval_seconds,
        );
    }

    /** @return array<string,mixed> */
    private function projection(
        string $id,
        string $status,
        ?string $userCode,
        ?string $verificationUri,
        ?int $expiresAt,
        int $interval,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'userCode' => $userCode,
            'verificationUri' => $verificationUri,
            'expiresAt' => $expiresAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'intervalSeconds' => $interval,
        ];
    }

    /** 固定 TLS/主机请求；异常对象可能含 URL，因此不向上保留原异常消息。 */
    private function request(string $method, string $url, array $options, string $errorCode, ?array $proxy = null): ResponseInterface
    {
        try {
            return ($this->http ?? new Client())->request($method, $url, NetworkProxyRequestOptions::apply($options + [
                'verify' => true,
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 20,
                'http_errors' => false,
            ], $proxy));
        } catch (Throwable) {
            throw new OneDriveAuthorizationFailed($errorCode, 'Microsoft 授权服务暂时不可用。', 503);
        }
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response, string $errorCode): array
    {
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_JSON_BYTES) {
            $body .= $stream->read(min(8192, self::MAX_JSON_BYTES + 1 - strlen($body)));
        }
        try {
            $payload = strlen($body) <= self::MAX_JSON_BYTES
                ? json_decode($body, true, 24, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new OneDriveAuthorizationFailed($errorCode, 'Microsoft 授权响应无效。', 503);
        }
        return $payload;
    }

    private function loginEndpoint(string $tenantId, string $suffix): string
    {
        return 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/' . $suffix;
    }

    /**
     * 只接受 Microsoft Device Code Flow 当前及兼容期内使用的固定验证入口。
     *
     * Microsoft 可能返回旧入口 `microsoft.com/devicelogin`，也可能返回新版
     * `login.microsoft.com/device`。浏览器会直接打开该地址，因此这里按 scheme、host、path
     * 精确匹配，拒绝端口、用户信息、查询串、片段和相似后缀域，避免上游异常响应把管理员
     * 引导到非 Microsoft 站点。新增入口必须先通过真实端点的脱敏契约探测，再显式加入列表。
     */
    private function verificationUri(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 512) return false;
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['port'], $parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            return false;
        }
        $endpoint = strtolower((string) ($parts['host'] ?? '')) . rtrim((string) ($parts['path'] ?? ''), '/');
        return in_array($endpoint, [
            'microsoft.com/devicelogin',
            'www.microsoft.com/devicelogin',
            'login.microsoft.com/device',
        ], true);
    }

    private function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }

    private function identifier(mixed $value, int $maximumBytes): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximumBytes
            && !str_contains($value, "\0") && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
