<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthorizationService;
use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Recommendation\LastfmRecommendationAuthenticationFailed;
use app\application\Recommendation\LastfmRecommendationConflict;
use app\application\Recommendation\LastfmRecommendationInvalid;
use app\application\Recommendation\LastfmRecommendationService;
use app\application\Recommendation\LastfmRecommendationUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Request;
use support\Response;
use Throwable;

/** 后台 Last.fm 推荐配置与手动刷新接口；所有业务写入委托给推荐领域服务。 */
final class RecommendationController
{
    /** 返回脱敏的 Last.fm 推荐配置状态。 */
    public function show(Request $request): Response
    {
        return $this->execute($request, static fn (array $actor): array => (new LastfmRecommendationService())->snapshot());
    }

    /** 保存启停和 API Key，API Key 不会出现在响应。 */
    public function update(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new LastfmRecommendationInvalid();
            return (new LastfmRecommendationService())->update($payload, (string) $actor['id'], RequestContext::requestId());
        });
    }

    /** 触发一次有界上游刷新并返回匹配数量摘要。 */
    public function refresh(Request $request): Response
    {
        return $this->execute($request, static fn (array $actor): array =>
            (new LastfmRecommendationService())->refresh($actor, RequestContext::requestId()));
    }

    private function execute(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $exception) {
            $code = match (true) {
                $exception instanceof AuthenticationRequired => ['AUTHENTICATION_REQUIRED', '请先登录。', 401],
                $exception instanceof AuthorizationDenied => ['PERMISSION_DENIED', '没有管理系统设置的权限。', 403],
                $exception instanceof LastfmRecommendationInvalid => ['LASTFM_RECOMMENDATION_INVALID', 'Last.fm 推荐配置无效。', 422],
                $exception instanceof LastfmRecommendationConflict => ['LASTFM_RECOMMENDATION_CONFLICT', '配置已变化，请刷新后重试。', 409],
                $exception instanceof LastfmRecommendationAuthenticationFailed => ['LASTFM_API_KEY_REJECTED', 'Last.fm API Key 无效或尚未生效。', 422],
                $exception instanceof LastfmRecommendationUnavailable => ['LASTFM_RECOMMENDATION_UNAVAILABLE', 'Last.fm 推荐暂时不可用。', 503],
                default => ['LASTFM_RECOMMENDATION_REQUEST_FAILED', 'Last.fm 推荐操作失败。', 503],
            };
            return JsonResponseFactory::error($code[0], $code[1], $code[2], $requestId);
        }
    }
}
