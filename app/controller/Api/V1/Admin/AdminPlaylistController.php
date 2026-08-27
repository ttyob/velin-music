<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playlist\AdminPlaylistService;
use app\application\Playlist\PlaylistAutoCompletionService;
use app\application\Playlist\M3uImportService;
use app\application\Playlist\M3uParser;
use app\application\Playlist\PlatformPlaylistImportService;
use app\application\Playlist\PlaylistConflict;
use app\application\Playlist\PlaylistCoverInvalid;
use app\application\Playlist\PlaylistCoverService;
use app\application\Playlist\PlaylistImportInvalid;
use app\application\Playlist\PlaylistInvalid;
use app\application\Playlist\PlaylistLinkInvalid;
use app\application\Playlist\PlaylistLinkUnavailable;
use app\application\Playlist\PlaylistNotFound;
use app\application\Playlist\PlaylistResourceDetectionService;
use app\application\Playlist\PlaylistValidator;
use app\application\Recommendation\LastfmRecommendationAuthenticationFailed;
use app\application\Recommendation\LastfmRecommendationConflict;
use app\application\Recommendation\LastfmRecommendationInvalid;
use app\application\Recommendation\LastfmRecommendationService;
use app\application\Recommendation\LastfmRecommendationUnavailable;
use app\application\Recommendation\PublicPlaylistRecommendationConflict;
use app\application\Recommendation\PublicPlaylistRecommendationInvalid;
use app\application\Recommendation\PublicPlaylistRecommendationService;
use app\application\Recommendation\PublicPlaylistRecommendationUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Db;
use support\Request;
use support\Response;
use Throwable;
use Webman\Http\UploadFile;

/**
 * 将后台歌单目录、系统歌单写入和 Last.fm 多规则同步映射到管理 API。默认同步插件覆盖历史
 * `['netease', 'qq', 'kugou']`，实际来源仍以插件 manifest/Hook 声明为准。
 *
 * 每个入口实时要求 `manage_system`，所有写路由另由 CSRF 中间件保护。用户列表返回歌单头摘要；补全开关
 * 和后台详情可作用于用户或系统歌单，但歌曲仍按管理员实时音乐库授权过滤，普通用户路由不会获得旁路。
 * 系统导入复用现有有界解析与本地
 * 授权匹配，但明确传入 `scope=system` 并固定 server 可见。Controller 不直接写数据库、不记录上传
 * 正文、链接或 API Key，错误只返回稳定机器码和脱敏中文提示。
 */
final class AdminPlaylistController
{
    /** 默认按 user scope 分页，支持页面 Tab 显式传 system。 */
    public function index(Request $request): Response
    {
        return $this->execute($request, static function () use ($request): array {
            $scope = is_string($request->get('scope')) ? (string) $request->get('scope') : 'user';
            $search = is_string($request->get('search')) ? (string) $request->get('search') : '';
            return (new AdminPlaylistService())->page(
                $scope,
                $search,
                is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50,
                is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0,
            );
        });
    }

