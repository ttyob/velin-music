<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 一次性 Google Drive OAuth 授权的服务端明文视图。
 *
 * 仅音乐库管理服务可在连接预检附近持有本对象；client secret 与 refresh token 使用后必须尽快
 * `sodium_memzero`。密文用于事务内转存，账号稳定 ID 只用于防止重复根，不得进入管理投影或审计。
 */
final class GoogleDriveAuthorizationGrant
{
    public function __construct(
        public string $authorizationId,
        public string $clientId,
        public string $clientSecret,
        public string $clientSecretCiphertext,
        public string $refreshToken,
        public string $refreshTokenCiphertext,
        public string $accountId,
        public ?string $proxyProfileId,
    ) {
    }
}
