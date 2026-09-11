<?php

declare(strict_types=1);

namespace app\application\System;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use JsonException;
use Throwable;

/**
 * 从固定 GitHub 官方仓库读取最新稳定版本，并生成浏览器可安全展示的投影。
 *
 * 服务不接受 URL、仓库或代理参数：DNS 解析结果必须是公网地址，请求固定首个地址且禁止重定向和环境
 * 代理，避免该只读能力演变为 SSRF。成功结果按 Worker 缓存，失败也短暂缓存以保护 GitHub 匿名限额；
 * 缓存只存在进程内，不写数据库或运行目录。上游异常、超时、超限、非法 JSON 或链接越界均失败关闭，
 * 调用方只得到统一不可用异常，不会看到上游正文、地址解析结果或连接细节。
 */
final class ReleaseUpdateService
{
    private const API_URL = 'https://api.github.com/repos/ttyob/velin-music/releases/latest';
    private const API_HOST = 'api.github.com';
    private const MAX_RESPONSE_BYTES = 524_288;
    private const MAX_NOTES_BYTES = 16_000;
    private const SUCCESS_TTL_SECONDS = 10_800;
    private const FAILURE_TTL_SECONDS = 300;

    /** @var array<string,array{expiresAt:int,checkedAt:string,release:?array<string,mixed>,failed:bool}> */
    private static array $cache = [];

    private readonly string $currentVersion;
    private readonly Closure $clock;

    /**
     * @param Closure(string):list<string>|null $resolver 测试可注入确定性 DNS；生产默认只接受公网 A/AAAA。
     * @param Closure():int|null $clock 测试时控制缓存时钟，生产使用 Unix 时间。
     */
    public function __construct(
        private readonly ?ClientInterface $http = null,
        private readonly ?Closure $resolver = null,
        ?string $currentVersion = null,
        private readonly string $cacheKey = self::API_URL,
        ?Closure $clock = null,
    ) {
        $configured = trim($currentVersion ?? (string) (getenv('VELIN_VERSION') ?: '0.1.0-dev'));
        $this->currentVersion = self::safeText($configured, 80, '0.1.0-dev');
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * 返回当前版本与最新官方稳定 Release。
     *
     * 只有当前镜像版本和官方标签都满足严格 SemVer 时才比较；开发构建仍返回官方发布信息，但永不触发
     * 自动弹窗，避免本地 `dev` 或未知构建被错误判断为旧版。该方法只读网络与进程缓存，没有持久副作用。
     *
     * @return array{currentVersion:string,checkedAt:string,updateAvailable:bool,release:array<string,mixed>}
     */
    public function check(): array
    {
        $now = ($this->clock)();
        $cached = self::$cache[$this->cacheKey] ?? null;
        if ($cached === null || $cached['expiresAt'] <= $now) {
            try {
                $release = $this->fetchRelease();
                $cached = [
                    'expiresAt' => $now + self::SUCCESS_TTL_SECONDS,
                    'checkedAt' => gmdate('c', $now),
                    'release' => $release,
                    'failed' => false,
                ];
            } catch (Throwable) {
                $cached = [
                    'expiresAt' => $now + self::FAILURE_TTL_SECONDS,
                    'checkedAt' => gmdate('c', $now),
                    'release' => null,
                    'failed' => true,
                ];
            }
            self::$cache[$this->cacheKey] = $cached;
        }
        if ($cached['failed'] || !is_array($cached['release'])) {
            throw new ReleaseUpdateUnavailable();
        }

        $latestVersion = (string) $cached['release']['version'];
        $updateAvailable = self::stableVersion($this->currentVersion) !== null
            && version_compare($latestVersion, $this->currentVersion, '>');

        return [
            'currentVersion' => $this->currentVersion,
            'checkedAt' => $cached['checkedAt'],
            'updateAvailable' => $updateAvailable,
            'release' => $cached['release'],
        ];
    }

    /** @return array<string,mixed> */
    private function fetchRelease(): array
    {
        $ip = $this->resolveOfficialHost();
        if (!extension_loaded('curl') || !defined('CURLOPT_RESOLVE')) {
            throw new ReleaseUpdateUnavailable();
        }
        try {
            $response = ($this->http ?? new Client(['http_errors' => false]))->request('GET', self::API_URL, [
                'allow_redirects' => false,
                'connect_timeout' => 2.0,
                'timeout' => 4.0,
                'proxy' => '',
                'http_errors' => false,
                'stream' => true,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'Velin-Music-Release-Check/1',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'curl' => [CURLOPT_RESOLVE => [self::API_HOST . ':443:' . $ip]],
            ]);
        } catch (Throwable $exception) {
            throw new ReleaseUpdateUnavailable(previous: $exception);
        }
        if ($response->getStatusCode() !== 200) {
            throw new ReleaseUpdateUnavailable();
        }
        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (!ctype_digit($declaredLength) || (int) $declaredLength > self::MAX_RESPONSE_BYTES)) {
            throw new ReleaseUpdateUnavailable();
        }

        $body = $response->getBody();
        $json = '';
        while (!$body->eof()) {
            $chunk = $body->read(min(65_536, self::MAX_RESPONSE_BYTES + 1 - strlen($json)));
            if ($chunk === '') break;
            $json .= $chunk;
            if (strlen($json) > self::MAX_RESPONSE_BYTES) throw new ReleaseUpdateUnavailable();
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ReleaseUpdateUnavailable(previous: $exception);
        }
        if (!is_array($decoded)) throw new ReleaseUpdateUnavailable();
        return $this->normalizeRelease($decoded);
    }

