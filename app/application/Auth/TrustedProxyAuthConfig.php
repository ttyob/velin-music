<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * 解析并执行可信反向代理认证的部署边界（AUTH-006、API-AUTH-006、ND-209）。
 *
 * 配置只来自进程环境，浏览器和代理请求不能覆盖。来源判断只使用 Webman 连接对象报告的直连 IP，
 * 绝不读取 `X-Forwarded-For` 等可由客户端伪造的转发链。启用时必须至少配置一个合法 IP/CIDR，
 * 身份头名也必须符合 HTTP token 语法；错误配置直接失败关闭，不能退化为信任所有来源。
 */
final readonly class TrustedProxyAuthConfig
{
    /** @var list<array{packed:string,prefix:int}> */
    private array $networks;

    /**
     * 构造已经过边界验证的代理认证配置。
     *
     * `$trustedNetworks` 使用逗号分隔 IPv4/IPv6 地址或 CIDR。禁用状态不解析网络，便于默认安全部署；
     * 启用状态的任一非法项都会抛错并使整个认证方式不可用，避免管理员误以为部分配置已生效。
     *
     * @throws TrustedProxyAuthUnavailable 启用配置缺失或格式无效
     */
    public function __construct(
        public bool $enabled,
        public string $identityHeader,
        string $trustedNetworks,
    ) {
        if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]{1,64}$/", $identityHeader) !== 1) {
            throw new TrustedProxyAuthUnavailable('可信代理身份头配置无效。');
        }
        if (!$enabled) {
            $this->networks = [];
            return;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $trustedNetworks)), static fn (string $item): bool => $item !== ''));
        if ($items === [] || count($items) > 64) {
            throw new TrustedProxyAuthUnavailable('可信代理来源范围未正确配置。');
        }
        $networks = [];
        foreach ($items as $item) {
            $networks[] = $this->parseNetwork($item);
        }
        $this->networks = $networks;
    }

    /**
     * 从部署环境创建配置快照。
     *
     * 仅字面值 `true` 启用功能，其他值全部按关闭处理。每个 Webman 请求读取一次快照，使容器重启后的
     * 配置明确生效；运行中改变环境并不受支持，也不会产生一半 Worker 使用旧边界的状态。
     */
    public static function fromEnvironment(): self
    {
        return new self(
            strtolower(trim((string) getenv('VELIN_TRUSTED_PROXY_AUTH_ENABLED'))) === 'true',
            trim((string) (getenv('VELIN_TRUSTED_PROXY_AUTH_HEADER') ?: 'X-Remote-User')),
            (string) getenv('VELIN_TRUSTED_PROXY_AUTH_CIDRS'),
        );
    }

    /**
     * 判断直连对端是否落在任一显式可信网络内。
     *
     * 输入必须是单个 IP 字面量；主机名、端口、zone ID、转发链和 IPv4/IPv6 混合比较均拒绝。
     * 本方法无网络查询和持久化副作用，配置为空时始终返回 false。
     */
    public function trusts(string $remoteIp): bool
    {
        $packedIp = @inet_pton($remoteIp);
        if ($packedIp === false) return false;
        foreach ($this->networks as $network) {
            if (strlen($network['packed']) !== strlen($packedIp)) continue;
            $wholeBytes = intdiv($network['prefix'], 8);
            $remainingBits = $network['prefix'] % 8;
            if ($wholeBytes > 0 && substr($network['packed'], 0, $wholeBytes) !== substr($packedIp, 0, $wholeBytes)) continue;
            if ($remainingBits === 0) return true;
            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if ((ord($network['packed'][$wholeBytes]) & $mask) === (ord($packedIp[$wholeBytes]) & $mask)) return true;
        }
        return false;
    }

    /** @return array{packed:string,prefix:int} */
    private function parseNetwork(string $value): array
    {
        if (substr_count($value, '/') > 1) throw new TrustedProxyAuthUnavailable('可信代理 CIDR 配置无效。');
        [$address, $prefixText] = array_pad(explode('/', $value, 2), 2, null);
        $packed = @inet_pton($address);
        if ($packed === false) throw new TrustedProxyAuthUnavailable('可信代理 IP 配置无效。');
        $maximum = strlen($packed) * 8;
        $prefix = $prefixText === null ? $maximum : (ctype_digit($prefixText) ? (int) $prefixText : -1);
        if ($prefix < 0 || $prefix > $maximum) throw new TrustedProxyAuthUnavailable('可信代理 CIDR 前缀无效。');
        return ['packed' => $packed, 'prefix' => $prefix];
    }
}
