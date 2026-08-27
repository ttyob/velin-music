<?php

declare(strict_types=1);

namespace app\application\Artwork;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * 从固定音乐平台 CDN 读取一张有界封面或艺人资料图，并以实际签名冻结资源事实。
 *
 * 调用前由 ArtworkRemoteHostPolicy 固定公开 DNS 地址；请求禁用代理继承和自动重定向，连接与总耗时均有
 * 硬上限。MusicBrainz 的 CAA 图片允许最多两次受控手工跳转，每一跳都重新校验固定路径、解析全部 DNS
 * 并固定公开 IP。正文最多 20 MiB，只接受 JPEG/PNG/WebP 且校验 Content-Type、文件签名、边长和总像素。
 * WebP 会在内存中转成 PNG，以兼容历史候选表约束；转换后仍重新计算尺寸、字节数和 SHA-256。失败不
 * 写文件或数据库，异常只含稳定错误码，不携带 URL、响应正文或 DNS 信息。
 */
final readonly class ArtworkRemoteImageFetcher
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_DIMENSION = 8_192;
    private const MAX_PIXELS = 80_000_000;
    private ClientInterface $http;

    public function __construct(
        ?ClientInterface $http = null,
        private ArtworkRemoteHostPolicy $hosts = new ArtworkRemoteHostPolicy(),
    ) {
        $this->http = $http ?? new Client(['http_errors' => false]);
    }

    /**
     * 下载并验证图片，返回只在 Worker 内部流转的冻结字节。
     *
     * @return array{mimeType:string,width:int,height:int,sizeBytes:int,sha256:string,bytes:string}
     */
    public function fetch(string $source, string $url): array
    {
        if (!extension_loaded('curl') || !defined('CURLOPT_RESOLVE')) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_SECURE_TRANSPORT_UNAVAILABLE', true, 30);
        }
        $currentUrl = $url;
        $response = null;
        for ($redirects = 0; $redirects <= 2; ++$redirects) {
            $target = $this->hosts->resolve($source, $currentUrl);
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            try {
                $response = $this->http->request('GET', $target['url'], [
                    'allow_redirects' => false,
                    'connect_timeout' => 4.0,
                    'timeout' => 15.0,
                    'proxy' => '',
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'image/jpeg,image/png,image/webp',
                        'User-Agent' => 'Velin-Music-Artwork/1.0',
                    ],
                    'curl' => [constant('CURLOPT_RESOLVE') => [$target['host'] . ':443:' . $ip]],
                ]);
            } catch (GuzzleException) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REMOTE_UNAVAILABLE', true, 10);
            } catch (Throwable) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_TRANSPORT_FAILED', true, 10);
            }
            $status = $response->getStatusCode();
            if ($status < 300 || $status >= 400) break;
            if (!in_array($source, ['musicbrainz', 'metadata-scrape'], true) || $redirects >= 2) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REDIRECT_REJECTED', false);
            }
            $location = trim($response->getHeaderLine('Location'));
            if ($location === '') {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REDIRECT_REJECTED', false);
            }
            // 下一轮 resolve 会按目标主机重新解析并固定 IP；Location 在任何日志和持久状态中都不保留。
            $currentUrl = $location;
        }
        if ($response === null) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_TRANSPORT_FAILED', true, 10);
        }
        $status = $response->getStatusCode();
        if ($status === 429 || $status >= 500) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REMOTE_UNAVAILABLE', true, 10);
        }
        if ($status < 200 || $status >= 300) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REMOTE_REJECTED', false);
        }
        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (!ctype_digit($declaredLength) || (int) $declaredLength > self::MAX_BYTES)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_TOO_LARGE', false);
        }
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
        }
        $body = $response->getBody();
        $bytes = '';
        while (!$body->eof()) {
            $remaining = self::MAX_BYTES + 1 - strlen($bytes);
            if ($remaining <= 0) break;
            $bytes .= $body->read(min(16_384, $remaining));
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_TOO_LARGE', false);
        }
        [$width, $height, $actualMime] = $this->facts($bytes);
        if ($actualMime !== $mime) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
        }
        if ($mime === 'image/webp') {
            [$bytes, $mime] = $this->webpToPng($bytes);
            [$width, $height, $actualMime] = $this->facts($bytes);
            if ($actualMime !== $mime) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
        }
        return ['mimeType' => $mime, 'width' => $width, 'height' => $height,
            'sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'bytes' => $bytes];
    }

    /** @return array{int,int,string} */
    private function facts(string $bytes): array
    {
        $facts = @getimagesizefromstring($bytes);
        $width = is_array($facts) ? (int) ($facts[0] ?? 0) : 0;
        $height = is_array($facts) ? (int) ($facts[1] ?? 0) : 0;
        $mime = is_array($facts) ? (string) ($facts['mime'] ?? '') : '';
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
        }
        return [$width, $height, $mime];
    }

    /** @return array{string,string} 把已验真的 WebP 重编码为无元数据 PNG，避免修改既有数据库约束。 */
    private function webpToPng(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
        ob_start();
        try {
            if (!imagepng($image, null, 6)) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_INVALID', false);
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!is_string($png) || $png === '' || strlen($png) > self::MAX_BYTES) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IMAGE_TOO_LARGE', false);
        }
        return [$png, 'image/png'];
    }
}
