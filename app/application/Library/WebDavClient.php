<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyRequestOptions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 执行受约束的 WebDAV PROPFIND 与 GET。
 *
 * 客户端只接受管理服务已经规范化的 HTTP(S) 基址、根路径和 Basic Auth 凭据；关闭自动重定向，避免
 * Authorization 被转发到另一个主机。目录枚举固定 `Depth: 1` 并限制 XML 体积，递归由扫描 Worker
 * 控制，因此服务端不能用一个无限响应耗尽内存。所有 href 都重新解析并证明位于配置根下，越界、点段、
 * 控制字符和重复路径失败关闭。
 *
 * 本类不记录请求 URL、用户名、响应正文或异常消息。调用方必须把网络 I/O 放在 SQLite 事务之外；
 * 音频正文只允许通过受验证的 Range 接口读取，不提供完整下载到本地临时文件的能力。
 */
final class WebDavClient implements RemoteLibraryClient, WritableRemoteLibraryClient
{
    private const MAX_XML_BYTES = 8_388_608;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $remoteRootPath,
        private readonly string $username,
        private string $password,
        private readonly bool $verifyTls,
        private readonly ClientInterface $http = new Client(),
        private readonly ?array $proxy = null,
    ) {
    }

    /** 尽力从进程内存清除凭据；PHP/Guzzle 内部复制不构成可证明的完整擦除保证。 */
    public function __destruct()
    {
        if ($this->password !== '') sodium_memzero($this->password);
    }

    /** 返回持久化来源键，供共享扫描器生成不泄露地址的内部定位符。 */
    public function sourceType(): string
    {
        return 'webdav';
    }

    /**
     * 验证配置根存在且是 collection。
     *
     * 成功只证明当前时刻可认证、TLS 策略可用且根可列举，不代表未来扫描或播放不会发生网络故障。
     */
    public function assertConnection(): void
    {
        // 连接保存前直接验证扫描真正依赖的 Depth: 1，而不是只验证较弱的 Depth: 0。响应可以包含
        // 任意数量的直接子项，但 parseMultistatus 已保证根对象存在且所有 href 都重新收敛到配置根内。
        $objects = $this->propfind('', 1);
        if ($objects === [] || !$objects[0]->directory || $objects[0]->relativePath !== '') {
            throw new WebDavUnavailable('WEBDAV_ROOT_INVALID', 'WebDAV 根目录不存在或不是目录。');
        }
    }

    /**
     * 列出一个根内目录及其直接子对象；返回值包含目录自身。
     *
     * @return list<WebDavObject>
     */
    public function listDirectory(string $relativeDirectory): array
    {
        $this->assertRelativePath($relativeDirectory, true);
        return $this->propfind($relativeDirectory, 1);
    }

    /**
     * 通过 MKCOL、条件 PUT 和 `Overwrite: F` MOVE 非覆盖发布完整音频。
     *
     * 文件先写入同目录下由目标路径和摘要确定的隐藏临时名，再原子 MOVE 到最终名；断线重试会完整读取
     * 已存在对象计算 SHA-256，只有摘要与大小都一致才恢复成功。服务端不支持条件写、MOVE 或返回无法
     * 判定的状态时失败关闭，绝不退化为覆盖 PUT。临时对象只包含当前摘要，失败时不删除无法证明归属的
     * 对象；后续同一任务会复用并校验它，管理员可按 `.velin-upload-*.tmp` 清理长期孤儿。
     */
    public function publishFile(string $relativePath, string $localPath, int $size, string $sha256): RemotePublishedObject
    {
        $this->assertRelativePath($relativePath, false);
        if (!is_file($localPath) || is_link($localPath) || !is_readable($localPath) || filesize($localPath) !== $size
            || $size < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new WebDavUnavailable('WEBDAV_UPLOAD_SOURCE_INVALID', 'WebDAV 上传源文件无效。');
        }
        $segments = explode('/', $relativePath);
        $name = array_pop($segments);
        $directory = '';
        foreach ($segments as $segment) {
            $directory = $directory === '' ? $segment : $directory . '/' . $segment;
            $this->ensureRemoteDirectory($directory);
        }
        $existing = $this->remoteFileVersion($relativePath, $size, $sha256);
        if ($existing !== null) return new RemotePublishedObject(
            $relativePath, $size, $sha256, $existing['version'], $existing['etag'],
        );

        $temporaryName = '.velin-upload-' . substr(hash('sha256', $relativePath . "\0" . $sha256), 0, 32) . '.tmp';
        $temporary = $directory === '' ? $temporaryName : $directory . '/' . $temporaryName;
        if ($this->remoteFileVersion($temporary, $size, $sha256) === null) {
            $input = @fopen($localPath, 'rb');
            if ($input === false) throw new WebDavUnavailable('WEBDAV_UPLOAD_SOURCE_INVALID', 'WebDAV 上传源文件无效。');
            try {
                $response = $this->http->request('PUT', $this->objectUrl($temporary), $this->options([
                    'headers' => ['If-None-Match' => '*', 'Content-Length' => (string) $size,
                        'Content-Type' => 'application/octet-stream'],
                    'body' => $input, 'timeout' => 900,
                ]));
            } catch (Throwable) {
                throw new WebDavUnavailable('WEBDAV_UPLOAD_FAILED', 'WebDAV 上传失败。');
            } finally {
                fclose($input);
            }
            if ($response->getStatusCode() === 412) {
                if ($this->remoteFileVersion($temporary, $size, $sha256) === null) throw new RemotePublicationConflict();
            } elseif (!in_array($response->getStatusCode(), [200, 201, 204], true)) {
                throw $this->writeFailure($response->getStatusCode(), 'WEBDAV_UPLOAD_FAILED');
            }
        }

        try {
            $response = $this->http->request('MOVE', $this->objectUrl($temporary), $this->options([
                'headers' => ['Destination' => $this->objectUrl($relativePath), 'Overwrite' => 'F',
                    'If-None-Match' => '*'],
                'timeout' => 60,
            ]));
        } catch (Throwable) {
            throw new WebDavUnavailable('WEBDAV_UPLOAD_PUBLISH_FAILED', 'WebDAV 无法公布上传文件。');
        }
        if (!in_array($response->getStatusCode(), [201, 204], true)) {
            if (in_array($response->getStatusCode(), [409, 412], true)) {
                $existing = $this->remoteFileVersion($relativePath, $size, $sha256);
                if ($existing !== null) return new RemotePublishedObject(
                    $relativePath, $size, $sha256, $existing['version'], $existing['etag'],
                );
                throw new RemotePublicationConflict();
            }
            throw $this->writeFailure($response->getStatusCode(), 'WEBDAV_UPLOAD_PUBLISH_FAILED');
        }
        $version = $this->remoteFileVersion($relativePath, $size, $sha256);
        if ($version === null) throw new WebDavUnavailable('WEBDAV_UPLOAD_VERIFY_FAILED', 'WebDAV 上传校验失败。');
        return new RemotePublishedObject($relativePath, $size, $sha256, $version['version'], $version['etag']);
    }

    /** 幂等创建一个根内 collection；405 只在 PROPFIND 证明其确为目录时视为成功。 */
    private function ensureRemoteDirectory(string $relativePath): void
    {
        try {
            $response = $this->http->request('MKCOL', $this->directoryUrl($relativePath), $this->options([]));
        } catch (Throwable) {
            throw new WebDavUnavailable('WEBDAV_DIRECTORY_CREATE_FAILED', 'WebDAV 无法创建目标目录。');
        }
        if ($response->getStatusCode() === 201) return;
        if ($response->getStatusCode() === 405) {
            $objects = $this->propfind($relativePath, 0);
            if (count($objects) === 1 && $objects[0]->relativePath === $relativePath && $objects[0]->directory) return;
        }
        throw $this->writeFailure($response->getStatusCode(), 'WEBDAV_DIRECTORY_CREATE_FAILED');
    }

    /**
     * 返回与期望内容一致的脱敏版本；404 返回 null，其他已存在内容冲突。
     *
     * WebDAV 没有统一内容摘要字段，因此崩溃恢复和上传后验证需要流式读取完整对象。读取有精确字节上限，
     * 不把正文拼入内存；大小或 SHA-256 不一致即视为外部对象，禁止覆盖。
     */
    /** @return array{version:string,etag:string}|null */
    private function remoteFileVersion(string $relativePath, int $expectedSize, string $expectedSha256): ?array
    {
        try {
            $response = $this->http->request('GET', $this->objectUrl($relativePath), $this->options([
                'headers' => ['Accept' => 'application/octet-stream'], 'stream' => true, 'timeout' => 900,
            ]));
        } catch (Throwable) {
            throw new WebDavUnavailable('WEBDAV_UPLOAD_VERIFY_FAILED', 'WebDAV 上传校验失败。');
        }
        if ($response->getStatusCode() === 404) return null;
        if ($response->getStatusCode() !== 200) throw $this->writeFailure($response->getStatusCode(), 'WEBDAV_UPLOAD_VERIFY_FAILED');
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($length !== '' && (preg_match('/^\d+$/D', $length) !== 1 || (int) $length !== $expectedSize)) {
            $response->getBody()->close();
            throw new RemotePublicationConflict();
        }
        $hash = hash_init('sha256');
        $read = 0;
        $stream = $response->getBody();
        try {
            while (!$stream->eof() && $read <= $expectedSize) {
                $chunk = $stream->read(min(1_048_576, $expectedSize + 1 - $read));
                if ($chunk === '' && !$stream->eof()) {
                    throw new WebDavUnavailable('WEBDAV_UPLOAD_VERIFY_FAILED', 'WebDAV 上传校验失败。');
                }
                $read += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            $stream->close();
        }
        $actual = hash_final($hash);
        if ($read !== $expectedSize || !hash_equals($expectedSha256, $actual)) throw new RemotePublicationConflict();
        $etag = trim($response->getHeaderLine('ETag'));
        return [
            'version' => hash('sha256', $relativePath . "\0" . $expectedSize . "\0" . $actual . "\0" . $etag),
            'etag' => $etag,
        ];
    }

    /** 把写请求状态收敛为不含 URL、用户名和响应正文的稳定异常。 */
    private function writeFailure(int $status, string $fallback): WebDavUnavailable
    {
        return match ($status) {
            401, 403 => new WebDavUnavailable('WEBDAV_WRITE_AUTH_FAILED', 'WebDAV 账号没有写入权限。'),
            429 => new WebDavUnavailable('WEBDAV_RATE_LIMITED', 'WebDAV 请求受到限流，请稍后重试。'),
            405, 501 => new WebDavUnavailable('WEBDAV_WRITE_UNSUPPORTED', 'WebDAV 服务不支持安全上传。'),
            default => new WebDavUnavailable($fallback, 'WebDAV 暂时无法完成安全上传。'),
        };
    }

    /**
     * 打开一个严格的单字节区间流，供回环 FFprobe 代理按需读取。
     *
     * 请求始终携带 Range，并在真实 ETag 可用时携带 If-Match。只有状态 206、Content-Range 起点与请求
     * 一致、终点未越过请求上限、总大小等于扫描快照且响应 ETag 未漂移时才返回正文；服务端忽略 Range
     * 返回 200 会明确归类为不支持，调用方不得把它当作完整文件继续读取。
     */
    public function openRange(WebDavObject $object, int $start, ?int $end = null): WebDavRangeResponse
    {
        $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $start < 0 || $start >= $object->size
            || ($end !== null && ($end < $start || $end >= $object->size))) {
            throw new WebDavUnavailable('WEBDAV_RANGE_INVALID', 'WebDAV 字节区间无效。');
        }
        $headers = ['Range' => 'bytes=' . $start . '-' . ($end ?? '')];
        if (!str_starts_with($object->etag, 'synthetic:')) $headers['If-Match'] = $object->etag;
        try {
            $response = $this->http->request('GET', $this->objectUrl($object->relativePath), $this->options([
                'headers' => $headers,
                'stream' => true,
                'timeout' => 60,
            ]));
        } catch (Throwable) {
            throw new WebDavUnavailable('WEBDAV_RANGE_FAILED', 'WebDAV 按需读取失败。');
        }
        if ($response->getStatusCode() === 200) {
            $response->getBody()->close();
            throw new WebDavUnavailable('WEBDAV_RANGE_UNSUPPORTED', 'WebDAV 服务不支持按需读取。');
        }
        try {
            [$actualStart, $actualEnd, $total] = $this->validatedContentRange($response, $object, $start, $end);
        } catch (WebDavUnavailable $failure) {
            $response->getBody()->close();
            throw $failure;
        }
        return new WebDavRangeResponse($actualStart, $actualEnd, $total, $response->getBody());
    }

    /**
     * 为指定不可变对象启动一次本机 Range 代理，供 FFprobe 或 FFmpeg 按需 seek。
     *
     * 返回 URL 不含 WebDAV 地址、路径或凭据；秘密通过子进程标准输入传递。调用方必须持有并最终关闭
     * 会话，使正常完成、失败、超时和客户端断开都能终止活动上游请求。该地址只允许进入受控媒体子进程，
     * 不得作为通用 SSRF 转发器或返回给浏览器、Subsonic 客户端和 DLNA Renderer。扫描保持单段响应，
     * 促使 FFprobe 按需 seek；转码必须启用连续响应，否则 FFmpeg 顺序解码会在首个 1 MiB 正常响应处结束。
     */
    public function openRangeProxy(WebDavObject $object, bool $continuousResponse = false): WebDavRangeProxySession
    {
        $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $object->etag === '') {
            throw new WebDavUnavailable('WEBDAV_RANGE_INVALID', 'WebDAV 探测对象无效。');
        }
        return WebDavRangeProxySession::start([
            'sourceType' => 'webdav',
            'baseUrl' => $this->baseUrl,
            'remoteRootPath' => $this->remoteRootPath,
            'username' => $this->username,
            'password' => $this->password,
            'verifyTls' => $this->verifyTls,
            'relativePath' => $object->relativePath,
            'size' => $object->size,
            'modifiedAt' => $object->modifiedAt,
            'etag' => $object->etag,
            'continuousResponse' => $continuousResponse,
            'proxy' => $this->proxy,
        ]);
    }

    /** @return array{int,int,int} */
    private function validatedContentRange(
        ResponseInterface $response,
        WebDavObject $object,
        int $requestedStart,
        ?int $requestedEnd,
    ): array {
        if ($response->getStatusCode() !== 206
            || preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', trim($response->getHeaderLine('Content-Range')), $match) !== 1) {
            throw new WebDavUnavailable('WEBDAV_RANGE_UNSUPPORTED', 'WebDAV 服务不支持按需读取。');
        }
        $start = (int) $match[1];
        $end = (int) $match[2];
        $total = (int) $match[3];
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($start !== $requestedStart || $end < $start || $end >= $total || $total !== $object->size
            || ($requestedEnd !== null && $end > $requestedEnd)
            || ($length !== '' && (preg_match('/^\d+$/', $length) !== 1 || (int) $length !== $end - $start + 1))) {
            throw new WebDavUnavailable('WEBDAV_OBJECT_CHANGED', 'WebDAV 对象在读取期间发生变化。');
        }
        $responseEtag = trim($response->getHeaderLine('ETag'));
        if ($responseEtag !== '' && !str_starts_with($object->etag, 'synthetic:')
            && !hash_equals($object->etag, $responseEtag)) {
            throw new WebDavUnavailable('WEBDAV_OBJECT_CHANGED', 'WebDAV 对象在读取期间发生变化。');
        }
        return [$start, $end, $total];
    }

    /** @return list<WebDavObject> */
    private function propfind(string $relativePath, int $depth): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/>'
            . '<d:getlastmodified/><d:getetag/></d:prop></d:propfind>';
        try {
            $response = $this->http->request('PROPFIND', $this->directoryUrl($relativePath), $this->options([
                'headers' => ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'],
                'body' => $body,
            ]));
            if (in_array($response->getStatusCode(), [401, 403], true)) {
                throw new WebDavUnavailable('WEBDAV_AUTH_FAILED', 'WebDAV 认证失败。');
            }
            if ($response->getStatusCode() === 404) {
                throw new WebDavUnavailable('WEBDAV_ROOT_INVALID', 'WebDAV 根目录不存在或不是目录。');
            }
            if ($response->getStatusCode() === 429) {
                // 429 可能来自 WebDAV 自身、反向代理或登录防御器。这里不能立即重试，否则会延长来源
                // 地址的封禁时间并放大目录扫描请求；也不回显响应正文或 Retry-After 之外的服务端细节。
                throw new WebDavUnavailable(
                    'WEBDAV_RATE_LIMITED',
                    'WebDAV 服务暂时拒绝请求（请求过多或来源地址受限），请解除服务端限制后重试。',
                );
            }
            if (in_array($response->getStatusCode(), [301, 302, 307, 308], true)) {
                // 凭据请求禁止自动跟随 Location，避免反向代理把 Authorization 带到另一个主机。管理员
                // 应填写服务端最终地址；错误不回显 Location、请求 URL 或用户名。
                throw new WebDavUnavailable(
                    'WEBDAV_REDIRECT_REJECTED',
                    'WebDAV 地址发生重定向，请填写重定向后的最终服务地址。',
                );
            }
            if ($response->getStatusCode() === 200) {
                throw new WebDavUnavailable(
                    'WEBDAV_ENDPOINT_INVALID',
                    '服务地址返回了普通页面，请检查 WebDAV 服务地址和远端根路径。',
                );
            }
            if (in_array($response->getStatusCode(), [405, 501], true)) {
                throw new WebDavUnavailable(
                    'WEBDAV_PROPFIND_UNSUPPORTED',
                    'WebDAV 服务未启用目录读取（PROPFIND）。',
                );
            }
            if ($response->getStatusCode() !== 207) {
                throw new WebDavUnavailable('WEBDAV_PROTOCOL_REJECTED', 'WebDAV 服务不支持所需的目录读取。');
            }
            $length = trim($response->getHeaderLine('Content-Length'));
            if ($length !== '' && preg_match('/^\d+$/', $length) === 1 && (int) $length > self::MAX_XML_BYTES) {
                throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
            }
            $xml = $this->readBoundedXml($response->getBody());
        } catch (WebDavUnavailable $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new WebDavUnavailable('WEBDAV_CONNECTION_FAILED', '无法连接 WebDAV 服务。');
        }
        if ($xml === '' || strlen($xml) > self::MAX_XML_BYTES) {
            throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
        }
        return $this->parseMultistatus($xml, $relativePath);
    }

    /** @return list<WebDavObject> */
    private function parseMultistatus(string $xml, string $requestedRelativePath): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('/*[local-name()="multistatus"]/*[local-name()="response"]');
        if ($nodes === false) throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
        $objects = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) continue;
            $href = trim((string) $xpath->evaluate('string(./*[local-name()="href"][1])', $node));
            $status = (string) $xpath->evaluate(
                'string(./*[local-name()="propstat"]/*[local-name()="status"][contains(., " 200 ")][1])',
                $node,
            );
            if ($href === '' || $status === '') continue;
            $relative = $this->relativeFromHref($href);
            if ($relative === null) continue;
            $isDirectory = (bool) $xpath->evaluate(
                'boolean(./*[local-name()="propstat"]/*[local-name()="prop"]/*[local-name()="resourcetype"]/*[local-name()="collection"])',
                $node,
            );
            $sizeText = trim((string) $xpath->evaluate(
                'string(./*[local-name()="propstat"]/*[local-name()="prop"]/*[local-name()="getcontentlength"][1])',
                $node,
            ));
            $modifiedText = trim((string) $xpath->evaluate(
                'string(./*[local-name()="propstat"]/*[local-name()="prop"]/*[local-name()="getlastmodified"][1])',
                $node,
            ));
            $etag = trim((string) $xpath->evaluate(
                'string(./*[local-name()="propstat"]/*[local-name()="prop"]/*[local-name()="getetag"][1])',
                $node,
            ));
            $size = $isDirectory ? 0 : (preg_match('/^\d+$/', $sizeText) === 1 ? (int) $sizeText : -1);
            $modified = $modifiedText === '' ? false : strtotime($modifiedText);
            if (!$isDirectory && ($size < 1 || $modified === false)) continue;
            if ($etag === '' || strlen($etag) > 512) {
                $etag = 'synthetic:' . hash('sha256', $relative . "\0" . $size . "\0" . (int) $modified);
            }
            if (array_key_exists($relative, $objects)) {
                throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应包含重复对象。');
            }
            $objects[$relative] = new WebDavObject(
                $relative,
                $isDirectory,
                max(0, $size),
                $modified === false ? 0 : max(0, (int) $modified),
                $etag,
            );
        }
        if (!isset($objects[$requestedRelativePath])) {
            throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应缺少请求对象。');
        }
        ksort($objects, SORT_STRING);
        return array_values($objects);
    }

    /** 把服务端 href 收敛成配置根内的无点段 UTF-8 相对路径。 */
    private function relativeFromHref(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path)) return null;
        $decodedPath = rawurldecode($path);
        $rootPath = rawurldecode((string) parse_url($this->objectUrl(''), PHP_URL_PATH));
        $root = rtrim($rootPath, '/');
        $candidate = rtrim($decodedPath, '/');
        if ($candidate === $root) return '';
        if (!str_starts_with($candidate, $root . '/')) return null;
        $relative = substr($candidate, strlen($root) + 1);
        if (!is_string($relative) || $relative === '' || str_contains($relative, "\0")
            || preg_match('//u', $relative) !== 1) return null;
        $segments = explode('/', str_replace('\\', '/', $relative));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/[\x00-\x1F\x7F]/u', $segment) === 1) return null;
        }
        return implode('/', $segments);
    }

    /** 只用配置事实生成 URL；相对路径逐段编码，不能改变 scheme、host 或根路径。 */
    private function objectUrl(string $relativePath): string
    {
        $url = rtrim($this->baseUrl, '/') . $this->encodedPath($this->remoteRootPath);
        if ($relativePath === '') return rtrim($url, '/') . '/';
        return rtrim($url, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
    }

    /**
     * 返回 collection 的规范请求 URL。
     *
     * WebDAV 的 href 可以带或不带末尾斜杠，但不少服务端只在带斜杠的 collection URL 上处理
     * PROPFIND，并对无斜杠 URL 返回重定向。这里只补同一已验证路径的 `/`，不读取 Location、改变主机
     * 或放宽关闭重定向的凭据边界；普通对象 GET 继续使用 objectUrl，文件 URL 不会被错误改成目录。
     */
    private function directoryUrl(string $relativePath): string
    {
        return rtrim($this->objectUrl($relativePath), '/') . '/';
    }

    private function encodedPath(string $path): string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $v): bool => $v !== ''));
        return $segments === [] ? '/' : '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * 分块读取最多 8 MiB XML；即使服务端省略或伪造 Content-Length，也不会把无限正文拼入 PHP 字符串。
     * PSR-7 Handler 可能已在底层暂存响应，但本边界仍确保领域解析和日志链路不持有超限副本。
     */
    private function readBoundedXml(\Psr\Http\Message\StreamInterface $stream): string
    {
        $xml = '';
        while (!$stream->eof()) {
            $remaining = self::MAX_XML_BYTES + 1 - strlen($xml);
            if ($remaining <= 0) break;
            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '' && !$stream->eof()) {
                throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
            }
            $xml .= $chunk;
        }
        if (strlen($xml) > self::MAX_XML_BYTES || !$stream->eof()) {
            throw new WebDavUnavailable('WEBDAV_RESPONSE_INVALID', 'WebDAV 目录响应无效。');
        }
        return $xml;
    }

    /** 调用边界再次收敛根内相对路径，避免损坏库存把点段带入 URL 生成。 */
    private function assertRelativePath(string $path, bool $allowEmpty): void
    {
        if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('//u', $path) !== 1) {
            throw new WebDavUnavailable('WEBDAV_PATH_INVALID', 'WebDAV 对象路径无效。');
        }
        if (($path === '' && $allowEmpty) || ($path !== '' && !str_starts_with($path, '/'))) {
            if ($path === '') return;
            $segments = explode('/', str_replace('\\', '/', $path));
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..'
                    || preg_match('/[\x00-\x1F\x7F]/u', $segment) === 1) {
                    throw new WebDavUnavailable('WEBDAV_PATH_INVALID', 'WebDAV 对象路径无效。');
                }
            }
            return;
        }
        throw new WebDavUnavailable('WEBDAV_PATH_INVALID', 'WebDAV 对象路径无效。');
    }

    /** @param array<string,mixed> $overrides */
    private function options(array $overrides): array
    {
        return NetworkProxyRequestOptions::apply($overrides + [
            'auth' => [$this->username, $this->password],
            'verify' => $this->verifyTls,
            'allow_redirects' => false,
            // WebDAV 凭据不得被宿主机的全局 HTTP(S)_PROXY 隐式转发给未配置的第三方代理。
            'connect_timeout' => 5,
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [],
        ], $this->proxy);
    }
}
