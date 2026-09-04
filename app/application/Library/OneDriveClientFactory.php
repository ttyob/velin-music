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

    /**
     * 为一个已证明是 OneDrive 来源的库建立客户端。
     *
     * 客户端每次从连接表读取当前授权密文，并使用库当前保存的代理；调用方不得把返回客户端跨任务缓存。
     */
    public function forLibrary(string $libraryId): OneDriveClient
    {
        return $this->buildLibraryClient($libraryId, null, false);
    }

    /**
     * 使用库中已有 OAuth 授权、但以调用方提供的代理建立 OneDrive 客户端。
     *
     * 该入口只用于代理变更尚未提交时的连接预检：授权身份、refresh token 和远端根仍来自受保护连接表，
     * 只有网络出口由参数覆盖。代理参数必须已经由 NetworkProxyProfileService 校验，方法不会写数据库；
     * OneDrive 返回新 refresh token 时仍使用旧密文条件更新，避免预检并发覆盖较新的授权。
     *
     * @param array{scheme:string,host:string,port:int,username:string,password:string}|null $proxy
     */
    public function forLibraryUsingProxy(string $libraryId, ?array $proxy): OneDriveClient
    {
        return $this->buildLibraryClient($libraryId, $proxy, true);
    }

    /**
     * 从受保护连接表读取授权事实并构造客户端。
     *
     * `$overrideProxy` 用于区分“使用库当前代理”和“明确使用 null 表示直连”，否则关闭代理时无法安全
     * 覆盖旧值。读取、解密和客户端构造失败均不暴露令牌；客户端生命周期结束后由客户端尽力清零秘密。
     *
     * @param array{scheme:string,host:string,port:int,username:string,password:string}|null $proxy
     */
    private function buildLibraryClient(string $libraryId, ?array $proxy, bool $overrideProxy): OneDriveClient
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
        $effectiveProxy = $overrideProxy ? $proxy : ($row->proxy_profile_id === null ? null
            : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id));
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
            proxy: $effectiveProxy,
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
