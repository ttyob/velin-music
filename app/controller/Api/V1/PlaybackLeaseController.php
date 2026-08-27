<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamUnavailable;
use app\application\Playback\PlaybackLeaseInvalid;
use app\application\Playback\PlaybackLeaseNotFound;
use app\application\Playback\PlaybackLeaseService;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 提供当前账号播放租约的申请、心跳和释放命令。 */
final class PlaybackLeaseController
{
    /** 在音频真正启动前申请或复用当前播放器租约。 */
    public function acquire(Request $request): Response
    {
        return $this->run($request, function (array $actor) use ($request): array {
            $songId = $request->post('songId');
            $playerId = $request->post('playerId');
            if (!is_string($songId) || !is_string($playerId)) throw new PlaybackLeaseInvalid('播放租约请求无效。');
            return ['lease' => (new PlaybackLeaseService())->acquire($actor, $songId, $playerId)];
        }, 'Playback lease acquire failed.');
    }

    /** 为仍在播放的健康租约续期。 */
    public function heartbeat(Request $request, string $leaseId): Response
    {
        return $this->run($request, fn (array $actor): array => [
            'lease' => (new PlaybackLeaseService())->heartbeat($actor, $leaseId),
        ], 'Playback lease heartbeat failed.');
    }

    /** 暂停、结束或离开页面时幂等释放租约。 */
    public function release(Request $request, string $leaseId): Response
    {
        return $this->run($request, fn (array $actor): array => [
            'released' => (new PlaybackLeaseService())->release($actor, $leaseId),
        ], 'Playback lease release failed.');
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $operation */
    private function run(Request $request, callable $operation, string $logMessage): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            return JsonResponseFactory::create([
                'data' => $operation($actor),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            if ($throwable instanceof AuthorizationDenied) return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放权限。', 403, $requestId);
            if ($throwable instanceof PlaybackLeaseInvalid) return JsonResponseFactory::error('PLAYBACK_LEASE_INVALID', '播放租约参数无效。', 422, $requestId);
            if ($throwable instanceof PlaybackLeaseNotFound) return JsonResponseFactory::error('PLAYBACK_LEASE_EXPIRED', '播放租约已过期，请重新播放。', 409, $requestId);
            if ($throwable instanceof MediaStreamNotFound) return JsonResponseFactory::error('MEDIA_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
            if ($throwable instanceof MediaStreamUnavailable) return JsonResponseFactory::error('MEDIA_FILE_UNAVAILABLE', '音频文件暂时不可用。', 503, $requestId);
            if ($throwable instanceof UserRuntimeLimitExceeded) {
                return JsonResponseFactory::error($throwable->reasonCode, '账号并发播放已达到上限。', 429,
                    $requestId, ['current' => $throwable->current, 'maximum' => $throwable->maximum]);
            }
            Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('PLAYBACK_LEASE_UNAVAILABLE', '播放准入服务暂时不可用。', 503, $requestId);
        }
    }
}
