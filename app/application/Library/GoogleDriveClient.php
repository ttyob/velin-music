<?php

declare(strict_types=1);

namespace app\application\Library;

use app\application\System\NetworkProxyRequestOptions;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 通过 delegated `drive` grant 读写一个固定 Google Drive 根。
 *
 * 所有 Bearer 只发送到固定 `www.googleapis.com`，token 刷新只发送到 `oauth2.googleapis.com`；请求关闭
 * 重定向并验证 TLS。目录项被规范化为根内相对路径，Workspace 原生文档被忽略。同一父目录出现重名或
 * 名称含路径分隔符时失败关闭，因为当前库存以相对路径唯一标识对象，不能安全猜测其中一个 file ID。
 *
 * 内部 ETag 由 Google file ID、大小、修改时间和可用 MD5 计算，不向上层暴露 provider DTO。每次 Range
 * 读取都重新按路径解析 file ID 并比较该身份摘要，严格要求 206 与 Content-Range；403 配额原因和 429
 * 映射为脱敏错误，绝不回退完整下载。调用方必须在 SQLite 事务外使用本类。
 */
final class GoogleDriveClient implements RemoteLibraryClient, WritableRemoteLibraryClient
{
    private const API = 'https://www.googleapis.com/drive/v3';
    private const SCOPE = 'https://www.googleapis.com/auth/drive';
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const MAX_JSON_BYTES = 8_388_608;
    private const MAX_PAGES = 1000;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private readonly string $driveId,
        private readonly string $remoteRootPath,
        private readonly ClientInterface $http = new Client(),
        private readonly ?Closure $refreshTokenRotated = null,
        ?string $initialAccessToken = null,
        int $initialAccessTokenExpiresAt = 0,
        private readonly ?array $proxy = null,
    ) {
        if (!$this->validClientId($clientId) || !$this->validSecret($clientSecret)
            || !$this->validToken($refreshToken) || !$this->validIdentifier($driveId, 256)
            || !$this->validRoot($remoteRootPath)
            || ($initialAccessToken !== null && !$this->validToken($initialAccessToken))) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_CONFIGURATION_INVALID', 'Google Drive 连接配置无效。');
        }
        if (is_string($initialAccessToken) && $initialAccessTokenExpiresAt > time() + 30) {
            $this->accessToken = $initialAccessToken;
            $this->tokenExpiresAt = $initialAccessTokenExpiresAt;
        }
    }

    /** 尽力清除 OAuth 明文；PHP/Guzzle 内部复制不构成完整内存擦除保证。 */
    public function __destruct()
    {
        if ($this->clientSecret !== '') sodium_memzero($this->clientSecret);
        if ($this->refreshToken !== '') sodium_memzero($this->refreshToken);
        if (is_string($this->accessToken) && $this->accessToken !== '') sodium_memzero($this->accessToken);
    }

    public function sourceType(): string { return 'google_drive'; }

    /** 验证 OAuth、盘身份和配置根当前均可只读访问。 */
    public function assertConnection(): void
    {
        $root = $this->fetchItem('');
        if (!$root->directory || $root->relativePath !== '') {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_ROOT_INVALID', 'Google Drive 根目录不存在或不是目录。');
        }
    }

    /** @return list<WebDavObject> 返回目录自身和直接子项，分页与重名均失败关闭。 */
    public function listDirectory(string $relativeDirectory): array
    {
        $relativeDirectory = $this->assertRelativePath($relativeDirectory, true);
        $folderId = $this->resolveFolderId($relativeDirectory);
        $self = $this->mapItem($this->file($folderId), $relativeDirectory);
        if (!$self instanceof WebDavObject || !$self->directory) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_ROOT_INVALID', 'Google Drive 目录不存在。');
        }
        $objects = [$relativeDirectory => $self];
        $pageToken = null;
        for ($page = 0; ; ++$page) {
            if ($page >= self::MAX_PAGES) {
                throw new GoogleDriveUnavailable('GOOGLE_DRIVE_PAGE_LIMIT_EXCEEDED', 'Google Drive 目录分页超过安全限制。');
            }
            $query = [
                'q' => "'" . $this->queryLiteral($folderId) . "' in parents and trashed = false",
                'pageSize' => 1000,
                'fields' => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId)',
                'spaces' => 'drive',
            ] + $this->driveScopeQuery(true);
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $payload = $this->json($this->api('GET', self::API . '/files', ['query' => $query]));
            $items = $payload['files'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 目录响应无效。');
            }
            foreach ($items as $item) {
                if (!is_array($item)) throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 目录响应无效。');
                $name = $this->childName($item['name'] ?? null);
                $relative = $relativeDirectory === '' ? $name : $relativeDirectory . '/' . $name;
                if (isset($objects[$relative])) {
                    throw new GoogleDriveUnavailable('GOOGLE_DRIVE_DUPLICATE_NAME', 'Google Drive 目录包含无法安全表示的同名对象。');
                }
                $mapped = $this->mapItem($item, $relative);
                if ($mapped instanceof WebDavObject) $objects[$relative] = $mapped;
            }
            $next = $payload['nextPageToken'] ?? null;
            if ($next === null) break;
            if (!is_string($next) || !$this->validIdentifier($next, 2048)) {
                throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 分页响应无效。');
            }
            $pageToken = $next;
        }
        ksort($objects, SORT_STRING);
        return array_values($objects);
    }

    /**
     * 使用 resumable upload 写入任务摘要临时名，再改名为最终歌曲名。
     *
     * Google Drive 允许同目录重名，无法依赖名称形成数据库式唯一约束。因此发布前后都查询同名对象；
     * 若最终改名后发现并发重名，只删除本次 API 返回 ID 对应的对象并失败关闭，绝不删除原对象。崩溃后
     * 最终名只有一个且 MD5、大小与本地文件一致时可恢复。目录创建同样复验唯一性，新建后若出现并发
     * 重名只删除本次创建且仍为空的目录。所有删除都是当前调用明确拥有对象的补偿。
     */
    public function publishFile(string $relativePath, string $localPath, int $size, string $sha256): RemotePublishedObject
    {
        $relativePath = $this->assertRelativePath($relativePath, false);
        if (!is_file($localPath) || is_link($localPath) || !is_readable($localPath) || filesize($localPath) !== $size
            || $size < 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_UPLOAD_SOURCE_INVALID', 'Google Drive 上传源文件无效。');
        }
        $localMd5 = hash_file('md5', $localPath);
        if (!is_string($localMd5)) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_UPLOAD_SOURCE_INVALID', 'Google Drive 上传源文件无效。');
        }
        $segments = explode('/', $relativePath);
        $name = (string) array_pop($segments);
        $parentId = $this->resolveConfiguredRootId();
        foreach ($segments as $segment) $parentId = $this->ensureDriveFolder($parentId, $segment);

        $existing = $this->matchingPublishedItem($parentId, $name, $size, $localMd5);
        if (is_array($existing)) {
            return new RemotePublishedObject($relativePath, $size, $sha256,
                $this->publicationVersion($relativePath, $existing, $sha256),
                $this->discoveryEtag($existing));
        }

        $temporaryName = '.velin-upload-' . substr(hash('sha256', $relativePath . "\0" . $sha256), 0, 32) . '.tmp';
        $temporaryMatches = $this->findNamedChildren($parentId, $temporaryName, false);
        if (count($temporaryMatches) > 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_DUPLICATE_NAME', 'Google Drive 上传暂存对象重名。');
        }
        if ($temporaryMatches === []) {
            $temporary = $this->resumableUpload($parentId, $temporaryName, $localPath, $size);
        } else {
            $temporary = $temporaryMatches[0];
            $this->assertDriveContent($temporary, $size, $localMd5);
        }

        // 改名前再次检查，缩短与外部写入者竞争的时间；若已有不同对象，保留临时对象供同任务恢复。
        $beforeRename = $this->findNamedChildren($parentId, $name, false);
        if ($beforeRename !== []) {
            $existing = $this->matchingPublishedItem($parentId, $name, $size, $localMd5);
            if (is_array($existing)) {
                $this->deleteOwnedItem((string) $temporary['id']);
                return new RemotePublishedObject($relativePath, $size, $sha256,
                    $this->publicationVersion($relativePath, $existing, $sha256),
                    $this->discoveryEtag($existing));
            }
            throw new RemotePublicationConflict();
        }

        $renamed = $this->json($this->api('PATCH', self::API . '/files/' . rawurlencode((string) $temporary['id']), [
            'query' => ['fields' => 'id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId']
                + $this->driveScopeQuery(false),
            'json' => ['name' => $name],
        ]));
        $matches = $this->findNamedChildren($parentId, $name, false);
        if (count($matches) !== 1 || (string) ($matches[0]['id'] ?? '') !== (string) ($renamed['id'] ?? '')) {
            $this->deleteOwnedItem((string) ($renamed['id'] ?? $temporary['id']));
            throw new RemotePublicationConflict();
        }
        $this->assertDriveContent($renamed, $size, $localMd5);
        return new RemotePublishedObject($relativePath, $size, $sha256,
            $this->publicationVersion($relativePath, $renamed, $sha256),
            $this->discoveryEtag($renamed));
    }

    /** 幂等取得唯一目录 ID；并发创建导致重名时只补偿本次创建对象。 */
    private function ensureDriveFolder(string $parentId, string $name): string
    {
        $matches = $this->findNamedChildren($parentId, $name, true);
        if (count($matches) === 1) return (string) $matches[0]['id'];
        if (count($matches) > 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_DUPLICATE_NAME', 'Google Drive 目标目录包含同名对象。');
        }
        $created = $this->json($this->api('POST', self::API . '/files', [
            'query' => ['fields' => 'id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId']
                + $this->driveScopeQuery(false),
            'json' => ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]],
        ], [200, 201]));
        $matches = $this->findNamedChildren($parentId, $name, true);
        if (count($matches) !== 1 || (string) ($matches[0]['id'] ?? '') !== (string) ($created['id'] ?? '')) {
            $this->deleteOwnedItem((string) ($created['id'] ?? ''));
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_DUPLICATE_NAME', 'Google Drive 目标目录并发重名。');
        }
        return (string) $created['id'];
    }

    /** 建立并完成一个 resumable upload；Location 仅允许 Google API HTTPS 主机。 */
    private function resumableUpload(string $parentId, string $name, string $localPath, int $size): array
    {
        $response = $this->api('POST', 'https://www.googleapis.com/upload/drive/v3/files', [
            'query' => ['uploadType' => 'resumable', 'fields' => 'id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId']
                + $this->driveScopeQuery(false),
            'headers' => ['X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $size, 'Content-Type' => 'application/json; charset=utf-8'],
            'json' => ['name' => $name, 'parents' => [$parentId]],
        ]);
        $location = trim($response->getHeaderLine('Location'));
        $parts = parse_url($location);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'www.googleapis.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strlen($location) > 16_384) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_UPLOAD_SESSION_INVALID', 'Google Drive 上传会话无效。');
        }
        $input = @fopen($localPath, 'rb');
        if ($input === false) throw new GoogleDriveUnavailable('GOOGLE_DRIVE_UPLOAD_SOURCE_INVALID', 'Google Drive 上传源文件无效。');
        try {
            $uploaded = $this->api('PUT', $location, [
                'headers' => ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $size],
                'body' => $input, 'timeout' => 900,
            ], [200, 201]);
        } finally {
            fclose($input);
        }
        return $this->json($uploaded);
    }

    /** 返回唯一且内容匹配的最终对象；不存在返回 null，重名或不同内容均失败关闭。 */
    private function matchingPublishedItem(string $parentId, string $name, int $size, string $md5): ?array
    {
        $matches = $this->findNamedChildren($parentId, $name, false);
        if ($matches === []) return null;
        if (count($matches) !== 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_DUPLICATE_NAME', 'Google Drive 目标路径包含同名对象。');
        }
        $this->assertDriveContent($matches[0], $size, $md5);
        return $matches[0];
    }

    /** 音频上传必须有 Google 计算的 MD5，只有大小与摘要都一致才可证明幂等。 */
    private function assertDriveContent(array $item, int $size, string $md5): void
    {
        $remoteSize = $item['size'] ?? null;
        $remoteSize = is_int($remoteSize) ? $remoteSize
            : (is_string($remoteSize) && preg_match('/^\d+$/D', $remoteSize) === 1 ? (int) $remoteSize : -1);
        $remoteMd5 = is_string($item['md5Checksum'] ?? null) ? strtolower($item['md5Checksum']) : '';
        if (($item['mimeType'] ?? null) === self::FOLDER_MIME || $remoteSize !== $size
            || preg_match('/^[a-f0-9]{32}$/D', $remoteMd5) !== 1 || !hash_equals($md5, $remoteMd5)) {
            throw new RemotePublicationConflict();
        }
    }

    /** 删除当前调用明确创建的对象；删除失败也失败关闭并保留远端现场供人工审计。 */
    private function deleteOwnedItem(string $id): void
    {
        if (!$this->validIdentifier($id, 256)) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_UPLOAD_COMPENSATION_FAILED', 'Google Drive 上传补偿失败。');
        }
        $this->api('DELETE', self::API . '/files/' . rawurlencode($id), [
            'query' => $this->driveScopeQuery(false),
        ], [204]);
    }

    private function publicationVersion(string $relativePath, array $item, string $sha256): string
    {
        return hash('sha256', $relativePath . "\0" . (string) ($item['id'] ?? '') . "\0"
            . (string) ($item['modifiedTime'] ?? '') . "\0" . $sha256);
    }

    /** 对扫描绑定对象执行严格 Range GET；对象变化、忽略 Range 或重定向都失败关闭。 */
    public function openRange(WebDavObject $object, int $start, ?int $end = null): WebDavRangeResponse
    {
        $relative = $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $start < 0 || $start >= $object->size
            || ($end !== null && ($end < $start || $end >= $object->size))) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RANGE_INVALID', 'Google Drive 字节区间无效。');
        }
        $item = $this->findPathItem($relative, false);
        $current = $this->mapItem($item, $relative);
        if (!$current instanceof WebDavObject || $current->directory || $current->size !== $object->size
            || $current->modifiedAt !== $object->modifiedAt || !hash_equals($object->etag, $current->etag)) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_OBJECT_CHANGED', 'Google Drive 对象在读取前已经变化。');
        }
        $fileId = (string) $item['id'];
        $response = $this->api('GET', self::API . '/files/' . rawurlencode($fileId), [
            'query' => ['alt' => 'media'] + $this->driveScopeQuery(false),
            'headers' => ['Range' => 'bytes=' . $start . '-' . ($end ?? '')],
            'stream' => true,
            'timeout' => 60,
        ], [206]);
        if (preg_match('/^bytes (\d+)-(\d+)\/(\d+)$/', trim($response->getHeaderLine('Content-Range')), $match) !== 1) {
            $response->getBody()->close();
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RANGE_UNSUPPORTED', 'Google Drive 未返回有效按需读取响应。');
        }
        $actualStart = (int) $match[1];
        $actualEnd = (int) $match[2];
        $total = (int) $match[3];
        $length = trim($response->getHeaderLine('Content-Length'));
        if ($actualStart !== $start || $actualEnd < $actualStart || $actualEnd >= $total || $total !== $object->size
            || ($end !== null && $actualEnd > $end)
            || ($length !== '' && (preg_match('/^\d+$/', $length) !== 1
                || (int) $length !== $actualEnd - $actualStart + 1))) {
            $response->getBody()->close();
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_OBJECT_CHANGED', 'Google Drive 对象在读取期间发生变化。');
        }
        return new WebDavRangeResponse($actualStart, $actualEnd, $total, $response->getBody());
    }

    /** 把 OAuth 秘密仅经 stdin 交给一次性回环代理，不进入 argv、环境变量或临时文件。 */
    public function openRangeProxy(WebDavObject $object, bool $continuousResponse = false): WebDavRangeProxySession
    {
        $this->assertRelativePath($object->relativePath, false);
        if ($object->directory || $object->size < 1 || $object->etag === '') {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RANGE_INVALID', 'Google Drive 探测对象无效。');
        }
        return WebDavRangeProxySession::start([
            'sourceType' => 'google_drive',
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
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

    private function fetchItem(string $relativePath): WebDavObject
    {
        $relativePath = $this->assertRelativePath($relativePath, true);
        $mapped = $this->mapItem($this->findPathItem($relativePath, true), $relativePath);
        if (!$mapped instanceof WebDavObject) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_ROOT_INVALID', 'Google Drive 根目录不存在。');
        }
        return $mapped;
    }

    /** @return array<string,mixed> */
    private function findPathItem(string $relativePath, bool $allowDirectory): array
    {
        $segments = $relativePath === '' ? [] : explode('/', $relativePath);
        $parent = $this->resolveConfiguredRootId();
        if ($segments === []) return $this->file($parent);
        foreach ($segments as $index => $segment) {
            $last = $index === array_key_last($segments);
            $matches = $this->findNamedChildren($parent, $segment, !$last || $allowDirectory);
            if (count($matches) !== 1) {
                throw new GoogleDriveUnavailable(
                    count($matches) > 1 ? 'GOOGLE_DRIVE_DUPLICATE_NAME' : 'GOOGLE_DRIVE_OBJECT_CHANGED',
                    count($matches) > 1 ? 'Google Drive 路径包含同名对象。' : 'Google Drive 对象不存在。',
                );
            }
            $parent = (string) $matches[0]['id'];
            if ($last) return $matches[0];
        }
        throw new GoogleDriveUnavailable('GOOGLE_DRIVE_OBJECT_CHANGED', 'Google Drive 对象不存在。');
    }

    private function resolveFolderId(string $relativeDirectory): string
    {
        $item = $this->findPathItem($relativeDirectory, true);
        if (($item['mimeType'] ?? null) !== self::FOLDER_MIME) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_ROOT_INVALID', 'Google Drive 目录不存在。');
        }
        return (string) $item['id'];
    }

    private function resolveConfiguredRootId(): string
    {
        $parent = $this->driveId;
        foreach ($this->rootSegments() as $segment) {
            $matches = $this->findNamedChildren($parent, $segment, true);
            if (count($matches) !== 1) {
                throw new GoogleDriveUnavailable(
                    count($matches) > 1 ? 'GOOGLE_DRIVE_DUPLICATE_NAME' : 'GOOGLE_DRIVE_ROOT_INVALID',
                    count($matches) > 1 ? 'Google Drive 根路径包含同名目录。' : 'Google Drive 根目录不存在。',
                );
            }
            $parent = (string) $matches[0]['id'];
        }
        return $parent;
    }

    /** @return list<array<string,mixed>> */
    private function findNamedChildren(string $parentId, string $name, bool $folderOnly): array
    {
        $q = "'" . $this->queryLiteral($parentId) . "' in parents and name = '"
            . $this->queryLiteral($name) . "' and trashed = false";
        if ($folderOnly) $q .= " and mimeType = '" . self::FOLDER_MIME . "'";
        $payload = $this->json($this->api('GET', self::API . '/files', ['query' => [
            'q' => $q,
            'pageSize' => 2,
            'fields' => 'files(id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId)',
            'spaces' => 'drive',
        ] + $this->driveScopeQuery(true)]));
        $files = $payload['files'] ?? null;
        return is_array($files) && array_is_list($files) ? array_values(array_filter($files, 'is_array')) : [];
    }

    /** @return array<string,mixed> */
    private function file(string $id): array
    {
        return $this->json($this->api('GET', self::API . '/files/' . rawurlencode($id), ['query' => [
            'fields' => 'id,name,mimeType,size,modifiedTime,md5Checksum,trashed,driveId',
        ] + $this->driveScopeQuery(false)]));
    }

    /** @param array<string,mixed> $item Google Workspace 原生文档返回 null 并由目录发现忽略。 */
    private function mapItem(array $item, string $relativePath): ?WebDavObject
    {
        $id = $item['id'] ?? null;
        $mime = $item['mimeType'] ?? null;
        $modified = is_string($item['modifiedTime'] ?? null) ? strtotime($item['modifiedTime']) : false;
        if (!is_string($id) || !$this->validIdentifier($id, 256) || !is_string($mime)
            || ($item['trashed'] ?? false) === true || $modified === false || $modified < 0) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 对象事实无效。');
        }
        if ($mime !== self::FOLDER_MIME && str_starts_with($mime, 'application/vnd.google-apps.')) return null;
        $directory = $mime === self::FOLDER_MIME;
        $sizeValue = $item['size'] ?? null;
        $size = is_int($sizeValue) ? $sizeValue
            : (is_string($sizeValue) && preg_match('/^\d+$/', $sizeValue) === 1 ? (int) $sizeValue : -1);
        if (!$directory && $size < 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 音频对象大小无效。');
        }
        $md5 = is_string($item['md5Checksum'] ?? null) ? strtolower($item['md5Checksum']) : '';
        if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 对象摘要无效。');
        }
        $etag = $this->discoveryEtag($item);
        return new WebDavObject($relativePath, $directory, $directory ? 0 : $size, (int) $modified, $etag);
    }

    /**
     * 生成与目录发现完全一致的稳定 ETag。
     *
     * Google Drive API 没有通用 HTTP ETag，库存身份由对象 ID、大小、修改时间和 MD5 共同摘要。上传回执
     * 和后续 list 必须调用同一函数，否则发布元数据可能错误绑定到同路径的替换对象。调用方已经完成
     * item 字段协议校验，本方法只做确定性转换，不访问网络。
     *
     * @param array<string,mixed> $item
     */
    private function discoveryEtag(array $item): string
    {
        $id = (string) ($item['id'] ?? '');
        $size = $item['size'] ?? 0;
        $modified = is_string($item['modifiedTime'] ?? null) ? strtotime($item['modifiedTime']) : false;
        $md5 = is_string($item['md5Checksum'] ?? null) ? strtolower($item['md5Checksum']) : '';
        return hash('sha256', $id . "\0" . (int) $size . "\0" . (int) ($modified === false ? 0 : $modified) . "\0" . $md5);
    }

    /** 固定 Google API 请求；401 刷新一次，配额与限流按稳定错误码失败关闭。 */
    private function api(string $method, string $uri, array $options = [], array $success = [200]): ResponseInterface
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $response = $this->http->request($method, $uri, $this->options($options, $this->token()));
            } catch (GoogleDriveUnavailable $failure) {
                throw $failure;
            } catch (Throwable) {
                throw new GoogleDriveUnavailable('GOOGLE_DRIVE_CONNECTION_FAILED', '无法连接 Google Drive。');
            }
            $status = $response->getStatusCode();
            if (in_array($status, $success, true)) return $response;
            if ($status === 401 && $attempt === 0) {
                $this->clearAccessToken();
                continue;
            }
            $response->getBody()->close();
            throw match ($status) {
                401 => new GoogleDriveUnavailable('GOOGLE_DRIVE_REAUTHORIZATION_REQUIRED', 'Google Drive 授权已失效，请重新授权。'),
                403 => new GoogleDriveUnavailable('GOOGLE_DRIVE_QUOTA_OR_PERMISSION_DENIED', 'Google Drive 权限或下载配额不允许当前请求。'),
                404 => new GoogleDriveUnavailable('GOOGLE_DRIVE_OBJECT_CHANGED', 'Google Drive 对象不存在。'),
                429 => new GoogleDriveUnavailable('GOOGLE_DRIVE_RATE_LIMITED', 'Google Drive 请求受到限流，请稍后重试。'),
                default => new GoogleDriveUnavailable('GOOGLE_DRIVE_PROTOCOL_REJECTED', 'Google Drive 暂时无法完成请求。'),
            };
        }
        throw new GoogleDriveUnavailable('GOOGLE_DRIVE_AUTH_FAILED', 'Google Drive 认证失败。');
    }

    private function token(): string
    {
        if (is_string($this->accessToken) && time() < $this->tokenExpiresAt) return $this->accessToken;
        try {
            $response = $this->http->request('POST', 'https://oauth2.googleapis.com/token', NetworkProxyRequestOptions::apply([
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                    'grant_type' => 'refresh_token',
                ],
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'connect_timeout' => 10, 'timeout' => 20,
            ], $this->proxy));
        } catch (Throwable) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_AUTH_FAILED', '无法完成 Google Drive 认证。');
        }
        if ($response->getStatusCode() !== 200) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_REAUTHORIZATION_REQUIRED', 'Google Drive 授权已失效，请重新授权。');
        }
        $payload = $this->json($response);
        $token = $payload['access_token'] ?? null;
        $expires = $payload['expires_in'] ?? null;
        if (!is_string($token) || !$this->validToken($token) || !is_int($expires) || $expires < 60) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_AUTH_FAILED', 'Google Drive 认证响应无效。');
        }
        $this->clearAccessToken();
        $this->accessToken = $token;
        $this->tokenExpiresAt = time() + max(30, min($expires - 30, 3300));
        return $this->accessToken;
    }

    private function clearAccessToken(): void
    {
        if (is_string($this->accessToken) && $this->accessToken !== '') sodium_memzero($this->accessToken);
        $this->accessToken = null;
        $this->tokenExpiresAt = 0;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof() && strlen($body) <= self::MAX_JSON_BYTES) {
            $body .= $stream->read(min(8192, self::MAX_JSON_BYTES + 1 - strlen($body)));
        }
        try {
            $payload = strlen($body) <= self::MAX_JSON_BYTES
                ? json_decode($body, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_RESPONSE_INVALID', 'Google Drive 响应无效。');
        }
        return $payload;
    }

    /**
     * 生成与盘类型严格一致的 Drive API 范围参数。
     *
     * My Drive 使用 `corpora=user` 且显式关闭 all-drives，防止配置根意外扩展到共享内容；Shared Drive
     * 只使用管理员提交并已校验的 drive ID，同时开启 Google 对共享盘请求要求的两个开关。目录列表才
     * 接受 includeItemsFromAllDrives，单对象与媒体 Range 请求不发送无意义参数。该方法无副作用。
     *
     * @return array<string,string>
     */
    private function driveScopeQuery(bool $listing): array
    {
        $sharedDrive = $this->driveId !== 'root';
        $query = ['supportsAllDrives' => $sharedDrive ? 'true' : 'false'];
        if ($listing) $query['includeItemsFromAllDrives'] = $sharedDrive ? 'true' : 'false';
        if (!$listing) return $query;
        return $sharedDrive
            ? $query + ['corpora' => 'drive', 'driveId' => $this->driveId]
            : $query + ['corpora' => 'user'];
    }

    /** 合并 Bearer 时保留 Range 等调用方 header，禁止调用方覆盖 Authorization。 */
    private function options(array $options, string $token): array
    {
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        unset($headers['Authorization']);
        $options['headers'] = $headers + ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        return NetworkProxyRequestOptions::apply($options + [
            'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
            'connect_timeout' => 10, 'timeout' => 30,
        ], $this->proxy);
    }

    private function childName(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1024 || $value === '.' || $value === '..'
            || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, "\0")) {
            throw new GoogleDriveUnavailable('GOOGLE_DRIVE_NAME_UNREPRESENTABLE', 'Google Drive 对象名称无法安全表示。');
        }
        return $value;
    }

    private function assertRelativePath(string $path, bool $allowEmpty): string
    {
        if (($path === '' && $allowEmpty) || ($path !== '' && $this->validRoot('/' . $path))) return $path;
        throw new GoogleDriveUnavailable('GOOGLE_DRIVE_PATH_INVALID', 'Google Drive 对象路径无效。');
    }

    /** @return list<string> */
    private function rootSegments(): array
    {
        return $this->remoteRootPath === '/' ? [] : explode('/', trim($this->remoteRootPath, '/'));
    }

    private function validRoot(string $path): bool
    {
        if (!str_starts_with($path, '/') || strlen($path) > 4096 || str_contains($path, "\0")
            || str_contains($path, '\\') || ($path !== '/' && str_ends_with($path, '/'))) return false;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '/')) return false;
        }
        return true;
    }

    private function queryLiteral(string $value): string { return str_replace(['\\', "'"], ['\\\\', "\\'"], $value); }
    private function validClientId(string $value): bool { return strlen($value) <= 255 && preg_match('/^[A-Za-z0-9._-]{12,220}\.apps\.googleusercontent\.com$/', $value) === 1; }
    private function validSecret(string $value): bool { return strlen($value) >= 16 && strlen($value) <= 512 && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1; }
    private function validToken(string $value): bool { return $value !== '' && strlen($value) <= 65_536 && !str_contains($value, "\0"); }
    private function validIdentifier(string $value, int $max): bool { return $value !== '' && strlen($value) <= $max && !str_contains($value, "\0"); }
}
