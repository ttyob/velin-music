<?php
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

return [
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/webman.log',
                    7, //$maxFiles
                    Monolog\Logger::DEBUG,
                ],
                // 生产与开发统一输出单行 JSON，context 只允许调用点提供已脱敏的 request_id、job_id、
                // error_code 和聚合值；禁止把密码、令牌、路径、请求正文或 Throwable 对象放入 context。
                'formatter' => [
                    'class' => app\infrastructure\Observability\StructuredLogFormatter::class,
                    'constructor' => [],
                ],
            ],
            [
                // error 及以上日志会同步聚合到后台诊断表；handler 自身失败时不抛错、不递归记录。
                'class' => app\infrastructure\Observability\DatabaseErrorLogHandler::class,
                'constructor' => [],
            ],
        ],
    ],
];
