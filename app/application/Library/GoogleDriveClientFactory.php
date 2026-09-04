<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use stdClass;
use support\Db;
use Throwable;

/**
 * 从受保护连接表构造 Google Drive 客户端；长驻 Worker 每次任务重新读取密文，不缓存 OAuth 秘密。
 *
 * 解密失败、授权状态异常或连接缺失均要求重新授权。Google 通常不轮换 refresh token，但构造参数保留
 * 条件更新边界，未来上游返回轮换 token 时不得覆盖并发保存的新 grant。
 */
final readonly class GoogleDriveClientFactory
{
    public function __construct(
        private GoogleDriveOAuthCipher $cipher = new GoogleDriveOAuthCipher(),
        private ?ClientInterface $http = null,
    ) {
    }

    public function forLibrary(string $libraryId): GoogleDriveClient
    {
        return $this->buildLibraryClient($libraryId, null, false);
    }

    /**
     * 使用库中已有 OAuth 授权、但以调用方提供的代理建立 Google Drive 客户端。
     *
     * 该入口只用于代理变更尚未提交时的连接预检：客户端密钥、refresh token、Drive 和根仍来自受保护
     * 连接表，只有网络出口由参数覆盖。代理参数必须已经由 NetworkProxyProfileService 校验，方法不会
     * 写数据库，也不会把秘密放入响应或审计。
     *
     * @param array{scheme:string,host:string,port:int,username:string,password:string}|null $proxy
     */
    public function forLibraryUsingProxy(string $libraryId, ?array $proxy): GoogleDriveClient
    {
        return $this->buildLibraryClient($libraryId, $proxy, true);
    }

    /**
     * 从受保护连接表读取授权事实并构造客户端。
     *
     * `$overrideProxy` 用于区分“使用库当前代理”和“明确使用 null 表示直连”，否则关闭代理时无法安全
     * 覆盖旧值。读取、解密和客户端构造失败均转换为稳定授权错误；客户端只在当前任务生命周期内存在。
     *
     * @param array{scheme:string,host:string,port:int,username:string,password:string}|null $proxy
     */
    private function buildLibraryClient(string $libraryId, ?array $proxy, bool $overrideProxy): GoogleDriveClient
    {
        /** @var stdClass|null $row */
        $row = Db::table('google_drive_library_connections as connection')
            ->join('music_libraries as library', 'library.id', '=', 'connection.library_id')
            ->where('connection.library_id', $libraryId)->first([
                'connection.client_id', 'connection.client_secret_ciphertext',
                'connection.refresh_token_ciphertext', 'connection.drive_id',
                'connection.remote_root_path', 'connection.authorization_status', 'library.proxy_profile_id',
            ]);
        if (!$row instanceof stdClass) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_CONFIGURATION_MISSING', 'Google Drive 连接配置不存在。');
        }
        try {
            if ((string) $row->authorization_status !== 'authorized') throw new \RuntimeException('reauthorization required');
            $clientSecret = $this->cipher->decryptClientSecret((string) $row->client_secret_ciphertext);
            $refreshToken = $this->cipher->decryptRefreshToken((string) $row->refresh_token_ciphertext);
        } catch (Throwable) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_REAUTHORIZATION_REQUIRED', 'Google Drive 授权不可用，请重新授权。');
        }
        $effectiveProxy = $overrideProxy ? $proxy : ($row->proxy_profile_id === null ? null
            : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id));
        return $this->forConfiguration(
            (string) $row->client_id,
            $clientSecret,
            $refreshToken,
            (string) $row->drive_id,
            (string) $row->remote_root_path,
            proxy: $effectiveProxy,
        );
    }

    /** 使用尚未持久化的授权事实测试连接，不写数据库或审计。 */
    public function forConfiguration(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $driveId,
        string $remoteRootPath,
        ?string $initialAccessToken = null,
        int $initialAccessTokenExpiresAt = 0,
        ?array $proxy = null,
    ): GoogleDriveClient {
        return new GoogleDriveClient(
            $clientId,
            $clientSecret,
            $refreshToken,
            $driveId,
            $remoteRootPath,
            $this->http ?? new Client(),
            initialAccessToken: $initialAccessToken,
            initialAccessTokenExpiresAt: $initialAccessTokenExpiresAt,
            proxy: $proxy,
        );
    }
}
