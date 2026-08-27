<?php

declare(strict_types=1);

namespace app\application\Artwork;

use Closure;

/**
 * 约束内置音乐平台返回的歌曲、专辑封面或艺人资料图地址，并把 DNS 结果固定到一次 HTTPS 请求。
 *
 * 地址只能来自代码内置的平台 CDN 域名；历史适配器返回的 HTTP 地址会在校验域名后升级为 HTTPS，
 * 浏览器、数据库和环境变量都不能扩展白名单。解析时要求全部 A/AAAA 记录都是公开地址，随后调用方用
 * CURLOPT_RESOLVE 固定首个地址。MusicBrainz 封面允许 CAA 到 Internet Archive 的固定两跳，并在每跳
 * 重新执行本策略；其他来源仍禁止重定向。解析失败不会产生网络请求之外的副作用，单个平台失败由上层隔离。
 */
final readonly class ArtworkRemoteHostPolicy
{
    /** @var array<string,list<string>> */
    private const HOST_PATTERNS = [
        'netease' => ['~^p\d+\.music\.126\.net$~'],
        'qq' => ['~^y\.gtimg\.cn$~', '~^qpic\.y\.qq\.com$~'],
        'kugou' => ['~^(?:imge|imgessl)\.kugou\.com$~'],
        'kuwo' => ['~^img\d*\.kuwo\.cn$~', '~^star\.kuwo\.cn$~'],
        'migu' => ['~^d\.musicapp\.migu\.cn$~', '~^cdnmusic\.migu\.cn$~', '~^freetyst\.nf\.migu\.cn$~'],
        'soda' => ['~^[a-z0-9-]+\.douyinpic\.com$~', '~^[a-z0-9-]+\.byteimg\.com$~'],
        'apple_music' => ['~^is\d+(?:-ssl)?\.mzstatic\.com$~'],
        'musicbrainz' => ['~^coverartarchive\.org$~', '~^archive\.org$~',
            '~^[a-z0-9-]+\.(?:ca|us)\.archive\.org$~'],
        // 插件只返回固定内置平台的地址，仍沿用同一组 CDN/CAA 主机白名单，不接受插件自定义域名。
        'metadata-scrape' => ['~^p\d+\.music\.126\.net$~', '~^y\.gtimg\.cn$~', '~^qpic\.y\.qq\.com$~',
            '~^(?:imge|imgessl)\.kugou\.com$~', '~^img\d*\.kuwo\.cn$~', '~^star\.kuwo\.cn$~',
            '~^d\.musicapp\.migu\.cn$~', '~^cdnmusic\.migu\.cn$~', '~^freetyst\.nf\.migu\.cn$~',
            '~^[a-z0-9-]+\.douyinpic\.com$~', '~^[a-z0-9-]+\.byteimg\.com$~',
            '~^is\d+(?:-ssl)?\.mzstatic\.com$~', '~^coverartarchive\.org$~', '~^archive\.org$~',
            '~^[a-z0-9-]+\.(?:ca|us)\.archive\.org$~'],
    ];

    /**
     * @param Closure(string):list<string>|null $resolver 测试可注入无网络解析器；生产默认读取 A/AAAA。
     */
    public function __construct(private ?Closure $resolver = null)
    {
    }

    /**
     * 返回规范 HTTPS 地址和 cURL 可固定的公开 IP。
     *
     * 查询串由固定 Provider 生成且最多 1000 字节；认证信息、非 443 端口、片段、IP 字面量和未知域名
     * 一律拒绝。任一 DNS 答案属于私网、保留、回环或链路本地时整体失败关闭，不能挑一个公开答案继续。
     *
     * @return array{url:string,host:string,ip:string}
     */
    public function resolve(string $source, string $url): array
    {
        $normalized = $this->normalize($source, $url);
        $host = (string) parse_url($normalized, PHP_URL_HOST);
        $ips = $this->resolver instanceof Closure
            ? ($this->resolver)($host)
            : $this->systemAddresses($host);
        if (!is_array($ips) || $ips === []) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_HOST_UNRESOLVED', true, 10);
        }
        $public = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_HOST_BLOCKED', false);
            }
            $public[] = $ip;
        }
        return ['url' => $normalized, 'host' => $host, 'ip' => $public[0]];
    }

    /** 只执行静态 URL 与固定平台域名校验，不进行 DNS 或 HTTP。 */
    private function normalize(string $source, string $url): string
    {
        $url = trim($url);
        if (!isset(self::HOST_PATTERNS[$source]) || $url === '' || strlen($url) > 2_000) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_URL_INVALID', false);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_URL_INVALID', false);
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !array_filter(self::HOST_PATTERNS[$source], static fn (string $pattern): bool => preg_match($pattern, $host) === 1)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_HOST_BLOCKED', false);
        }
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        if ($path === '' || strlen($query) > 1_000
            || ($this->isMusicBrainzHost($host) && !$this->isMusicBrainzArtworkPath($host, $path, $query))) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_URL_INVALID', false);
        }
        return 'https://' . $host . $path . $query;
    }

    private function isMusicBrainzHost(string $host): bool
    {
        return $host === 'coverartarchive.org' || $host === 'archive.org'
            || preg_match('~^[a-z0-9-]+\.(?:ca|us)\.archive\.org$~', $host) === 1;
    }

    /**
     * 只允许 CAA 正面图及其 Internet Archive 实际字节路径。
     *
     * 第一个地址由 Go 从已审核 front 图生成；后两种只可能来自 CAA 的 Location。两个重复出现的 MBID
     * 必须相同，且不接受查询参数，防止固定 archive.org 主机被扩展成任意内容下载器。
     */
    private function isMusicBrainzArtworkPath(string $host, string $path, string $query): bool
    {
        if ($query !== '') return false;
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
        if ($host === 'coverartarchive.org') {
            return preg_match('~^/release/' . $uuid . '/[0-9]+(?:-1200)?\.jpg$~', $path) === 1;
        }
        if ($host === 'archive.org') {
            $matched = preg_match('~^/download/mbid-(' . $uuid . ')/mbid-(' . $uuid
                . ')-[0-9]+(?:_thumb1200)?\.jpg$~', $path, $parts);
            return $matched === 1 && $parts[1] === $parts[2];
        }
        if (preg_match('~^[a-z0-9-]+\.(?:ca|us)\.archive\.org$~', $host) === 1) {
            $matched = preg_match('~^/[0-9]+/items/mbid-(' . $uuid . ')/mbid-(' . $uuid
                . ')-[0-9]+(?:_thumb1200)?\.jpg$~', $path, $parts);
            return $matched === 1 && $parts[1] === $parts[2];
        }
        return false;
    }

    /** @return list<string> */
    private function systemAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) return [];
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $ips[] = $ip;
        }
        return array_values(array_unique($ips));
    }
}
