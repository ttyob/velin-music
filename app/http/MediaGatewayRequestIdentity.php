<?php

declare(strict_types=1);

namespace app\http;

use support\Request;

/**
 * 验证内置 Go 网关传递的直连客户端 IP，保持可信代理认证的原有来源边界。
 *
 * 网关成为 Webman 唯一上游后，TCP 对端固定为回环地址。Go 会清除客户端同名头，并使用用途隔离 HMAC
 * 签名实际对端 IP 和当前秒；这里仅在网关已启用、Webman 直连对端为 loopback、时间偏差不超过十五秒
 * 且签名完全匹配时采用该 IP。任何缺失或伪造都回退真实 TCP 对端，不读取 X-Forwarded-For，也不记录
 * 头值、密钥或来源地址。
 */
final class MediaGatewayRequestIdentity
{
    private const HKDF_INFO = 'velin-media-gateway-request-key-v1';

    private bool $enabled;
    private string $key;
    private \Closure $clock;

    /** 测试可注入开关、密钥和时钟；生产默认读取部署配置并派生用途隔离子密钥。 */
    public function __construct(?bool $enabled = null, ?string $rootSecret = null, ?\Closure $clock = null)
    {
        $this->enabled = $enabled ?? (bool) config('media_delivery.enabled', false);
        $this->key = hash_hkdf(
            'sha256',
            $rootSecret ?? RequestContext::authenticationHashKey(),
            32,
            self::HKDF_INFO,
        );
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** 返回经过网关认证的客户端 IP，或保持 Webman 实际 TCP 对端。 */
    public function clientIp(Request $request, string $directIp): string
    {
        if (!$this->enabled || !in_array($directIp, ['127.0.0.1', '::1'], true)) {
            return $directIp;
        }
        $clientIp = $request->header('x-velin-gateway-client-ip');
        $timestamp = $request->header('x-velin-gateway-timestamp');
        $signature = $request->header('x-velin-gateway-signature');
        if (!is_string($clientIp) || @inet_pton($clientIp) === false || !is_string($timestamp)
            || preg_match('/^(?:0|[1-9]\d{0,10})$/D', $timestamp) !== 1 || !is_string($signature)) {
            return $directIp;
        }
        $seconds = (int) $timestamp;
        if (abs(($this->clock)() - $seconds) > 15) return $directIp;
        $expected = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            "v1\n{$timestamp}\n{$clientIp}",
            $this->key,
            true,
        )), '+/', '-_'), '=');
        return hash_equals($expected, $signature) ? $clientIp : $directIp;
    }
}
