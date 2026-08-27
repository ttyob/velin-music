<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\SessionService;
use app\application\Playback\PlayerProfileConflict;
use app\application\Playback\PlayerProfileInvalid;
use app\application\Playback\PlayerProfileService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 提供当前 Web Session 账号的脱敏播放器档案列表、版本化编辑和重置接口。 */
final class PlayerProfileController
{
    /** 列出最近 100 个自动登记设备，不返回内部 player_key 或 Subsonic 客户端摘要。 */
    public function index(Request $request): Response
    {
        return $this->run($request, static fn (array $actor): array => [
            'players' => (new PlayerProfileService())->list($actor),
        ]);
    }

    /** 保存完整名称/格式/码率覆盖，空格式和码率代表继承账号设置。 */
    public function update(Request $request, string $profileId): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request, $profileId): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PlayerProfileInvalid('播放器配置无效。');
            return ['player' => (new PlayerProfileService())->update($actor, $profileId, $payload, $requestId)];
        });
    }

    /** 重置一个当前账号设备；后续访问会以默认名称和继承设置重新登记。 */
    public function delete(Request $request, string $profileId): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($profileId): array {
            (new PlayerProfileService())->delete($actor, $profileId, $requestId);
            return ['deleted' => true];
        });
    }

    /** @param callable(array<string,mixed>,string):array<string,mixed> $operation */
    private function run(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            return JsonResponseFactory::create(['data' => $operation($actor, $requestId),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 200, $requestId);
        } catch (PlayerProfileConflict $conflict) {
            return JsonResponseFactory::error('PLAYER_PROFILE_CONFLICT', $conflict->getMessage(), 409, $requestId);
        } catch (PlayerProfileInvalid $invalid) {
            return JsonResponseFactory::error('PLAYER_PROFILE_INVALID', $invalid->getMessage(), 422, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Player profile request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('PLAYER_PROFILE_UNAVAILABLE', '播放器配置暂时不可用。', 503, $requestId);
        }
    }
}
