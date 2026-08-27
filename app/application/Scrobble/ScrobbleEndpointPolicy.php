<?php

declare(strict_types=1);

namespace app\application\Scrobble;

/**
 * 校验 Maloja 自定义 HTTPS 端点并在请求前固定一个公开地址。
 *
 * 只允许 443 端口、无认证信息的 HTTPS 根地址，拒绝 IP 字面量、localhost、私网、回环、链路本地和
 * 同时返回公私地址的主机。投递层把解析结果交给 cURL CURLOPT_RESOLVE，从 DNS 校验到 TLS 请求始终使用
 * 同一 IP，且禁用重定向，从而关闭 DNS rebinding 和二次跳转绕过。固定 Last.fm/ListenBrainz 端点不
 * 接收用户 URL，因此不经过本策略。
 */
final class ScrobbleEndpointPolicy
{
    /** 规范化保存值；不执行 DNS，避免连接编辑因临时解析故障不可保存。 */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) throw new ScrobbleInvalid('Maloja 地址无效。');
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new ScrobbleInvalid('Maloja 仅支持 HTTPS 地址。');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($host === '' || $host === 'localhost' || $port !== 443 || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || str_contains($host, '..')) {
            throw new ScrobbleInvalid('Maloja 地址无效。');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return 'https://' . $host . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    /**
     * 解析并返回 cURL 可固定的公开 IP；任一私有结果都会让整个请求失败关闭。
     *
     * @return array{host: string, ip: string}
     */
    public function resolve(string $normalizedUrl): array
    {
        $url = $this->normalize($normalizedUrl);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) throw new ScrobbleDeliveryFailure('SCROBBLE_ENDPOINT_UNRESOLVED', true);
        $public = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ScrobbleDeliveryFailure('SCROBBLE_ENDPOINT_BLOCKED', false);
            }
            $public[] = $ip;
        }

        return ['host' => $host, 'ip' => $public[0]];
    }
}
