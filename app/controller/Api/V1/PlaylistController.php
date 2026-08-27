<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistCoverInvalid;
use app\application\Playlist\PlaylistCoverService;
use app\application\Playlist\M3uExportService;
use app\application\Playlist\M3uImportService;
use app\application\Playlist\M3uSourceInvalid;
use app\application\Playlist\M3uSyncService;
use app\application\Playlist\PlaylistAutoCompletionService;
use app\application\Playlist\PlatformPlaylistImportService;
use app\application\Playlist\PlaylistInvalid;
use app\application\Playlist\PlaylistImportInvalid;
use app\application\Playlist\PlaylistLinkInvalid;
use app\application\Playlist\PlaylistLinkUnavailable;
use app\application\Playlist\PlaylistItemNotFound;
use app\application\Playlist\PlaylistNotFound;
use app\application\Playlist\PlaylistService;
use app\application\Playlist\PlaylistValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;
use Webman\Http\UploadFile;

/**
 * 提供用户/系统歌单浏览、所有者命令、M3U 工作流和自定义封面接口（LIST-001/001A/002/007）。
 *
 * 读取要求 play，并由领域服务再次校验 owner/server 可见性及实时歌曲授权；写入要求 create_playlist、
 * 所有权、共享 expectedVersion 和路由 CSRF。封面上传使用原始正文且会在服务端重编码，请求载荷、名称、
 * 说明、歌曲 ID、图片字节和客户端文件名均不进入日志。Controller 只负责 HTTP 映射，不直接修改数据库。
 */
final class PlaylistController
{
    /** 返回当前账号可见的用户歌单和公开系统歌单；系统项由领域服务固定排在用户项之前。 */
    public function index(Request $request): Response
    {
        return $this->read($request, null);
    }

    /** 返回独立的系统推荐歌单列表；合并的普通列表接口默认也会返回这些项目。 */
    public function systemIndex(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $data = (new PlaylistService())->systemPage(
                $actor,
                is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50,
                is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0,
            );
            return JsonResponseFactory::create(['data' => $data, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'System playlist read failed.');
        }
    }

    /** Returns one readable playlist with ordered authorized song projections. */
    public function show(Request $request, string $playlistId): Response
    {
        return $this->read($request, $playlistId);
    }

