<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\SessionService;
use app\application\Scrobble\ScrobbleConflict;
use app\application\Scrobble\ScrobbleConnectionService;
use app\application\Scrobble\ScrobbleInvalid;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供当前 Web Session 账号的外部 Scrobble 连接管理 API。
 *
 * 连接管理拒绝 Bearer 令牌代办，写路由同时要求 CSRF。列表和写响应始终使用脱敏投影，Controller 不
 * 读取、记录或返回数据库密文；credentials 只交给领域服务立即验证加密。Provider 从受约束路径参数
 * 获取，用户 ID 只取 Session，因而管理员也不能借本接口读取其他账号连接。
 */
final class ScrobbleConnectionController
{
    /** 返回三个支持平台的配置、队列数和最近稳定失败码。 */
    public function index(Request $request): Response
    {
        return $this->handle($request, static fn (array $actor): array =>
            (new ScrobbleConnectionService())->snapshot($actor));
    }

    /** 创建、更新或重新授权一个平台；expectedVersion=0 仅用于首次创建。 */
    public function save(Request $request, string $provider): Response
    {
        return $this->handle($request, static function (array $actor, string $requestId) use ($request, $provider): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new ScrobbleInvalid('连接请求无效。');
            return ['connection' => (new ScrobbleConnectionService())->save($actor, $provider, $payload, $requestId)];
        });
    }

    /** 删除当前账号的指定连接及尚存投递任务，不改变内部播放历史。 */
    public function delete(Request $request, string $provider): Response
    {
        return $this->handle($request, static function (array $actor, string $requestId) use ($provider): array {
            (new ScrobbleConnectionService())->delete($actor, $provider, $requestId);
            return ['deleted' => true];
        });
    }

    /**
     * 统一完成 Session 重验与稳定 HTTP 错误映射；日志只包含请求 ID 和异常类。
     *
     * @param callable(array<string,mixed>,string):array<string,mixed> $operation
     */
    private function handle(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            return JsonResponseFactory::create([
                'data' => $operation($actor, $requestId),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (ScrobbleConflict $conflict) {
            return JsonResponseFactory::error('SCROBBLE_CONNECTION_CONFLICT', $conflict->getMessage(), 409, $requestId);
        } catch (ScrobbleInvalid $invalid) {
            return JsonResponseFactory::error('SCROBBLE_CONNECTION_INVALID', $invalid->getMessage(), 422, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Scrobble connection request failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error(
                'SCROBBLE_CONNECTION_UNAVAILABLE', '外部播放记录连接暂时不可用。', 503, $requestId,
            );
        }
    }
}