    /** 返回用户或系统歌单的有序可播放项和资源缺失条目；歌曲仍按管理员实时音乐库授权过滤。 */
    public function show(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new AdminPlaylistService())->detailAdmin($actor, $playlistId));
    }

    /** 返回固定 Last.fm 预置目录和脱敏全局配置，密钥不会回读。 */
    public function lastfmPresets(Request $request): Response
    {
        return $this->execute($request, static fn (): array => [
            'presets' => (new LastfmRecommendationService())->presets(),
            'settings' => (new LastfmRecommendationService())->snapshot(),
        ]);
    }

    /** 创建一条独立 Last.fm 系统歌单；自动同步由 Worker 异步执行。 */
    public function createLastfm(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new LastfmRecommendationInvalid();
            return (new LastfmRecommendationService())->createPreset($actor, $payload, RequestContext::requestId());
        }, 201);
    }

    /** 返回同步插件声明的官方榜单目录；平台详情只在受控插件 Helper 内解析。 */
    public function publicCatalog(Request $request): Response
    {
        return $this->execute($request, static function () use ($request): array {
            $provider = is_string($request->get('provider')) ? strtolower(trim($request->get('provider'))) : '';
            return (new PublicPlaylistRecommendationService())->catalog($provider);
        });
    }

    /** 创建一条平台官方榜单系统歌单，自动同步仍由单消费者 Worker 执行。 */
    public function createPublic(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PublicPlaylistRecommendationInvalid();
            return (new PublicPlaylistRecommendationService())->createPreset($actor, $payload, RequestContext::requestId());
        }, 201);
    }

    /** 用歌单版本锁修改系统歌单名称和说明。 */
    public function update(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PlaylistInvalid('payload is invalid.');
            return (new AdminPlaylistService())->updateSystem(
                $playlistId, $payload, (string) $actor['id'], RequestContext::requestId(),
            );
        });
    }

    /** 删除系统歌单业务记录，不触碰媒体文件。 */
    public function delete(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            $version = (new PlaylistValidator())->version(is_array($payload) ? ($payload['expectedVersion'] ?? null) : null);
            (new AdminPlaylistService())->deleteSystem(
                $playlistId, $version, (string) $actor['id'], RequestContext::requestId(),
            );
            return ['deleted' => true];
        });
    }

    /**
     * 导入 M3U/M3U8 或固定平台公开链接并在创建事务直接落为系统歌单。
     *
     * 文件上限、编码、路径、URL、匹配和幂等规则与用户导入一致；管理员身份只扩大目标 scope，不允许
     * 任意服务器路径、远端文件抓取或绕过本地歌曲授权。
     */
    public function import(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            $payload = is_array($payload) ? $payload : [];
            $mode = is_string($payload['mode'] ?? null) ? strtolower(trim($payload['mode'])) : 'file';
            $idempotencyKey = trim((string) $request->header('idempotency-key', ''));
            if ($mode === 'link') {
                $source = is_string($payload['format'] ?? null) ? strtolower(trim($payload['format'])) : '';
                $link = is_string($payload['link'] ?? null) ? trim($payload['link']) : '';
            $labels = [];
            foreach ((new \app\application\Playlist\PluginPlaylistIdentificationGateway())->sources() as $provider) {
                if (is_array($provider) && is_string($provider['key'] ?? null) && is_string($provider['name'] ?? null)) {
                    $labels[$provider['key']] = $provider['name'] . '歌单';
                }
            }
            if (!isset($labels[$source]) || $link === '') throw new PlaylistLinkInvalid();
                $payload['name'] = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
                    ? mb_substr(trim($payload['name']), 0, 100) : $labels[$source];
                $metadata = (new PlaylistValidator())->create($payload + ['visibility' => 'server']);
                return (new PlatformPlaylistImportService())->importLink(
                    $actor, $source, $link, $metadata, $idempotencyKey, RequestContext::requestId(), 'system',
                );
            }
            $file = $request->file('file');
            if (!$file instanceof UploadFile || !$file->isValid()) throw new PlaylistImportInvalid('upload_failed');
            $extension = strtolower($file->getUploadExtension());
            $size = $file->getSize();
            if (!in_array($extension, ['m3u', 'm3u8'], true) || $size < 1 || $size > M3uParser::MAX_BYTES) {
                throw new PlaylistImportInvalid('invalid_upload');
            }
            $bytes = file_get_contents($file->getPathname(), false, null, 0, M3uParser::MAX_BYTES + 1);
            if (!is_string($bytes) || $bytes === '') throw new PlaylistImportInvalid('upload_read_failed');
            $fallback = trim((string) pathinfo((string) $file->getUploadName(), PATHINFO_FILENAME));
            $payload['name'] = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
                ? mb_substr(trim($payload['name']), 0, 100) : mb_substr($fallback !== '' ? $fallback : '导入歌单', 0, 100);
            $metadata = (new PlaylistValidator())->create($payload + ['visibility' => 'server']);
            return (new M3uImportService())->import(
                $actor, $bytes, $metadata, $idempotencyKey, RequestContext::requestId(), 'system',
            );
        }, 201);
    }

    /** 接收原始图片正文并在系统歌单版本锁下更新封面。 */
    public function uploadCover(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $rawVersion = (string) $request->header('x-expected-version', '');
            if (preg_match('/^[1-9][0-9]{0,9}$/', $rawVersion) !== 1) throw new PlaylistCoverInvalid();
            (new PlaylistCoverService())->upload(
                $actor, $playlistId, $request->rawBody(), (string) $request->header('content-type', ''),
                (int) $rawVersion, RequestContext::requestId(), true,
            );
            return (new AdminPlaylistService())->findSystem($playlistId);
        });
    }

    /** 删除系统歌单封面；没有封面时保持幂等。 */
    public function deleteCover(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            $version = (new PlaylistValidator())->version(is_array($payload) ? ($payload['expectedVersion'] ?? null) : null);
            (new PlaylistCoverService())->delete($actor, $playlistId, $version, RequestContext::requestId(), true);
            return (new AdminPlaylistService())->findSystem($playlistId);
        });
    }

    /** 更新一条规则的自动同步开关。 */
    public function updateLastfmRule(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PlaylistInvalid('同步设置请求无效。');
            $provider = (string) Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->value('provider');
            if (in_array($provider, array_column((new PublicPlaylistRecommendationService())->providers(), 'key'), true)) {
                return (new PublicPlaylistRecommendationService())->updateRule($playlistId, $payload, (string) $actor['id'], RequestContext::requestId());
            }
            return (new LastfmRecommendationService())->updateRule($playlistId, $payload, (string) $actor['id'], RequestContext::requestId());
        });
    }

    /** 更新用户或系统歌单的后台自动补全开关；真正搜索和下载由独立 Worker 异步执行。 */
    public function updateAutoCompletion(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PlaylistInvalid('自动补全请求无效。');
            return (new PlaylistAutoCompletionService())->update(
                $playlistId,
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
                (bool) ($actor['isSuperAdmin'] ?? false),
            );
        });
    }

    /** 重置指定用户或系统歌单中仍缺失歌曲的补全任务；请求使用歌单版本锁并由 Worker 异步重跑。 */
    public function resetAutoCompletion(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId, $request): array {
            $payload = $request->post();
            if (!is_array($payload) || !is_int($payload['expectedVersion'] ?? null)
                || array_diff(array_keys($payload), ['expectedVersion']) !== []) {
                throw new PlaylistInvalid('自动补全重置请求无效。');
            }
            return (new PlaylistAutoCompletionService())->reset(
                $playlistId, $payload['expectedVersion'], (string) $actor['id'], RequestContext::requestId(),
                (bool) ($actor['isSuperAdmin'] ?? false),
            );
        });
    }

    /** 返回指定用户或系统歌单的脱敏补全日志；读取支持分页，不暴露插件句柄、路径或第三方响应。 */
    public function autoCompletionLog(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function () use ($request, $playlistId): array {
            $limit = is_numeric($request->get('limit')) ? (int) $request->get('limit') : 100;
            $offset = is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0;
            return (new PlaylistAutoCompletionService())->log($playlistId, $limit, $offset);
        });
    }

    /** 立即刷新指定 Last.fm 系统歌单，失败时保留旧项目。 */
    public function refreshLastfm(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static function (array $actor) use ($playlistId): array {
            $provider = (string) Db::table('system_playlist_sync_rules')->where('playlist_id', $playlistId)->value('provider');
            if (in_array($provider, array_column((new PublicPlaylistRecommendationService())->providers(), 'key'), true)) {
                return (new PublicPlaylistRecommendationService())->refreshPlaylist($actor, $playlistId, $provider, RequestContext::requestId());
            }
            return (new LastfmRecommendationService())->refreshPlaylist($actor, $playlistId, RequestContext::requestId());
        });
    }

    /** 只用已保存的歌名和艺人证据检测当前媒体库，不访问 Last.fm 或其他平台来源。 */
    public function detectResources(Request $request, string $playlistId): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new PlaylistResourceDetectionService())->detect($actor, $playlistId, RequestContext::requestId()));
    }

    /** 统一认证、no-store 信封和稳定错误映射。 */
    private function execute(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $status, $requestId)->withHeader('Cache-Control', 'private, no-store');
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 将领域异常映射为不泄露路径、密钥和私人歌单内容的管理 API 错误。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        $error = match (true) {
            $throwable instanceof AuthenticationRequired => ['AUTHENTICATION_REQUIRED', '请先登录。', 401],
            $throwable instanceof AuthorizationDenied => ['PERMISSION_DENIED', '没有管理系统歌单的权限。', 403],
            $throwable instanceof PlaylistCoverInvalid => ['PLAYLIST_COVER_INVALID', '请选择 5 MiB 以内的 JPEG、PNG 或 WebP 图片。', 422],
            $throwable instanceof PlaylistImportInvalid => ['PLAYLIST_IMPORT_INVALID', '请检查歌单文件、大小和请求标识。', 422],
            $throwable instanceof PlaylistLinkInvalid => ['PLAYLIST_IMPORT_INVALID', '仅支持指定平台的公开歌单链接。', 422],
            $throwable instanceof PlaylistLinkUnavailable => ['PLAYLIST_LINK_UNAVAILABLE', '歌单链接暂时无法解析。', 503],
            $throwable instanceof PlaylistNotFound => ['PLAYLIST_NOT_FOUND', '后台歌单不存在。', 404],
            $throwable instanceof PlaylistConflict || $throwable instanceof LastfmRecommendationConflict
                => ['PLAYLIST_CONFLICT', '数据已发生变化，请重新加载。', 409],
            $throwable instanceof PlaylistInvalid || $throwable instanceof LastfmRecommendationInvalid
                => ['VALIDATION_FAILED', '请检查系统歌单数据。', 422],
            $throwable instanceof LastfmRecommendationAuthenticationFailed
                => ['LASTFM_API_KEY_REJECTED', 'Last.fm API Key 无效或尚未生效。', 422],
            $throwable instanceof LastfmRecommendationUnavailable
                => ['LASTFM_RECOMMENDATION_UNAVAILABLE', 'Last.fm 推荐暂时不可用。', 503],
            $throwable instanceof PublicPlaylistRecommendationConflict
                => ['PLAYLIST_CONFLICT', '公开热门歌单已存在或数据已变化，请重新加载。', 409],
            $throwable instanceof PublicPlaylistRecommendationInvalid
                => ['VALIDATION_FAILED', '请检查公开热门歌单来源和选择。', 422],
            $throwable instanceof PublicPlaylistRecommendationUnavailable
                => ['PUBLIC_PLAYLIST_UNAVAILABLE', '公开热门歌单暂时不可用，请稍后重试。', 503],
            default => ['ADMIN_PLAYLIST_UNAVAILABLE', '后台歌单服务暂时不可用。', 503],
        };
        if ($error[2] >= 500) Log::error('Admin playlist request failed.', [
            'request_id' => $requestId, 'exception_class' => $throwable::class,
        ]);
        return JsonResponseFactory::error($error[0], $error[1], $error[2], $requestId);
    }
}
