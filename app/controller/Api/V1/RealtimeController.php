<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationService;
use app\application\Realtime\RealtimeEventInvalid;
use app\http\JsonResponseFactory;
use app\http\RealtimeEventResponse;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Upgrades an authenticated same-origin GET into the private realtime invalidation stream.
 *
 * Web EventSource 可使用 Session Cookie；App 可通过支持自定义 Header 的 SSE 客户端提交短期 Bearer。
 * 客户端不能选择用户或音乐库范围。异步 runner 每秒按不可变用户 ID 重建账号与库权限，并在 55 秒
 * 主动断开，令牌撤销最迟在下一次重连时复验；本方法只验证初始凭据和有界十进制游标。
 */
final class RealtimeController
{
    /** Returns an internal stream marker or a normal no-store JSON authentication/validation error. */
    public function stream(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) {
                throw new AuthenticationRequired('Authentication is required.');
            }

            return new RealtimeEventResponse(
                $actor,
                $this->lastEventId($request->header('last-event-id')),
                $requestId,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof RealtimeEventInvalid) {
                return JsonResponseFactory::error('VALIDATION_FAILED', '实时事件游标无效。', 422, $requestId);
            }
            Log::error('Realtime stream setup failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error('REALTIME_UNAVAILABLE', '实时更新暂时不可用。', 503, $requestId);
        }
    }

    /** Parses only canonical non-negative decimal IDs, preventing overflow and ambiguous cursors. */
    private function lastEventId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,18})$/', $value) !== 1) {
            throw new RealtimeEventInvalid('Invalid Last-Event-ID.');
        }
        $cursor = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($cursor === false) {
            throw new RealtimeEventInvalid('Last-Event-ID is outside the integer range.');
        }

        return $cursor;
    }
}
