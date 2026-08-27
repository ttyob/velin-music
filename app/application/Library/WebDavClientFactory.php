<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyProfileService;
use GuzzleHttp\ClientInterface;
use stdClass;
use support\Db;

/**
 * 从受保护连接表创建一次性 WebDAV 客户端。
 *
 * 查询只按内部 library ID 进行且从不返回管理投影；密码在构造客户端前认证解密，失败统一转为远端
 * 不可用。客户端生命周期结束后尝试清零密码。工厂不缓存客户端，避免一次凭据轮换仍被长驻 Worker
 * 使用旧秘密。
 */
final readonly class WebDavClientFactory
{
    public function __construct(
        private WebDavCredentialCipher $cipher = new WebDavCredentialCipher(),
        private ?ClientInterface $http = null,
    ) {
    }

    /** 为一个已证明是 WebDAV 来源的库建立客户端。 */
    public function forLibrary(string $libraryId): WebDavClient
    {
        /** @var stdClass|null $row */
        $row = Db::table('webdav_library_connections as connection')
            ->join('music_libraries as library', 'library.id', '=', 'connection.library_id')
            ->where('connection.library_id', $libraryId)->first([
                'connection.base_url', 'connection.remote_root_path', 'connection.username',
                'connection.password_ciphertext', 'connection.verify_tls', 'library.proxy_profile_id',
            ]);
        if (!$row instanceof stdClass) {
            throw new WebDavUnavailable('WEBDAV_CONFIGURATION_MISSING', 'WebDAV 连接配置不存在。');
        }
        try {
            $password = $this->cipher->decrypt((string) $row->password_ciphertext);
        } catch (\Throwable) {
            throw new WebDavUnavailable('WEBDAV_CREDENTIAL_UNAVAILABLE', 'WebDAV 凭据不可用。');
        }
        $proxy = $row->proxy_profile_id === null ? null
            : (new NetworkProxyProfileService())->connection((string) $row->proxy_profile_id);
        return new WebDavClient(
            (string) $row->base_url,
            (string) $row->remote_root_path,
            (string) $row->username,
            $password,
            (int) $row->verify_tls === 1,
            $this->http ?? new \GuzzleHttp\Client(),
            $proxy,
        );
    }

    /** 使用尚未持久化的表单事实进行连接测试，不写数据库或审计。 */
    public function forConfiguration(
        string $baseUrl,
        string $remoteRootPath,
        string $username,
        string $password,
        bool $verifyTls,
        ?array $proxy = null,
    ): WebDavClient {
        return new WebDavClient(
            $baseUrl,
            $remoteRootPath,
            $username,
            $password,
            $verifyTls,
            $this->http ?? new \GuzzleHttp\Client(),
            $proxy,
        );
    }
}
