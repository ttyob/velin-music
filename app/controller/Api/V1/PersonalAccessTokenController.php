<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\PersonalAccessTokenInvalid;
use app\application\Auth\PersonalAccessTokenService;
use app\application\Auth\SessionService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供当前 Web Session 账号的个人令牌列表、创建和撤销接口。
 *
 * 令牌管理刻意只接受可撤销 Web Session，不接受另一个 Bearer 令牌代为签发或撤销。写路由同时要求
 * CSRF；创建响应中的 plainTextToken 只出现一次，后续列表永不返回摘要或明文。
 */
final class PersonalAccessTokenController
{
    /** 返回当前账号最近 100 个令牌的脱敏状态与可委托范围。 */
    public function index(Request $request): Response
    {
        return $this->handle($request, static fn (array $actor, string $requestId): array => [
            'tokens' => (new PersonalAccessTokenService())->list($actor),
        ]);
    }

    /** 校验名称、范围和到期时间后创建高熵令牌，明文仅随本响应返回。 */
    public function create(Request $request): Response
    {
        return $this->handle($request, static function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new PersonalAccessTokenInvalid('个人令牌请求无效。');
            return ['created' => (new PersonalAccessTokenService())->create($actor, $payload, $requestId)];
        }, 201);
    }

    /** 仅撤销当前账号拥有的令牌；重复撤销幂等返回同一脱敏对象。 */
    public function revoke(Request $request, string $tokenId): Response
    {
        return $this->handle($request, static fn (array $actor, string $requestId): array => [
            'token' => (new PersonalAccessTokenService())->revoke($actor, $tokenId, $requestId),
        ]);
    }

    /**
     * 统一执行 Session 身份重验和稳定错误映射，不把请求正文、令牌或异常消息写入日志。
     *
     * @param callable(array<string,mixed>,string):array<string,mixed> $operation
     */
    private function handle(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            return JsonResponseFactory::create([
                'data' => $operation($actor, $requestId),
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], $status, $requestId);
        } catch (PersonalAccessTokenInvalid $invalid) {
            return JsonResponseFactory::error('PERSONAL_TOKEN_INVALID', $invalid->getMessage(), 422, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Personal access token request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error(
                'PERSONAL_TOKEN_UNAVAILABLE', '个人令牌服务暂时不可用。', 503, $requestId,
            );
        }
    }
}
