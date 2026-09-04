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

namespace support;

use app\http\MediaGatewayRequestIdentity;

/**
 * Velin Music 的 HTTP 请求类型，并恢复内置同源网关之前的直连来源语义。
 *
 * 普通请求沿用 Webman 行为；网关模式下只有通过用途隔离签名验证的内部来源头才能替代回环 TCP 对端。
 * 该覆盖不信任标准转发头，也不改变 Host、Cookie、Bearer 或请求正文解析。
 */
class Request extends \Webman\Http\Request
{
    /** 返回真实 TCP 对端，或经内置 Go 网关认证的原始直连 IP。 */
    public function getRemoteIp(): string
    {
        $directIp = parent::getRemoteIp();
        return (new MediaGatewayRequestIdentity())->clientIp($this, $directIp);
    }
}