    /**
     * 返回当前账号可读取的歌单 WebP 封面。
     *
     * play 能力和歌单 owner/server 可见性都会在返回 BLOB 前复验；私有失权、无封面和非法 ID 使用相同
     * 404。响应只允许私有条件缓存并带 nosniff，ETag 不是授权凭据。
     */
    public function cover(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $image = (new PlaylistCoverService())->image($actor, $playlistId);
            $headers = [
                'Content-Type' => 'image/webp',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=86400, must-revalidate',
                'ETag' => $image->etag,
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
            ];
            if (trim((string) $request->header('if-none-match', '')) === $image->etag) {
                return response('', 304, $headers);
            }
            $headers['Content-Length'] = (string) strlen($image->bytes);

            return response($image->bytes, 200, $headers);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist cover read failed.');
        }
    }

    /**
     * 接收原始 JPEG/PNG/WebP 请求体并在歌单版本锁下替换所有者封面。
     *
     * `X-Expected-Version` 必须是规范正整数，路由已执行 CSRF；服务端不接收 multipart 文件名或目标
     * 路径。图片在事务外规范化，提交后返回完整歌单以刷新 version 与 coverUrl。
     */
    public function uploadCover(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $rawVersion = (string) $request->header('x-expected-version', '');
            if (preg_match('/^[1-9][0-9]{0,9}$/', $rawVersion) !== 1) {
                throw new PlaylistCoverInvalid('Playlist cover version is invalid.');
            }
            (new PlaylistCoverService())->upload(
                $actor,
                $playlistId,
                $request->rawBody(),
                (string) $request->header('content-type', ''),
                (int) $rawVersion,
                $requestId,
            );

            return $this->playlistResponse((new PlaylistService())->detail($actor, $playlistId), $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist cover upload failed.');
        }
    }

    /** 删除所有者封面并返回共享版本已更新的完整歌单；不存在封面时保持幂等。 */
    public function deleteCover(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $expectedVersion = (new PlaylistValidator())->version(
                is_array($payload) ? ($payload['expectedVersion'] ?? null) : null,
            );
            (new PlaylistCoverService())->delete($actor, $playlistId, $expectedVersion, $requestId);

            return $this->playlistResponse((new PlaylistService())->detail($actor, $playlistId), $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist cover delete failed.');
        }
    }

    /** Creates one empty ordinary playlist for a principal with create_playlist capability. */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $metadata = (new PlaylistValidator())->create(is_array($payload) ? $payload : []);
            $playlist = (new PlaylistService())->create($actor, $metadata);

            return $this->playlistResponse($playlist, $requestId, 201);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist create failed.');
        }
    }

    /**
     * Imports one bounded M3U/M3U8 文件或固定平台的公开 HTTPS 歌单链接。
     *
     * 文件只在 Webman 请求临时目录中读取一次，并受 1 MiB 上限约束，不会移动到媒体、缓存或运行时目录。
     * 读取前会检查扩展名和上传状态；幂等键只保存 HMAC 摘要。响应报告不包含客户端或服务器路径，且
     * 使用 private no-store。文件模式只接受 M3U/M3U8；链接模式提供 `mode=link`、平台键和 link，不接受
     * Cookie、Token 或任意请求头，平台私有 ID 只用于本次匹配，不会持久化；两种模式均保留未匹配条目。
     */
    public function import(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $payload = is_array($payload) ? $payload : [];
            // 兼容旧版前端：只要请求带有 link 字段，就按链接导入处理，不能误落到文件上传分支。
            $mode = is_string($payload['mode'] ?? null) ? strtolower(trim($payload['mode'])) : '';
            if ($mode === '' && array_key_exists('link', $payload)) {
                $mode = 'link';
            }
            $mode = $mode === '' ? 'file' : $mode;
            if ($mode === 'link') {
                $source = is_string($payload['format'] ?? null) ? strtolower(trim($payload['format'])) : '';
                $link = is_string($payload['link'] ?? null) ? trim($payload['link']) : '';
                $providers = (new \app\application\Playlist\PluginPlaylistIdentificationGateway())->sources();
                $labels = [];
                foreach ($providers as $provider) {
                    if (is_array($provider) && is_string($provider['key'] ?? null) && is_string($provider['name'] ?? null)) {
                        $labels[$provider['key']] = $provider['name'] . '歌单';
                    }
                }
                if (!isset($labels[$source]) || $link === '') {
                    throw new PlaylistLinkInvalid();
                }
                $payload['name'] = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
                    ? mb_substr(trim($payload['name']), 0, 100) : $labels[$source];
                $metadata = (new PlaylistValidator())->create($payload);
                $idempotencyKey = (string) $request->header('idempotency-key', '');
                if ($idempotencyKey === '' && is_string($payload['idempotencyKey'] ?? null)) {
                    $idempotencyKey = trim($payload['idempotencyKey']);
                }
                $result = (new PlatformPlaylistImportService())->importLink(
                    $actor, $source, $link, $metadata, $idempotencyKey, $requestId,
                );
                return JsonResponseFactory::create([
                    'data' => $result,
                    'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
                ], 201, $requestId)->withHeader('Cache-Control', 'private, no-store');
            }
            $file = $request->file('file');
            if (!$file instanceof UploadFile || !$file->isValid()) {
                throw new PlaylistImportInvalid('upload_failed');
            }
            $extension = strtolower($file->getUploadExtension());
            $size = $file->getSize();
            if (!in_array($extension, ['m3u', 'm3u8'], true)
                || $size < 1 || $size > \app\application\Playlist\M3uParser::MAX_BYTES) {
                throw new PlaylistImportInvalid('invalid_upload');
            }
            $bytes = file_get_contents($file->getPathname(), false, null, 0, \app\application\Playlist\M3uParser::MAX_BYTES + 1);
            if (!is_string($bytes) || $bytes === '') {
                throw new PlaylistImportInvalid('upload_read_failed');
            }
            $name = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
                ? trim($payload['name'])
                : trim((string) pathinfo((string) $file->getUploadName(), PATHINFO_FILENAME));
            if ($name === '') {
                $name = '导入歌单';
            }
            $payload['name'] = mb_substr($name, 0, 100);
            $metadata = (new PlaylistValidator())->create($payload);
            $idempotencyKey = (string) $request->header('idempotency-key', '');
            $result = (new M3uImportService())->import($actor, $bytes, $metadata, $idempotencyKey, $requestId);

            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId)->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist import failed.');
        }
    }

    /** Exports one currently readable list without paths, embedded credentials, or state changes. */
    public function export(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $document = (new M3uExportService())->export($actor, $playlistId);
            $fallback = 'playlist-' . substr(hash('sha256', $playlistId), 0, 12) . '.m3u8';

            return response($document['content'], 200, [
                'Content-Type' => 'audio/x-mpegurl; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $fallback
                    . '"; filename*=UTF-8\'\'' . rawurlencode($document['filename']),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Request-ID' => $requestId,
            ]);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist M3U export failed.');
        }
    }

    /** Returns the owner-only path-free M3U binding and sources from currently granted libraries. */
    public function m3uSyncShow(Request $request, string $playlistId): Response
    {
        return $this->m3uCommand($request, $playlistId, 'show');
    }

    /** Binds an opaque scanner source and atomically applies its current order under dual versions. */
    public function m3uSyncBind(Request $request, string $playlistId): Response
    {
        return $this->m3uCommand($request, $playlistId, 'bind');
    }

    /** Checks a bound source now; normal execution records conflict rather than overwriting edits. */
    public function m3uSyncRun(Request $request, string $playlistId): Response
    {
        return $this->m3uCommand($request, $playlistId, 'sync');
    }

    /** Resolves conflict explicitly by using file order or retaining the current playlist as baseline. */
    public function m3uSyncResolve(Request $request, string $playlistId): Response
    {
        return $this->m3uCommand($request, $playlistId, 'resolve');
    }

    /** Removes only the rule; neither current playlist items nor the library source file is changed. */
    public function m3uSyncUnbind(Request $request, string $playlistId): Response
    {
        return $this->m3uCommand($request, $playlistId, 'unbind');
    }

    /** Replaces owner-controlled name, description, and visibility under optimistic locking. */
    public function update(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $command = (new PlaylistValidator())->update(is_array($payload) ? $payload : []);
            $playlist = (new PlaylistService())->update($actor, $playlistId, $command);

            return $this->playlistResponse($playlist, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist update failed.');
        }
    }

    /**
     * 在一个版本检查和 SQLite 事务内替换歌单元数据及完整有序曲目。
     *
     * 调用者必须具有 create_playlist 能力且是手工歌单所有者。请求携带客户端读取
     * 到的 expectedVersion，冲突返回 409 并保持名称、说明、可见范围和曲目全部不变；
     * 歌曲权限、重复项和顺序由 PlaylistService 统一复验，Controller 不拆分写入。
     */
    public function replaceAll(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $command = (new PlaylistValidator())->replaceAll(is_array($payload) ? $payload : []);
            $playlist = (new PlaylistService())->replaceAll($actor, $playlistId, $command);

            return $this->playlistResponse($playlist, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist complete replacement failed.');
        }
    }

    /** Atomically replaces the complete ordered song ID list under optimistic locking. */
    public function replaceItems(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $command = (new PlaylistValidator())->items(is_array($payload) ? $payload : []);
            $playlist = (new PlaylistService())->replaceItems($actor, $playlistId, $command);

            return $this->playlistResponse($playlist, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist item replacement failed.');
        }
    }

    /**
     * 由用户歌单所有者登记一条暂时没有本地资源的歌曲，并在自动补全开启时提交后台任务。
     *
     * 请求只接受歌名、艺人、可选专辑和 expectedVersion；路由 CSRF、create_playlist 和服务层所有权检查
     * 共同保护写入。响应仍是完整歌单快照，客户端必须以新版本替换本地状态。
     */
    public function addManualEntry(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $payload = $request->post();
            $command = (new PlaylistValidator())->manualEntry(is_array($payload) ? $payload : []);
            $playlist = (new PlaylistService())->addManualEntry($actor, $playlistId, $command);
            (new PlaylistAutoCompletionService())->enqueueMissingForPlaylist(
                $playlistId,
                (string) $actor['id'],
                (bool) ($actor['isSuperAdmin'] ?? false),
            );

            return $this->playlistResponse($playlist, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist manual entry failed.');
        }
    }

    /** Deletes an owned playlist only when the body version matches the committed header. */
    public function delete(Request $request, string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $version = (new PlaylistValidator())->version($request->post('expectedVersion'));
            (new PlaylistService())->delete($actor, $playlistId, $version);

            return JsonResponseFactory::create([
                'data' => ['deleted' => true],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist delete failed.');
        }
    }

    /** Handles both list and detail reads under the play capability. */
    private function read(Request $request, ?string $playlistId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $service = new PlaylistService();
            $scope = $request->get('scope', 'all');
            if (!is_string($scope) || !in_array($scope, ['all', 'user'], true)) {
                throw new PlaylistInvalid('Playlist scope is invalid.');
            }
            $data = $playlistId === null
                ? $service->page(
                    $actor,
                    is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50,
                    is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0,
                    $scope,
                )
                : ['playlist' => $service->detail($actor, $playlistId)];

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist read failed.');
        }
    }

    /**
     * Maps the compact M3U sync HTTP surface to one service while keeping all file/path logic outside
     * the controller. Every mutation route has CSRF middleware and accepts only opaque IDs, positive
     * versions, and a two-value resolution strategy; no arbitrary path or uploaded bytes are accepted.
     */
    private function m3uCommand(Request $request, string $playlistId, string $command): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'create_playlist');
            $service = new M3uSyncService();
            $payload = $request->post();
            $payload = is_array($payload) ? $payload : [];
            $validator = new PlaylistValidator();
            $data = match ($command) {
                'show' => $service->snapshot($actor, $playlistId),
                'bind' => $service->bind(
                    $actor, $playlistId, $this->sourceId($payload['sourceId'] ?? null),
                    $validator->version($payload['expectedPlaylistVersion'] ?? null),
                    isset($payload['expectedRuleVersion'])
                        ? $validator->version($payload['expectedRuleVersion']) : null,
                    $requestId,
                ),
                'sync' => $service->synchronize(
                    $actor, $playlistId,
                    $validator->version($payload['expectedPlaylistVersion'] ?? null),
                    $validator->version($payload['expectedRuleVersion'] ?? null),
                    false, $requestId,
                ),
                'resolve' => $this->resolveM3uConflict($service, $actor, $playlistId, $payload, $validator, $requestId),
                'unbind' => $service->unbind(
                    $actor, $playlistId, $validator->version($payload['expectedRuleVersion'] ?? null), $requestId,
                ),
                default => throw new PlaylistInvalid('Unknown M3U command.'),
            };

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId)->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Playlist M3U sync command failed.');
        }
    }

    /** Dispatches the only two destructive conflict choices after exact strategy validation. */
    private function resolveM3uConflict(
        M3uSyncService $service,
        array $actor,
        string $playlistId,
        array $payload,
        PlaylistValidator $validator,
        string $requestId,
    ): array {
        $strategy = $payload['strategy'] ?? null;
        if (!is_string($strategy) || !in_array($strategy, ['use_file', 'keep_playlist'], true)) {
            throw new PlaylistInvalid('M3U conflict strategy is invalid.');
        }
        $playlistVersion = $validator->version($payload['expectedPlaylistVersion'] ?? null);
        $ruleVersion = $validator->version($payload['expectedRuleVersion'] ?? null);

        return $strategy === 'use_file'
            ? $service->synchronize($actor, $playlistId, $playlistVersion, $ruleVersion, true, $requestId)
            : $service->keepPlaylist($actor, $playlistId, $playlistVersion, $ruleVersion, $requestId);
    }

    /** Accepts only an opaque scanner-issued source identifier, never a filesystem path. */
    private function sourceId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new PlaylistInvalid('M3U source ID is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $playlist Emits one stable playlist mutation envelope. */
    private function playlistResponse(array $playlist, string $requestId, int $status = 200): Response
    {
        return JsonResponseFactory::create([
            'data' => ['playlist' => $playlist],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], $status, $requestId);
    }

    /** Maps stable failures without leaking private playlist existence or media IDs. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行播放列表操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof PlaylistCoverInvalid) {
            return JsonResponseFactory::error(
                'PLAYLIST_COVER_INVALID',
                '请选择 5 MiB 以内的 JPEG、PNG 或 WebP 图片。',
                422,
                $requestId,
            );
        }
        if ($throwable instanceof PlaylistInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查播放列表数据。', 422, $requestId);
        }
        if ($throwable instanceof PlaylistImportInvalid) {
            $messages = [
                'invalid_idempotency_key' => '导入请求标识无效，请刷新页面后重试。',
                'unsupported_platform_format' => '当前文件不是已支持的平台歌单格式。',
                'invalid_platform_json' => '平台歌单文件不是有效的 UTF-8 JSON。',
                'invalid_size_or_encoding' => '歌单文件为空、过大或编码不受支持。',
                'platform_playlist_empty' => '平台歌单文件中没有歌曲。',
                'invalid_upload' => '上传文件扩展名或大小不符合要求。',
                'upload_failed' => '没有收到有效的歌单文件。',
            ];
            $message = $messages[$throwable->reasonCode] ?? '请检查歌单文件格式、编码、大小、平台类型和幂等键。';
            return JsonResponseFactory::error('PLAYLIST_IMPORT_INVALID', $message, 422, $requestId, [
                'reasonCode' => $throwable->reasonCode,
            ]);
        }
        if ($throwable instanceof PlaylistLinkInvalid) {
            return JsonResponseFactory::error('PLAYLIST_IMPORT_INVALID', '仅支持指定平台的 HTTPS 公开歌单链接。', 422, $requestId);
        }
        if ($throwable instanceof PlaylistLinkUnavailable) {
            $messages = [
                'helper_unavailable' => '歌单解析器不可用，请稍后重试。',
                'request_timeout_or_start_failed' => '平台响应超时或解析器启动失败，请稍后重试。',
                'provider_request_failed' => '平台暂时拒绝或无法访问该链接，请确认歌单公开后重试。',
                'invalid_helper_response' => '平台返回的数据格式异常，暂时无法导入。',
                'playlist_empty' => '链接对应的公开歌单没有可导入歌曲。',
            ];
            $message = $messages[$throwable->reasonCode] ?? '歌单链接暂时无法解析，请稍后重试。';
            return JsonResponseFactory::error('PLAYLIST_LINK_UNAVAILABLE', $message, 503, $requestId);
        }
        if ($throwable instanceof M3uSourceInvalid) {
            return JsonResponseFactory::error('PLAYLIST_M3U_SOURCE_INVALID', 'M3U 源不可用、已变化或文件内容无效。', 422, $requestId);
        }
        if ($throwable instanceof PlaylistNotFound) {
            return JsonResponseFactory::error('PLAYLIST_NOT_FOUND', '播放列表不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof PlaylistItemNotFound) {
            return JsonResponseFactory::error('PLAYLIST_SONG_NOT_FOUND', '一首或多首歌曲不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof PlaylistConflict) {
            return JsonResponseFactory::error('PLAYLIST_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('PLAYLIST_UNAVAILABLE', '播放列表服务暂时不可用。', 503, $requestId);
    }
}