    /** @return list<string> */
    private function systemAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) return [];
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $addresses[] = $ip;
        }
        return array_values(array_unique($addresses));
    }

    private function resolveOfficialHost(): string
    {
        $addresses = $this->resolver instanceof Closure
            ? ($this->resolver)(self::API_HOST)
            : $this->systemAddresses(self::API_HOST);
        if (!is_array($addresses) || $addresses === []) throw new ReleaseUpdateUnavailable();
        foreach ($addresses as $ip) {
            if (!is_string($ip)
                || filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ReleaseUpdateUnavailable();
            }
        }
        return $addresses[0];
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function normalizeRelease(array $source): array
    {
        if (($source['draft'] ?? null) !== false || ($source['prerelease'] ?? null) !== false) {
            throw new ReleaseUpdateUnavailable();
        }
        $tag = is_string($source['tag_name'] ?? null) ? $source['tag_name'] : '';
        $version = self::stableVersion($tag, true);
        if ($version === null) throw new ReleaseUpdateUnavailable();
        $releasePageUrl = $this->releasePageUrl($source['html_url'] ?? null, $tag);
        $publishedAt = $this->publishedAt($source['published_at'] ?? null);
        $name = self::safeText(is_string($source['name'] ?? null) ? trim($source['name']) : '', 160, "Velin Music {$version}");
        $notes = self::safeText(is_string($source['body'] ?? null) ? trim($source['body']) : '', self::MAX_NOTES_BYTES, '');

        $assets = [];
        foreach (is_array($source['assets'] ?? null) ? array_slice($source['assets'], 0, 30) : [] as $asset) {
            $normalized = $this->normalizeAsset($asset, $tag);
            if ($normalized !== null) $assets[] = $normalized;
        }
        $fpk = null;
        foreach ($assets as $asset) {
            if (preg_match('/\.fpk$/iD', $asset['name']) === 1) {
                $fpk = $asset;
                break;
            }
        }

        return [
            'version' => $version,
            'tag' => $tag,
            'name' => $name,
            'publishedAt' => $publishedAt,
            'notes' => $notes,
            'releasePageUrl' => $releasePageUrl,
            'fpkDownloadUrl' => $fpk['url'] ?? null,
            'fpkFileName' => $fpk['name'] ?? null,
            'assets' => $assets,
        ];
    }

    /** @return array{name:string,url:string,sizeBytes:int,contentType:string}|null */
    private function normalizeAsset(mixed $source, string $tag): ?array
    {
        if (!is_array($source) || !is_string($source['name'] ?? null)
            || !is_string($source['browser_download_url'] ?? null)) return null;
        $name = trim($source['name']);
        if ($name === '' || strlen($name) > 180 || basename($name) !== $name || preg_match('//u', $name) !== 1) return null;
        $url = $source['browser_download_url'];
        if (!$this->officialUrl($url, '/ttyob/velin-music/releases/download/' . rawurlencode($tag) . '/', $name)) return null;
        $size = $source['size'] ?? null;
        if (!is_int($size) || $size < 0) return null;
        $contentType = self::safeText(is_string($source['content_type'] ?? null) ? $source['content_type'] : '', 120, 'application/octet-stream');
        return ['name' => $name, 'url' => $url, 'sizeBytes' => $size, 'contentType' => $contentType];
    }

    private function releasePageUrl(mixed $value, string $tag): string
    {
        if (!is_string($value)
            || !$this->officialUrl($value, '/ttyob/velin-music/releases/tag/', $tag)) {
            throw new ReleaseUpdateUnavailable();
        }
        return $value;
    }

    private function officialUrl(string $url, string $pathPrefix, string $tail): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])) return false;
        $path = (string) ($parts['path'] ?? '');
        return str_starts_with($path, $pathPrefix)
            && rawurldecode(substr($path, strlen($pathPrefix))) === $tail;
    }

    private function publishedAt(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
            throw new ReleaseUpdateUnavailable();
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new ReleaseUpdateUnavailable(previous: $exception);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function stableVersion(string $value, bool $requireTag = false): ?string
    {
        $pattern = $requireTag
            ? '/^v((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D'
            : '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D';
        return preg_match($pattern, $value, $matches) === 1 ? $matches[1] : null;
    }

    private static function safeText(string $value, int $maxBytes, string $fallback): string
    {
        if (preg_match('//u', $value) !== 1) return $fallback;
        if (strlen($value) <= $maxBytes) return $value;
        $truncated = substr($value, 0, $maxBytes);
        while ($truncated !== '' && preg_match('//u', $truncated) !== 1) {
            $truncated = substr($truncated, 0, -1);
        }
        return rtrim($truncated);
    }
}
