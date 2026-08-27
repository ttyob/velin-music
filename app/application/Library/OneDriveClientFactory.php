<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use stdClass;
use support\Db;

/**
 * 从受保护连接表构造 delegated OAuth OneDrive Graph 客户端。
 *
 * 工厂不缓存客户端或令牌，长驻 Worker 每项任务都会读取当前 refresh token 密文，因此授权轮换在下一
 * 任务生效。客户端收到 Microsoft 新 refresh token 时，回调只在旧密文仍匹配时条件替换，避免并发刷新
 * 把较新的 grant 覆盖为较旧值。解密或条件更新失败均关闭当前读取；token 与稳定 drive ID 不进入任务、
 * 日志或浏览器响应。
 */
final readonly class OneDriveClientFactory
{
    public function __construct(
        private OneDriveOAuthCipher $cipher = new OneDriveOAuthCipher(),
        private ?ClientInterface $http = null,
    ) {
    }

    /** 为一个已证明是 OneDrive 来源的库建立客户端。 */
    public function forLibrary(string $libraryId): OneDriveClient
    {
        /** @var stdClass|null $row */
        $row = Db::table('onedrive_library_connections as connection')
            ->join('music_libraries as library', 'library.id', '=', 'connection.library_id')
            ->where('connection.library_id', $libraryId)->first([
                'connection.tenant_id', 'connection.client_id', 'connection.refresh_token_ciphertext',
                'connection.drive_id', 'connection.remote_root_path', 'connection.authorization_status',
                'library.proxy_profile_id',
            ]);
        if (!$row instanceof stdClass) {
            throw new OneDriveUnavailable('ONEDRIVE_CONFIGURATION_MISSING', 'OneDrive 连接配置不存在。');
        }
        try {
            if ((string) $row->authorization_status !== 'authorized') {
                throw new \RuntimeException('reauthorization required');
            }
            $ciphertext = (string) $row->refresh_token_ciphertext;
            $refreshToken = $this->cipher->decryptRefreshToken($ciphertext);
        } catch (\Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_REAUTHORIZATION_REQUIRED', 'OneDrive 授权不可用，请重新授权。');
        }
        return $this->forConfiguration(
            (string) $row->tenant_id,
            (string) $row->client_id,
            $refreshToken,
            (string) $row->drive_id,
            (string) $row->remote_root_path,
            function (string $rotatedRefreshToken) use (&$ciphertext, $libraryId): void {
                $replacement = $this->cipher->encryptRefreshToken($rotatedRefreshToken);
                $changed = Db::table('onedrive_library_connections')->where('library_id', $libraryId)
                    ->where('authorization_status', 'authorized')
                    ->where('refresh_token_ciphertext', $ciphertext)->update([
                        'refresh_token_ciphertext' => $replacement,
                        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                if ($changed !== 1) throw new \RuntimeException('OneDrive refresh token changed concurrently.');
                $ciphertext = $replacement;
            },
            proxy: $row->proxy_profile_id === null ? null
                : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id),
        );
    }

    /** 使用尚未持久化的表单事实测试连接，不写数据库或审计。 */
    public function forConfiguration(
        string $tenantId,
        string $clientId,
        string $refreshToken,
        string $driveId,
        string $remoteRootPath,
        ?\Closure $refreshTokenRotated = null,
        ?string $initialAccessToken = null,
        int $initialAccessTokenExpiresAt = 0,
        ?array $proxy = null,
    ): OneDriveClient {
        return new OneDriveClient(
            $tenantId,
            $clientId,
            $refreshToken,
            $driveId,
            $remoteRootPath,
            $this->http ?? new Client(),
            $refreshTokenRotated,
            $initialAccessToken,
            $initialAccessTokenExpiresAt,
            $proxy,
        );
    }
}
