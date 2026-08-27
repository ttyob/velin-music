<?php

declare(strict_types=1);

use app\application\Library\WebDavClient;
use app\application\Library\WebDavObject;
use app\application\Library\WebDavRangeProxyServer;
use app\application\Library\OneDriveClient;
use app\application\Library\GoogleDriveClient;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * 该入口只由远端 Range 会话启动。秘密配置来自匿名标准输入且限制为 64 KiB；任何启动失败
 * 都只返回固定 ERROR，不打印异常、配置或远端响应。进程不加载 Webman 数据库，也不继承业务事务。
 */
$token = $argv[1] ?? '';
$input = stream_get_contents(STDIN, 65_537);
try {
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1
        || !is_string($input) || strlen($input) > 65_536) {
        throw new RuntimeException('invalid input');
    }
    $configuration = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($configuration)) throw new RuntimeException('invalid configuration');
    $sourceType = (string) ($configuration['sourceType'] ?? 'webdav');
    $client = $sourceType === 'google_drive'
        ? new GoogleDriveClient(
            (string) ($configuration['clientId'] ?? ''),
            (string) ($configuration['clientSecret'] ?? ''),
            (string) ($configuration['refreshToken'] ?? ''),
            (string) ($configuration['driveId'] ?? ''),
            (string) ($configuration['remoteRootPath'] ?? ''),
            initialAccessToken: is_string($configuration['accessToken'] ?? null)
                ? $configuration['accessToken'] : null,
            initialAccessTokenExpiresAt: (int) ($configuration['accessTokenExpiresAt'] ?? 0),
            proxy: is_array($configuration['proxy'] ?? null) ? $configuration['proxy'] : null,
        )
        : ($sourceType === 'onedrive' ? new OneDriveClient(
            (string) ($configuration['tenantId'] ?? ''),
            (string) ($configuration['clientId'] ?? ''),
            (string) ($configuration['refreshToken'] ?? ''),
            (string) ($configuration['driveId'] ?? ''),
            (string) ($configuration['remoteRootPath'] ?? ''),
            initialAccessToken: is_string($configuration['accessToken'] ?? null)
                ? $configuration['accessToken'] : null,
            initialAccessTokenExpiresAt: (int) ($configuration['accessTokenExpiresAt'] ?? 0),
            proxy: is_array($configuration['proxy'] ?? null) ? $configuration['proxy'] : null,
        ) : new WebDavClient(
            (string) ($configuration['baseUrl'] ?? ''),
            (string) ($configuration['remoteRootPath'] ?? ''),
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
            ($configuration['verifyTls'] ?? null) === true,
            proxy: is_array($configuration['proxy'] ?? null) ? $configuration['proxy'] : null,
        ));
    $object = new WebDavObject(
        (string) ($configuration['relativePath'] ?? ''),
        false,
        (int) ($configuration['size'] ?? 0),
        (int) ($configuration['modifiedAt'] ?? 0),
        (string) ($configuration['etag'] ?? ''),
    );
    $input = str_repeat("\0", strlen($input));
    (new WebDavRangeProxyServer(
        $client,
        $object,
        $token,
        ($configuration['continuousResponse'] ?? null) === true,
    ))->serve(static function (int $port): void {
        fwrite(STDOUT, 'READY ' . $port . "\n");
        fflush(STDOUT);
    });
} catch (Throwable) {
    fwrite(STDOUT, "ERROR\n");
    fflush(STDOUT);
    exit(1);
}
