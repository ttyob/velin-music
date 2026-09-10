<?php

declare(strict_types=1);
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use app\application\System\PublicUrlConfig;
use Webman\Session\FileSessionHandler;
use Webman\Session\RedisSessionHandler;
use Webman\Session\RedisClusterSessionHandler;

$sessionLifetime = 7 * 24 * 60 * 60;
$secureCookie = PublicUrlConfig::sessionCookieSecure();
/*
 * Docker 镜像携带固定标记并在 host 网络中使用仓库内 Redis；因此容器默认选择 Redis Session，裸机开发
 * 仍默认使用文件 Session。部署者无需再用 Compose environment 重复声明固定拓扑；显式配置仅保留给
 * 裸机或测试覆盖。标记只影响默认值，Redis 不可用时仍按既有失败语义暴露健康错误，不静默降级到文件。
 */
$defaultSessionType = is_file(base_path('.velin-container')) ? 'redis' : 'file';
$sessionType = getenv('VELIN_SESSION_DRIVER') ?: $defaultSessionType;
if (!in_array($sessionType, ['file', 'redis', 'redis_cluster'], true)) {
    $sessionType = 'file';
}
$sessionHandler = match ($sessionType) {
    'redis' => RedisSessionHandler::class,
    'redis_cluster' => RedisClusterSessionHandler::class,
    default => FileSessionHandler::class,
};

return [

    // Compose uses Redis so sessions survive backend worker replacement; bare metal stays file-based.
    'type' => $sessionType,

    'handler' => $sessionHandler,

    'config' => [
        'file' => [
            'save_path' => runtime_path() . '/sessions',
        ],
        'redis' => [
            'host' => getenv('VELIN_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('VELIN_REDIS_PORT') ?: 27379),
            'auth' => getenv('VELIN_REDIS_PASSWORD') ?: '',
            'timeout' => (float) (getenv('VELIN_REDIS_TIMEOUT') ?: 2),
            'database' => (int) (getenv('VELIN_REDIS_DATABASE') ?: 0),
            'prefix' => getenv('VELIN_REDIS_SESSION_PREFIX') ?: 'velin_session:',
        ],
        'redis_cluster' => [
            'host' => ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7001'],
            'timeout' => 2,
            'auth' => '',
            'prefix' => 'redis_session_',
        ]
    ],

    'session_name' => 'VELIN_SID',

    'auto_update_timestamp' => false,

    'lifetime' => $sessionLifetime,

    'cookie_lifetime' => $sessionLifetime,

    'cookie_path' => '/',

    'domain' => '',

    'http_only' => true,

    'secure' => $secureCookie,

    'same_site' => 'Lax',

    'gc_probability' => [1, 1000],

];
