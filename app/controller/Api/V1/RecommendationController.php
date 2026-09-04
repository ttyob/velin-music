<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaDetailNotFound;
use app\application\Recommendation\PluginRecommendationService;
use app\application\ResourcePlugin\Contract\RecommendationSongIdentity;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use InvalidArgumentException;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * RecommendationController 暴露插件可扩展的只读推荐与缺失歌曲详情 API。
 *
 * 每个入口都要求有效 Session、PAT 或 App Bearer 及实时 `play` capability；歌曲和艺人 ID 继续由领域
 * 服务按当前音乐库授权复验。插件失败不会把异常正文或第三方响应返回客户端，已有本地推荐仍以 200
 * 返回并标记 providerAvailable=false。缺失详情 POST 只是有界查询，不写服务器状态、不要求 CSRF，也
 * 不接受 URL、平台 ID、凭据或任意 options。
 */
final class RecommendationController
{
    /** 返回当前账号有界每日推荐。 */
    public function daily(Request $request): Response
    {
        return $this->handle($request, 'daily');
    }

    /** 返回授权歌曲的相似歌曲。 */
    public function similarSongs(Request $request, string $songId): Response
    {
        return $this->handle($request, 'similar_songs', $songId);
    }

    /** 返回授权艺人的相似艺人。 */
    public function similarArtists(Request $request, string $artistId): Response
    {
        return $this->handle($request, 'similar_artists', $artistId);
    }

    /** 返回插件推荐歌单，其中未入库条目只携带平台无关身份。 */
    public function playlists(Request $request): Response
    {
        return $this->handle($request, 'playlists');
    }

    /** 按平台无关身份查询尚未入库歌曲的安全描述详情。 */
    public function missingSongDetail(Request $request): Response
    {
        return $this->handle($request, 'missing_detail');
    }

    /**
     * 统一执行认证、输入映射、领域调用和脱敏错误响应。
     *
     * limit 只接受规范十进制整数 1-100，非法值不会被静默截断；POST 正文必须恰好是歌曲身份字段，避免
     * 后续插件新增参数时意外接收旧客户端任意第三方对象。日志不记录媒体 ID、歌曲身份或插件异常消息。
     */
    private function handle(Request $request, string $operation, ?string $id = null): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $service = new PluginRecommendationService();
            $limit = $this->limit($request->get('limit'), $operation === 'playlists' ? 10 : 20);
            $data = match ($operation) {
                'daily' => $service->daily($actor, $limit),
                'similar_songs' => $service->similarSongs($actor, (string) $id, $limit),
                'similar_artists' => $service->similarArtists($actor, (string) $id, $limit),
                'playlists' => $service->playlists($actor, $limit),
                'missing_detail' => $service->missingSongDetail($this->identity($request)),
                default => throw new InvalidArgumentException('RECOMMENDATION_OPERATION_INVALID'),
            };
            return JsonResponseFactory::create(['data' => $data, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaDetailNotFound) {
                return JsonResponseFactory::error('MEDIA_NOT_FOUND', '媒体不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof InvalidArgumentException) {
                return JsonResponseFactory::error('VALIDATION_FAILED', '推荐查询参数无效。', 422, $requestId);
            }
            Log::error('Recommendation request failed.', [
                'request_id' => $requestId,
                'operation' => $operation,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('RECOMMENDATION_UNAVAILABLE', '推荐服务暂时不可用。', 503, $requestId);
        }
    }

    /** 从请求正文构造受限歌曲身份；对象构造器继续执行字段级 UTF-8 与长度校验。 */
    private function identity(Request $request): RecommendationSongIdentity
    {
        $payload = $request->post();
        if (!is_array($payload)) throw new InvalidArgumentException('RECOMMENDATION_IDENTITY_INVALID');
        return RecommendationSongIdentity::fromArray($payload);
    }

    /** 只接受规范正整数，避免 PHP 宽松数值转换接受小数、指数或符号文本。 */
    private function limit(mixed $value, int $default): int
    {
        if ($value === null || $value === '') return $default;
        if (is_int($value)) $limit = $value;
        elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/D', $value) === 1) $limit = (int) $value;
        else throw new InvalidArgumentException('RECOMMENDATION_LIMIT_INVALID');
        if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('RECOMMENDATION_LIMIT_INVALID');
        return $limit;
    }
}
