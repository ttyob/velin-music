<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyRequestOptions;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Closure;
use Throwable;

/**
 * 通过 Microsoft Graph delegated grant 读写一个固定企业 OneDrive drive 根。
 *
 * 认证使用 Device Code Flow 已取得的 refresh token，只请求 `offline_access Files.ReadWrite User.Read`；本类
 * 不启动交互登录，也不接受客户端密钥或 UPN。Graph Bearer 只发送到固定登录/Graph 主机，下载
 * 重定向先关闭自动跟随后验证为 Microsoft 文件域，再以无 Authorization 的请求读取。目录分页、对象名、
 * ETag、时间和 Content-Range 均失败关闭，任一异常都转换为不含 UPN、URL、令牌或响应正文的稳定错误。
 *
 * 调用方必须在数据库事务外使用本客户端。refresh token 轮换回调必须以旧密文条件更新数据库；回调失败
 * 时认证失败关闭，避免继续使用未持久化的新 grant。refresh/access token 析构时尽力清零，但 PHP/Guzzle
 * 内部复制不构成可证明的完整内存擦除保证。
 */
final class OneDriveClient implements RemoteLibraryClient, WritableRemoteLibraryClient
{
    private const MAX_JSON_BYTES = 8_388_608;
    private const MAX_PAGES = 1000;
    private const DOWNLOAD_LOCATION_TTL_SECONDS = 300;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    /** @var array{objectKey:string,url:string,expiresAt:int}|null */
    private ?array $downloadLocationCache = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private string $refreshToken,
        private readonly string $driveId,
        private readonly string $remoteRootPath,
        private readonly ClientInterface $http = new Client(),
        private readonly ?Closure $refreshTokenRotated = null,
        ?string $initialAccessToken = null,
        int $initialAccessTokenExpiresAt = 0,
        private readonly ?array $proxy = null,
    ) {
        if (!$this->isUuid($tenantId) || !$this->isUuid($clientId)
            || !$this->validIdentifier($driveId, 1024) || !$this->validRoot($remoteRootPath)
            || $refreshToken === '' || strlen($refreshToken) > 65_536
            || ($initialAccessToken !== null && ($initialAccessToken === '' || strlen($initialAccessToken) > 65_536))) {
            throw new OneDriveUnavailable('ONEDRIVE_CONFIGURATION_INVALID', 'OneDrive 企业版连接配置无效。');
        }
        if (is_string($initialAccessToken) && $initialAccessTokenExpiresAt > time() + 30) {
            $this->accessToken = $initialAccessToken;
            $this->tokenExpiresAt = $initialAccessTokenExpiresAt;
        }
    }

    /** 尽力清除长期 refresh token 和缓存 access token。 */
    public function __destruct()
    {
        $this->downloadLocationCache = null;
        if ($this->refreshToken !== '') sodium_memzero($this->refreshToken);
        if (is_string($this->accessToken) && $this->accessToken !== '') sodium_memzero($this->accessToken);
    }

    /** 返回统一远端来源键。 */
    public function sourceType(): string
    {
        return 'onedrive';
    }

    /** 验证应用权限、目标用户盘和配置根当前均可读取。 */
    public function assertConnection(): void
    {
        $root = $this->fetchItem('');
        if (!$root->directory || $root->relativePath !== '') {
            throw new OneDriveUnavailable('ONEDRIVE_ROOT_INVALID', 'OneDrive 根目录不存在或不是目录。');
        }
    }

    /**
     * 返回目录自身与全部直接子项；Graph 分页逐页验证且总页数有界。
     *
     * @return list<WebDavObject>
     */
    public function listDirectory(string $relativeDirectory): array
    {
        $relativeDirectory = $this->assertRelativePath($relativeDirectory, true);
        $self = $this->fetchItem($relativeDirectory);
        if (!$self->directory) throw new OneDriveUnavailable('ONEDRIVE_ROOT_INVALID', 'OneDrive 目录不存在。');
        $objects = [$relativeDirectory => $self];
        $uri = $this->childrenEndpoint($relativeDirectory);
        for ($page = 0; $uri !== null; ++$page) {
            if ($page >= self::MAX_PAGES) {
                throw new OneDriveUnavailable('ONEDRIVE_PAGE_LIMIT_EXCEEDED', 'OneDrive 目录分页超过安全限制。');
            }
            $response = $this->graph('GET', $uri, $page === 0 ? [
                'query' => ['$top' => 200, '$select' => 'name,size,eTag,lastModifiedDateTime,folder,file'],
            ] : []);
            $payload = $this->json($response, 'ONEDRIVE_RESPONSE_INVALID');
            $items = $payload['value'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 目录响应无效。');
            }
            foreach ($items as $item) {
                if (!is_array($item)) throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 目录响应无效。');
                $name = $this->validChildName($item['name'] ?? null);
                $relative = $relativeDirectory === '' ? $name : $relativeDirectory . '/' . $name;
                if (isset($objects[$relative])) {
                    throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 目录响应包含重复对象。');
                }
                $objects[$relative] = $this->mapItem($item, $relative);
            }
            $next = $payload['@odata.nextLink'] ?? null;
            $uri = $next === null ? null : $this->validatedNextLink($next);
        }
        ksort($objects, SORT_STRING);
        return array_values($objects);
    }

    /**
     * 以 Graph 冲突策略 fail 发布文件；小文件直接 PUT，大文件使用固定 10 MiB 分片 upload session。
     *
     * 目录逐级创建且重名必须证明为目录。最终对象存在时只在 Graph 返回 SHA-256 或 SHA-1 且与本地内容
     * 一致时恢复成功；仅大小或 ETag 不足以证明所有权。uploadUrl 必须是受信 Microsoft HTTPS 文件域，
     * 请求不携带 Graph Bearer。任一分片失败保留本地源和 session 供服务端过期回收，不删除最终对象。
     */
    public function publishFile(string $relativePath, string $localPath, int $size, string $sha256): RemotePublishedObject
    {
        $relativePath = $this->assertRelativePath($relativePath, false);
        if (!is_file($localPath) || is_link($localPath) || !is_readable($localPath) || filesize($localPath) !== $size
            || $size < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_SOURCE_INVALID', 'OneDrive 上传源文件无效。');
        }
        $segments = explode('/', $relativePath);
        array_pop($segments);
        $directory = '';
        foreach ($segments as $segment) {
            $parent = $directory;
            $directory = $directory === '' ? $segment : $directory . '/' . $segment;
            $this->ensureWritableDirectory($parent, $segment, $directory);
        }
        $existing = $this->publishedVersion($relativePath, $localPath, $size, $sha256);
        if ($existing !== null) return new RemotePublishedObject(
            $relativePath, $size, $sha256, $existing['version'], $existing['etag'],
        );

        if ($size <= 250 * 1024 * 1024) {
            $input = @fopen($localPath, 'rb');
            if ($input === false) throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_SOURCE_INVALID', 'OneDrive 上传源文件无效。');
            try {
                $response = $this->graphWrite('PUT', $this->contentEndpoint($relativePath), [
                    'query' => ['@microsoft.graph.conflictBehavior' => 'fail'],
                    'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $size],
                    'body' => $input, 'timeout' => 900,
                ], [200, 201, 409]);
            } finally {
                // Guzzle 在部分真实 handler 中会随请求体 Stream 一并关闭底层资源；重复 fclose 在 PHP 8
                // 会抛出 TypeError 并掩盖已经成功的远端 PUT，因此只关闭仍由本调用持有的活动资源。
                if (is_resource($input)) fclose($input);
            }
            if ($response->getStatusCode() === 409) {
                $existing = $this->publishedVersion($relativePath, $localPath, $size, $sha256);
                if ($existing === null) throw new RemotePublicationConflict();
                return new RemotePublishedObject(
                    $relativePath, $size, $sha256, $existing['version'], $existing['etag'],
                );
            }
        } else {
            $this->uploadLargeFile($relativePath, $localPath, $size);
        }
        $version = $this->publishedVersion($relativePath, $localPath, $size, $sha256);
        if ($version === null) throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_VERIFY_FAILED', 'OneDrive 上传校验失败。');
        return new RemotePublishedObject($relativePath, $size, $sha256, $version['version'], $version['etag']);
    }

    /** 幂等创建目录；409 后必须重新读取并证明目标是唯一目录，不能把同名文件当作父级。 */
    private function ensureWritableDirectory(string $parent, string $name, string $relativePath): void
    {
        $current = $this->itemMetadataOrNull($relativePath);
        if (is_array($current)) {
            if (!is_array($current['folder'] ?? null)) throw new RemotePublicationConflict();
            return;
        }
        $response = $this->graphWrite('POST', $this->childrenEndpoint($parent), [
            'json' => ['name' => $name, 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'],
        ], [201, 409]);
        $createdId = null;
        if ($response->getStatusCode() === 201) {
            $created = $this->json($response, 'ONEDRIVE_RESPONSE_INVALID');
            $createdId = is_string($created['id'] ?? null) ? $created['id'] : null;
            if ($createdId === null || !$this->validIdentifier($createdId, 1024)) {
                throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 目录创建响应无效。');
            }
        }
        $current = $this->itemMetadataOrNull($relativePath);
        if (!is_array($current) || !is_array($current['folder'] ?? null)
            || ($createdId !== null && ($current['id'] ?? null) !== $createdId)) {
            throw new RemotePublicationConflict();
        }
    }

    /** 使用 upload session 顺序发送 10 MiB（320 KiB 整数倍）分片，响应区间必须单调推进。 */
    private function uploadLargeFile(string $relativePath, string $localPath, int $size): void
    {
        $sessionResponse = $this->graphWrite('POST', $this->itemEndpoint($relativePath) . ':/createUploadSession', [
            'json' => ['item' => ['@microsoft.graph.conflictBehavior' => 'fail']],
        ], [200, 409]);
        if ($sessionResponse->getStatusCode() === 409) throw new RemotePublicationConflict();
        $session = $this->json($sessionResponse, 'ONEDRIVE_UPLOAD_SESSION_INVALID');
        $uploadUrl = $session['uploadUrl'] ?? null;
        if (!is_string($uploadUrl) || !$this->validUploadUrl($uploadUrl)) {
            throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_SESSION_INVALID', 'OneDrive 上传会话无效。');
        }
        $input = @fopen($localPath, 'rb');
        if ($input === false) throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_SOURCE_INVALID', 'OneDrive 上传源文件无效。');
        $chunkSize = 10 * 1024 * 1024;
        try {
            for ($offset = 0; $offset < $size;) {
                $length = min($chunkSize, $size - $offset);
                $body = fread($input, $length);
                if (!is_string($body) || strlen($body) !== $length) {
                    throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_SOURCE_CHANGED', 'OneDrive 上传期间源文件发生变化。');
                }
                try {
                    $response = $this->http->request('PUT', $uploadUrl, $this->plainOptions([
                        'headers' => ['Content-Length' => (string) $length,
                            'Content-Range' => 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size],
                        'body' => $body, 'timeout' => 900,
                    ]));
                } catch (Throwable) {
                    throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_FAILED', 'OneDrive 分片上传失败。');
                }
                if (!in_array($response->getStatusCode(), [200, 201, 202], true)) {
                    throw $this->writeFailure($response->getStatusCode());
                }
                $offset += $length;
                if ($offset < $size && $response->getStatusCode() !== 202) {
                    throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_RESPONSE_INVALID', 'OneDrive 分片上传响应无效。');
                }
            }
        } finally {
            fclose($input);
        }
    }

    /**
     * 读取最终对象并证明幂等身份；404 表示尚未发布。
     *
     * OneDrive Personal 常返回 SHA-1，部分 Graph 环境返回 SHA-256；SharePoint/E5 通常只返回不具备
     * 抗碰撞性质的 quickXorHash。强摘要存在时直接比较；只有强摘要完全缺失时，才通过带 ETag 的受信
     * Microsoft 下载地址流式回读完整对象并计算 SHA-256。回读不把正文放入内存，也不携带 Graph
     * Bearer；大小、Range、对象版本或摘要任一不一致都按外部同名对象冲突处理，绝不覆盖。
     */
    /** @return array{version:string,etag:string}|null */
    private function publishedVersion(string $relativePath, string $localPath, int $size, string $sha256): ?array
    {
        $item = $this->itemMetadataOrNull($relativePath);
        if ($item === null) return null;
        if (is_array($item['folder'] ?? null) || !is_array($item['file'] ?? null)
            || ($item['size'] ?? null) !== $size) throw new RemotePublicationConflict();
        $hashes = is_array($item['file']['hashes'] ?? null) ? $item['file']['hashes'] : [];
        $remoteSha256 = is_string($hashes['sha256Hash'] ?? null) ? strtolower($hashes['sha256Hash']) : '';
        $remoteSha1 = is_string($hashes['sha1Hash'] ?? null) ? strtolower($hashes['sha1Hash']) : '';
        if (($remoteSha256 !== '' && preg_match('/^[a-f0-9]{64}$/D', $remoteSha256) !== 1)
            || ($remoteSha1 !== '' && preg_match('/^[a-f0-9]{40}$/D', $remoteSha1) !== 1)) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 对象摘要无效。');
        }
        $matches = $remoteSha256 !== ''
            ? hash_equals($sha256, $remoteSha256)
            : ($remoteSha1 !== ''
                ? hash_equals((string) hash_file('sha1', $localPath), $remoteSha1)
                : $this->remoteSha256Matches($this->mapItem($item, $relativePath), $size, $sha256));
        if (!$matches) throw new RemotePublicationConflict();
        $etag = is_string($item['eTag'] ?? null) ? $item['eTag'] : '';
        return [
            'version' => hash('sha256', $relativePath . "\0" . $size . "\0" . $sha256 . "\0" . $etag),
            'etag' => $etag,
        ];
    }

    /** SharePoint 未提供强摘要时，按完整 Range 流计算远端 SHA-256，不信任 QuickXor 或仅大小相等。 */
    private function remoteSha256Matches(WebDavObject $object, int $expectedSize, string $expectedSha256): bool
    {
        $location = $this->downloadLocation($object);
        try {
            $response = $this->http->request('GET', $location, $this->plainOptions([
                'headers' => ['Range' => 'bytes=0-' . ($expectedSize - 1)],
                'stream' => true, 'timeout' => 900,
            ]));
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_VERIFY_FAILED', 'OneDrive 上传校验失败。');
        }
        $range = trim($response->getHeaderLine('Content-Range'));
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($response->getStatusCode() !== 206
            || $range !== 'bytes 0-' . ($expectedSize - 1) . '/' . $expectedSize
            || ($length !== '' && (preg_match('/^\d+$/D', $length) !== 1 || (int) $length !== $expectedSize))) {
            $response->getBody()->close();
            throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_VERIFY_FAILED', 'OneDrive 上传校验失败。');
        }
        $hash = hash_init('sha256');
        $read = 0;
        $stream = $response->getBody();
        try {
            while (!$stream->eof() && $read <= $expectedSize) {
                $chunk = $stream->read(min(1_048_576, $expectedSize + 1 - $read));
                if ($chunk === '' && !$stream->eof()) {
                    throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_VERIFY_FAILED', 'OneDrive 上传校验失败。');
                }
                $read += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            $stream->close();
        }
        return $read === $expectedSize && hash_equals($expectedSha256, hash_final($hash));
    }

    /** @return array<string,mixed>|null */
    private function itemMetadataOrNull(string $relativePath): ?array
    {
        $response = $this->graphWrite('GET', $this->itemEndpoint($relativePath), [
            'query' => ['$select' => 'id,name,size,eTag,lastModifiedDateTime,folder,file'],
        ], [200, 404]);
        return $response->getStatusCode() === 404 ? null : $this->json($response, 'ONEDRIVE_RESPONSE_INVALID');
    }

    /** 固定 Graph 主机写请求，合并 Bearer 而不允许调用方 headers 抹去认证边界。 */
    private function graphWrite(string $method, string $uri, array $options, array $success): ResponseInterface
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $options['headers'] = $headers + ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'];
        try {
            $response = $this->http->request($method, $uri, $this->plainOptions($options));
        } catch (OneDriveUnavailable $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_UPLOAD_FAILED', '无法连接 Microsoft Graph 完成上传。');
        }
        if (in_array($response->getStatusCode(), $success, true)) return $response;
        throw $this->writeFailure($response->getStatusCode());
    }

    private function writeFailure(int $status): OneDriveUnavailable
    {
        return match ($status) {
            401, 403 => new OneDriveUnavailable('ONEDRIVE_WRITE_AUTH_FAILED', 'OneDrive 授权没有写入权限，请重新授权。'),
            429 => new OneDriveUnavailable('ONEDRIVE_RATE_LIMITED', 'OneDrive 请求受到限流，请稍后重试。'),
            default => new OneDriveUnavailable('ONEDRIVE_UPLOAD_FAILED', 'OneDrive 暂时无法完成安全上传。'),
        };
    }

    private function validUploadUrl(string $value): bool
    {
        if (strlen($value) > 16_384) return false;
        $parts = parse_url($value);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $host !== '' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && !isset($parts['port']) && $this->allowedDownloadHost($host);
    }

    /**
     * 打开一个最多由调用方限制的字节区间。
     *
     * Graph `/content` 请求携带 If-Match 并关闭重定向；Location 通过域名白名单后才以无 Bearer 请求读取。
     * 只有严格匹配对象总大小和请求边界的 206 响应会交给调用方。
     */
    public function openRange(WebDavObject $object, int $start, ?int $end = null): WebDavRangeResponse
    {
        $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $start < 0 || $start >= $object->size
            || ($end !== null && ($end < $start || $end >= $object->size))) {
            throw new OneDriveUnavailable('ONEDRIVE_RANGE_INVALID', 'OneDrive 字节区间无效。');
        }
        $location = $this->downloadLocation($object);
        try {
            $response = $this->http->request('GET', $location, $this->plainOptions([
                'headers' => ['Range' => 'bytes=' . $start . '-' . ($end ?? '')],
                'stream' => true,
                'timeout' => 60,
            ]));
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_RANGE_FAILED', 'OneDrive 按需读取失败。');
        }
        if ($response->getStatusCode() !== 206
            || preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', trim($response->getHeaderLine('Content-Range')), $match) !== 1) {
            $response->getBody()->close();
            throw new OneDriveUnavailable('ONEDRIVE_RANGE_UNSUPPORTED', 'OneDrive 服务未返回有效按需读取响应。');
        }
        $actualStart = (int) $match[1];
        $actualEnd = (int) $match[2];
        $total = (int) $match[3];
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($actualStart !== $start || $actualEnd < $actualStart || $actualEnd >= $total || $total !== $object->size
            || ($end !== null && $actualEnd > $end)
            || ($length !== '' && (preg_match('/^\d+$/', $length) !== 1 || (int) $length !== $actualEnd - $actualStart + 1))) {
            $response->getBody()->close();
            throw new OneDriveUnavailable('ONEDRIVE_OBJECT_CHANGED', 'OneDrive 对象在读取期间发生变化。');
        }
        return new WebDavRangeResponse($actualStart, $actualEnd, $total, $response->getBody());
    }

    /** 为 FFprobe/FFmpeg 启动不含远端地址和凭据的一次性回环代理。 */
    public function openRangeProxy(WebDavObject $object, bool $continuousResponse = false): WebDavRangeProxySession
    {
        $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $object->etag === '') {
            throw new OneDriveUnavailable('ONEDRIVE_RANGE_INVALID', 'OneDrive 探测对象无效。');
        }
        return WebDavRangeProxySession::start([
            'sourceType' => 'onedrive',
            'tenantId' => $this->tenantId,
            'clientId' => $this->clientId,
            'refreshToken' => $this->refreshToken,
            'driveId' => $this->driveId,
            'remoteRootPath' => $this->remoteRootPath,
            'accessToken' => $this->token(),
            'accessTokenExpiresAt' => $this->tokenExpiresAt,
            'relativePath' => $object->relativePath,
            'size' => $object->size,
            'modifiedAt' => $object->modifiedAt,
            'etag' => $object->etag,
            'continuousResponse' => $continuousResponse,
            'proxy' => $this->proxy,
        ]);
    }

    /** 读取指定路径的单个 Graph item，并映射为无远端 ID 的内部对象。 */
    private function fetchItem(string $relativePath): WebDavObject
    {
        $relativePath = $this->assertRelativePath($relativePath, true);
        $response = $this->graph('GET', $this->itemEndpoint($relativePath), [
            'query' => ['$select' => 'name,size,eTag,lastModifiedDateTime,folder,file'],
        ]);
        return $this->mapItem($this->json($response, 'ONEDRIVE_RESPONSE_INVALID'), $relativePath);
    }

    /** @param array<string,mixed> $item */
    private function mapItem(array $item, string $relativePath): WebDavObject
    {
        $directory = is_array($item['folder'] ?? null);
        if (!$directory && !is_array($item['file'] ?? null)) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 返回了不支持的对象类型。');
        }
        $size = $directory ? 0 : ($item['size'] ?? null);
        $modified = is_string($item['lastModifiedDateTime'] ?? null)
            ? strtotime($item['lastModifiedDateTime']) : false;
        $etag = is_string($item['eTag'] ?? null) ? trim($item['eTag']) : '';
        if ((!$directory && (!is_int($size) || $size < 1)) || $modified === false || $modified < 0
            || $etag === '' || strlen($etag) > 512) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 对象事实无效。');
        }
        return new WebDavObject($relativePath, $directory, $directory ? 0 : $size, (int) $modified, $etag);
    }

    /** 请求固定 Graph 端点并把 HTTP 状态收敛为稳定错误。 */
    private function graph(string $method, string $uri, array $options = []): ResponseInterface
    {
        try {
            $response = $this->http->request($method, $uri, $this->plainOptions($options + [
                'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'],
            ]));
        } catch (OneDriveUnavailable $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_CONNECTION_FAILED', '无法连接 Microsoft Graph。');
        }
        return match ($response->getStatusCode()) {
            200 => $response,
            401, 403 => throw new OneDriveUnavailable('ONEDRIVE_AUTH_FAILED', 'OneDrive 应用认证或读取权限无效。'),
            404 => throw new OneDriveUnavailable('ONEDRIVE_ROOT_INVALID', 'OneDrive 用户、磁盘或根目录不存在。'),
            429 => throw new OneDriveUnavailable('ONEDRIVE_RATE_LIMITED', 'OneDrive 请求受到限流，请稍后重试。'),
            default => throw new OneDriveUnavailable('ONEDRIVE_PROTOCOL_REJECTED', 'Microsoft Graph 暂时无法完成请求。'),
        };
    }

    /**
     * 使用 delegated refresh token 获取短期 access token，并在微软轮换 grant 时先持久化新 refresh token。
     *
     * 轮换回调由工厂绑定到当前连接密文的条件更新；回调缺失仅用于尚未保存的授权预检和隔离代理子进程。
     * Microsoft 未返回新 refresh token 时保留原值。任何响应、回调或长度异常都失败关闭且不暴露 token。
     */
    private function token(): string
    {
        if (is_string($this->accessToken) && time() < $this->tokenExpiresAt) return $this->accessToken;
        try {
            $response = $this->http->request(
                'POST',
                'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token',
                $this->plainOptions([
                    'form_params' => [
                        'client_id' => $this->clientId,
                        'refresh_token' => $this->refreshToken,
                        'scope' => 'offline_access Files.ReadWrite User.Read',
                        'grant_type' => 'refresh_token',
                    ],
                ]),
            );
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_AUTH_FAILED', '无法完成 OneDrive 应用认证。');
        }
        if ($response->getStatusCode() !== 200) {
            throw new OneDriveUnavailable('ONEDRIVE_REAUTHORIZATION_REQUIRED', 'OneDrive 授权已失效，请重新授权。');
        }
        $payload = $this->json($response, 'ONEDRIVE_AUTH_FAILED');
        $token = $payload['access_token'] ?? null;
        $rotatedRefreshToken = $payload['refresh_token'] ?? null;
        $expires = $payload['expires_in'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 32_768 || !is_int($expires) || $expires < 60) {
            throw new OneDriveUnavailable('ONEDRIVE_AUTH_FAILED', 'OneDrive 认证响应无效。');
        }
        if ($rotatedRefreshToken !== null
            && (!is_string($rotatedRefreshToken) || $rotatedRefreshToken === '' || strlen($rotatedRefreshToken) > 65_536)) {
            throw new OneDriveUnavailable('ONEDRIVE_AUTH_FAILED', 'OneDrive 认证响应无效。');
        }
        if (is_string($rotatedRefreshToken) && !hash_equals($this->refreshToken, $rotatedRefreshToken)) {
            try {
                if ($this->refreshTokenRotated instanceof Closure) {
                    ($this->refreshTokenRotated)($rotatedRefreshToken);
                }
            } catch (Throwable) {
                sodium_memzero($rotatedRefreshToken);
                throw new OneDriveUnavailable('ONEDRIVE_CREDENTIAL_ROTATION_FAILED', 'OneDrive 授权轮换保存失败。');
            }
            sodium_memzero($this->refreshToken);
            $this->refreshToken = $rotatedRefreshToken;
        }
        if (is_string($this->accessToken) && $this->accessToken !== '') sodium_memzero($this->accessToken);
        $this->accessToken = $token;
        $this->tokenExpiresAt = time() + min($expires - 30, 3300);
        return $this->accessToken;
    }

    /**
     * 获取并验证 Graph 生成的短期预认证下载地址，不把 Bearer 带到下载主机。
     *
     * FFprobe 会对同一对象执行多个 seek。若每个区间都重新访问 Graph `/content`，少量字节读取也会被
     * 放大成大量 Graph 请求并更容易触发租户限流。因此客户端只缓存当前对象的一个地址五分钟；对象
     * 身份变化立即替换缓存，客户端销毁后地址随实例释放，不写入数据库、磁盘、日志或子进程参数。
     */
    private function downloadLocation(WebDavObject $object): string
    {
        $objectKey = hash('sha256', $object->relativePath . "\n" . $object->size . "\n" . $object->etag);
        if (is_array($this->downloadLocationCache)
            && hash_equals($this->downloadLocationCache['objectKey'], $objectKey)
            && $this->downloadLocationCache['expiresAt'] > time()) {
            return $this->downloadLocationCache['url'];
        }
        $response = $this->graphRedirect('GET', $this->contentEndpoint($object->relativePath), [
            'headers' => ['Authorization' => 'Bearer ' . $this->token(), 'If-Match' => $object->etag],
        ]);
        $location = trim($response->getHeaderLine('Location'));
        $parts = parse_url($location);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port'])
            || !$this->allowedDownloadHost($host)) {
            throw new OneDriveUnavailable('ONEDRIVE_DOWNLOAD_REDIRECT_REJECTED', 'OneDrive 下载地址未通过安全验证。');
        }
        $this->downloadLocationCache = [
            'objectKey' => $objectKey,
            'url' => $location,
            'expiresAt' => time() + self::DOWNLOAD_LOCATION_TTL_SECONDS,
        ];
        return $location;
    }

    /** Graph 内容端点只允许返回一次未跟随的 Microsoft 下载重定向。 */
    private function graphRedirect(string $method, string $uri, array $options): ResponseInterface
    {
        try {
            $response = $this->http->request($method, $uri, $this->plainOptions($options));
        } catch (Throwable) {
            throw new OneDriveUnavailable('ONEDRIVE_RANGE_FAILED', 'OneDrive 按需读取失败。');
        }
        return match ($response->getStatusCode()) {
            301, 302, 303, 307, 308 => $response,
            401, 403, 412 => throw new OneDriveUnavailable('ONEDRIVE_OBJECT_CHANGED', 'OneDrive 对象或读取授权已经变化。'),
            404 => throw new OneDriveUnavailable('ONEDRIVE_OBJECT_CHANGED', 'OneDrive 对象已经不存在。'),
            429 => throw new OneDriveUnavailable('ONEDRIVE_RATE_LIMITED', 'OneDrive 请求受到限流，请稍后重试。'),
            default => throw new OneDriveUnavailable('ONEDRIVE_RANGE_FAILED', 'OneDrive 按需读取失败。'),
        };
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response, string $errorCode): array
    {
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($length !== '' && preg_match('/^\d+$/', $length) === 1 && (int) $length > self::MAX_JSON_BYTES) {
            throw new OneDriveUnavailable($errorCode, 'Microsoft Graph 响应无效。');
        }
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_JSON_BYTES) {
            $body .= $stream->read(min(8192, self::MAX_JSON_BYTES + 1 - strlen($body)));
        }
        if ($body === '' || strlen($body) > self::MAX_JSON_BYTES) {
            throw new OneDriveUnavailable($errorCode, 'Microsoft Graph 响应无效。');
        }
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new OneDriveUnavailable($errorCode, 'Microsoft Graph 响应无效。');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new OneDriveUnavailable($errorCode, 'Microsoft Graph 响应无效。');
        }
        return $payload;
    }

    private function itemEndpoint(string $relativePath): string
    {
        $base = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($this->driveId) . '/root';
        $path = $this->combinedPath($relativePath);
        return $path === '' ? $base : $base . ':/' . $this->encodedSegments($path);
    }

    private function childrenEndpoint(string $relativePath): string
    {
        $path = $this->combinedPath($relativePath);
        $base = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($this->driveId) . '/root';
        return $path === '' ? $base . '/children' : $base . ':/' . $this->encodedSegments($path) . ':/children';
    }

    private function contentEndpoint(string $relativePath): string
    {
        $path = $this->combinedPath($relativePath);
        return 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($this->driveId)
            . '/root:/' . $this->encodedSegments($path) . ':/content';
    }

    private function combinedPath(string $relativePath): string
    {
        $root = trim($this->remoteRootPath, '/');
        return $relativePath === '' ? $root : ($root === '' ? $relativePath : $root . '/' . $relativePath);
    }

    private function encodedSegments(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function validatedNextLink(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 8192) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 分页地址无效。');
        }
        $parts = parse_url($value);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $drivePrefix = '/v1.0/drives/' . rawurlencode($this->driveId) . '/root';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'graph.microsoft.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !str_starts_with($path, $drivePrefix) || !str_ends_with($path, '/children')) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 分页地址无效。');
        }
        return $value;
    }

    private function allowedDownloadHost(string $host): bool
    {
        foreach (['.sharepoint.com', '.sharepoint-df.com', '.1drv.com', '.onedrive.com'] as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) return true;
        }
        return false;
    }

    private function validChildName(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255 || $value === '.' || $value === '..'
            || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")
            || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new OneDriveUnavailable('ONEDRIVE_RESPONSE_INVALID', 'OneDrive 对象名称无效。');
        }
        return $value;
    }

    private function assertRelativePath(string $path, bool $allowEmpty): string
    {
        $path = str_replace('\\', '/', $path);
        if (($path === '' && $allowEmpty) || ($path !== '' && $this->validRoot('/' . $path))) return $path;
        throw new OneDriveUnavailable('ONEDRIVE_PATH_INVALID', 'OneDrive 对象路径无效。');
    }

    private function validRoot(string $path): bool
    {
        if ($path === '' || strlen($path) > 4096 || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('//u', $path) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $path) === 1) return false;
        foreach (array_values(array_filter(explode('/', $path), static fn (string $v): bool => $v !== '')) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '\\')) return false;
        }
        return true;
    }

    private function validIdentifier(string $value, int $maximumBytes): bool
    {
        return $value !== '' && strlen($value) <= $maximumBytes && !str_contains($value, "\0")
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** @param array<string,mixed> $overrides */
    private function plainOptions(array $overrides): array
    {
        return NetworkProxyRequestOptions::apply($overrides + [
            'verify' => true,
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [],
        ], $this->proxy);
    }
}
