<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\System\NetworkProxyProfileConflict;
use app\application\System\NetworkProxyProfileInUse;
use app\application\System\NetworkProxyProfileInvalid;
use app\application\System\NetworkProxyProfileNotFound;
use app\application\System\NetworkProxyProfileService;
use app\application\System\NetworkProxyProfileUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露命名代理的管理员 CRUD 边界。
 *
 * 全部端点要求 `manage_system`，写路由另由 CSRF 中间件保护。Controller 不返回密码或密文，也不发起
 * 代理探测；字段校验、版本锁、引用保护和审计均委托领域服务。Bearer 即使携带同名能力仍受既有后台
 * Session 授权规则约束。
 */
final class NetworkProxyController
{
    /** 返回脱敏代理列表；失败不提供旧全局代理作为隐藏回退。 */
    public function index(Request $request): Response
    {
        return $this->execute($request, static fn (): array => [
            'proxies' => (new NetworkProxyProfileService())->catalog(),
        ]);
    }

    /** 创建 profile，密码是只写字段，成功使用 201。 */
    public function create(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new NetworkProxyProfileInvalid();
            return ['proxy' => (new NetworkProxyProfileService())->create(
                $payload, (string) $actor['id'], RequestContext::requestId(),
            )];
        }, 201);
    }

    /** 通过 expectedVersion 更新 profile；409 后客户端必须重载，不能自动覆盖。 */
    public function update(Request $request, string $proxyId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $proxyId): array {
            $payload = $request->post();
            if (!is_array($payload)) throw new NetworkProxyProfileInvalid();
            return ['proxy' => (new NetworkProxyProfileService())->update(
                $proxyId, $payload, (string) $actor['id'], RequestContext::requestId(),
            )];
        });
    }

    /** 只删除无引用且版本匹配的 profile，不改写任何渠道或音乐库。 */
    public function delete(Request $request, string $proxyId): Response
    {
        return $this->execute($request, static function (array $actor) use ($request, $proxyId): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['expectedVersion']
                || !is_int($payload['expectedVersion']) || $payload['expectedVersion'] < 1) {
                throw new NetworkProxyProfileInvalid();
            }
            (new NetworkProxyProfileService())->delete(
                $proxyId, $payload['expectedVersion'], (string) $actor['id'], RequestContext::requestId(),
            );
            return [];
        });
    }

    /**
     * 统一认证、响应信封与错误映射；日志只含 requestId 和异常类，不记录表单或代理地址。
     *
     * @param callable(array<string,mixed>):array<string,mixed> $operation
     */
    private function execute(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $status, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            if ($throwable instanceof AuthorizationDenied) return JsonResponseFactory::error('PERMISSION_DENIED', '没有管理网络代理的权限。', 403, $requestId);
            if ($throwable instanceof NetworkProxyProfileInvalid) return JsonResponseFactory::error('NETWORK_PROXY_VALIDATION_FAILED', '代理配置参数无效。', 422, $requestId);
            if ($throwable instanceof NetworkProxyProfileNotFound) return JsonResponseFactory::error('NETWORK_PROXY_NOT_FOUND', '代理不存在。', 404, $requestId);
            if ($throwable instanceof NetworkProxyProfileConflict) return JsonResponseFactory::error('NETWORK_PROXY_VERSION_CONFLICT', $throwable->getMessage(), 409, $requestId);
            if ($throwable instanceof NetworkProxyProfileInUse) return JsonResponseFactory::error('NETWORK_PROXY_IN_USE', '代理仍被数据源或音乐库使用。', 409, $requestId);
            Log::error('Network proxy profile request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            $code = $throwable instanceof NetworkProxyProfileUnavailable ? 'NETWORK_PROXY_CATALOG_UNAVAILABLE' : 'NETWORK_PROXY_MANAGEMENT_UNAVAILABLE';
            return JsonResponseFactory::error($code, '网络代理管理暂时不可用。', 503, $requestId);
        }
    }
}
