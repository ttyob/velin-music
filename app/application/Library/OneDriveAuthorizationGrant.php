<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 承载已经绑定 actor 且尚未消费的 OneDrive delegated grant。
 *
 * refresh token 仅在音乐库连接预检和同事务消费之间短暂存在，不得序列化到 HTTP、日志、审计或任务；
 * account/drive ID 只用于稳定远端身份。消费仍由服务层条件更新确认，本 DTO 本身不是一次性语义的证明。
 */
final class OneDriveAuthorizationGrant
{
    public function __construct(
        public string $authorizationId,
        public string $tenantId,
        public string $clientId,
        public string $refreshToken,
        public string $refreshTokenCiphertext,
        public string $accountId,
        public string $driveId,
        public ?string $proxyProfileId,
    ) {
    }
}
