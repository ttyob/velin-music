<?php

declare(strict_types=1);

namespace app\application\System;

/**
 * 把已由 NetworkProxyProfileService 校验并解密的连接转换为单次 Guzzle 请求选项。
 *
 * 用户名和密码按 URI user-info 规则编码，避免特殊字符改变代理地址结构。profile 为空时显式写入空
 * `proxy`，阻止 Guzzle/cURL 继承宿主环境代理；非空时所有 HTTP 与 HTTPS 请求都使用同一明确连接。
 * 返回值可能包含明文密码，只能保存在当前请求内存或 Range helper stdin，不得记录、审计或响应。
 */
final class NetworkProxyRequestOptions
{
    /**
     * @param array<string,mixed> $options
     * @param array{scheme:string,host:string,port:int,username:string,password:string}|null $proxy
     * @return array<string,mixed>
     */
    public static function apply(array $options, ?array $proxy): array
    {
        if ($proxy === null) {
            $options['proxy'] = '';
            return $options;
        }
        $credentials = $proxy['username'] === '' ? ''
            : rawurlencode($proxy['username']) . ':' . rawurlencode($proxy['password']) . '@';
        $host = str_contains($proxy['host'], ':') ? '[' . $proxy['host'] . ']' : $proxy['host'];
        $options['proxy'] = $proxy['scheme'] . '://' . $credentials . $host . ':' . $proxy['port'];
        return $options;
    }
}
