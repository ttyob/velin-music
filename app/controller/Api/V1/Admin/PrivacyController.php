<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\User\AdminPlaybackPrivacyService;
use app\application\User\UserNotFound;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 提供独立隐私能力保护的在线播放、指定账号历史和导出任务只读投影。 */
final class PrivacyController
{
    public function nowPlaying(Request $request): Response
    {
        return $this->run($request, static fn (array $actor): array =>
            (new AdminPlaybackPrivacyService())->nowPlaying($actor, self::integer($request->get('limit'), 50, 1, 100)));
    }

    public function history(Request $request, string $userId): Response
    {
        return $this->run($request, static fn (array $actor): array =>
            (new AdminPlaybackPrivacyService())->history(
                $actor, $userId, self::integer($request->get('limit'), 50, 1, 100),
                self::integer($request->get('offset'), 0, 0, 10_000),
            ));
    }

    public function exports(Request $request, string $userId): Response
    {
        return $this->run($request, static fn (array $actor): array =>
            (new AdminPlaybackPrivacyService())->exports($actor, $userId));
    }

    /** Controller 先要求隐私能力，领域服务对指定账号入口再强制与 manage_users 组合。 */
    private function run(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'view_play_privacy');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PLAY_PRIVACY_PERMISSION_DENIED', '没有查看播放隐私的权限。', 403, $requestId);
        } catch (UserNotFound) {
            return JsonResponseFactory::error('USER_NOT_FOUND', '用户不存在或无权查看。', 404, $requestId);
        } catch (\InvalidArgumentException) {
            return JsonResponseFactory::error('PLAY_PRIVACY_QUERY_INVALID', '播放隐私查询参数无效。', 422, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Administration playback privacy read failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('PLAY_PRIVACY_UNAVAILABLE', '播放隐私数据暂时不可用。', 503, $requestId);
        }
    }

    /** 严格解析有界分页值，非法输入不会扩大查询。 */
    private static function integer(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') return $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false) throw new \InvalidArgumentException('PRIVACY_PAGINATION_INVALID');
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) throw new \InvalidArgumentException('PRIVACY_PAGINATION_INVALID');
        return $integer;
    }
}
