<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\System\BasicSystemSettingsConflict;
use app\application\System\BasicSystemSettingsInvalid;
use app\application\System\BasicSystemSettingsService;
use app\application\System\BasicSystemSettingsUnavailable;
use app\application\System\SystemLimitSettingsConflict;
use app\application\System\SystemLimitSettingsInvalid;
use app\application\System\SystemLimitSettingsService;
use app\application\System\SystemLimitSettingsUnavailable;
use app\application\System\NetworkProxySettingsConflict;
use app\application\System\NetworkProxySettingsInvalid;
use app\application\System\NetworkProxySettingsService;
use app\application\System\NetworkProxySettingsUnavailable;
use app\application\System\RuntimeMaintenanceInvalid;
use app\application\System\RuntimeMaintenanceService;
use app\application\System\RuntimeMaintenanceUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 将基础设置、全局限制和固定运行维护领域服务映射到管理员 HTTP API。
 *
 * 所有请求先实时要求 `manage_system`；PUT/POST 写请求还由路由级 CSRF 中间件保护。Controller 只负责认证、输入
 * 映射和稳定 HTTP 错误，不读取部署变量、密钥、服务器路径或历史值，也不在异常响应中返回底层消息。
 */
final class SystemSettingsController
{
    /**
     * 返回主程序固定运行维护分类的受管量和过期可清理量。
     *
     * 授权发生在文件系统扫描前；响应只包含数量、字节和固定保留策略，不返回服务器路径、文件名或未知
     * 目录项。该读取不删除文件、不写审计，也不会创建缺失的缓存目录。
     */
    public function showMaintenance(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new RuntimeMaintenanceService())->status());
    }

    /**
     * 清理管理员选择的固定分类并返回实际结果。
     *
     * POST 正文只能包含 categories 封闭列表，路径和保留期不可由客户端控制；CSRF 在路由层先验证。
     * actor ID 来自实时 Session，完成后由服务写一条脱敏审计。单文件失败作为部分结果返回，方便管理员
     * 重试；目录身份异常则失败关闭且不继续删除。
     */
    public function cleanupMaintenance(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['categories']
                || !is_array($payload['categories'])) {
                throw new RuntimeMaintenanceInvalid('运行维护参数无效。');
            }
            return (new RuntimeMaintenanceService())->cleanup(
                $payload['categories'],
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 读取固定基础设置快照。
     *
     * 返回值包含 expectedVersion 所需的当前版本；响应始终 no-store。迁移缺失或内容损坏返回 503，
     * 不用 Controller 常量伪造默认配置，也没有写入副作用。
     */
    public function show(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new BasicSystemSettingsService())->get());
    }

    /**
     * 严格接收完整配置与版本号并执行原子替换。
     *
     * actor ID 只能来自实时 Session/认证服务，不能由请求正文提供。409 不自动重放旧表单；成功响应
     * 返回递增后的完整快照。CSRF 在进入本方法前由显式路由中间件验证。
     */
    public function update(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new BasicSystemSettingsInvalid('基础设置参数无效。');
            }

            return (new BasicSystemSettingsService())->update($payload, (string) $actor['id'], RequestContext::requestId());
        });
    }

    /**
     * 读取全站统一限制快照。
     *
     * 授权发生在读取前，响应携带独立版本并禁用缓存；接口不接受用户 ID，也不返回用户覆盖或个人用量。
     */
    public function showLimits(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new SystemLimitSettingsService())->get());
    }

    /**
     * 严格接收完整全局限制对象并执行原子版本替换。
     *
     * actor ID 只来自实时认证主体，CSRF 由路由中间件先行验证。成功只改变后续播放、转码、下载或任务
     * 准入，不终止现有任务或媒体流；后台上传不读取此对象。版本冲突由浏览器重新加载后人工确认。
     */
    public function updateLimits(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new SystemLimitSettingsInvalid('系统限制参数无效。');
            }

            return (new SystemLimitSettingsService())->update(
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 读取数据源共用的系统代理脱敏快照。
     *
     * 授权发生在数据库读取前；响应只包含主机、端口、用户名和 passwordConfigured，不解密或返回密码。
     * 该操作不探测代理，也不影响当前正在运行的数据源请求。
     */
    public function showProxy(Request $request): Response
    {
        return $this->execute($request, static fn (): array => (new NetworkProxySettingsService())->snapshot());
    }

    /**
     * 使用独立版本锁原子保存系统代理。
     *
     * actor 只取自实时 Session，PUT 由路由 CSRF 中间件保护。密码省略或空字符串表示保留密文，响应
     * 永不回显；保存事务不访问代理或第三方数据源，成功只影响后续且已勾选代理的数据源请求。
     */
    public function updateProxy(Request $request): Response
    {
        return $this->execute($request, static function (array $actor) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload)) {
                throw new NetworkProxySettingsInvalid();
            }
            return (new NetworkProxySettingsService())->update(
                $payload,
                (string) $actor['id'],
                RequestContext::requestId(),
            );
        });
    }

    /**
     * 先实时授权再执行设置操作并生成统一响应。
     *
     * 授权失败时不会读取配置；成功和失败响应均通过统一工厂禁用缓存。未知异常仅按类名和 requestId
     * 写入日志，避免 SQL、路径或原始请求内容泄露到浏览器。
     */
    private function execute(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
            return JsonResponseFactory::create(['data' => $operation($actor), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied) {
                return JsonResponseFactory::error('PERMISSION_DENIED', '没有管理系统设置的权限。', 403, $requestId);
            }
            if ($throwable instanceof BasicSystemSettingsInvalid) {
                return JsonResponseFactory::error('SYSTEM_SETTINGS_VALIDATION_FAILED', '系统设置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof SystemLimitSettingsInvalid) {
                return JsonResponseFactory::error('SYSTEM_LIMITS_VALIDATION_FAILED', '系统限制参数无效。', 422, $requestId);
            }
            if ($throwable instanceof NetworkProxySettingsInvalid) {
                return JsonResponseFactory::error('NETWORK_PROXY_VALIDATION_FAILED', '代理配置参数无效。', 422, $requestId);
            }
            if ($throwable instanceof RuntimeMaintenanceInvalid) {
                return JsonResponseFactory::error('RUNTIME_MAINTENANCE_VALIDATION_FAILED', '清理分类无效。', 422, $requestId);
            }
            if ($throwable instanceof RuntimeMaintenanceUnavailable) {
                return JsonResponseFactory::error('RUNTIME_MAINTENANCE_UNAVAILABLE', '运行维护暂时不可用。', 503, $requestId);
            }
            if ($throwable instanceof BasicSystemSettingsConflict) {
                return JsonResponseFactory::error('SYSTEM_SETTINGS_VERSION_CONFLICT', '系统设置已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof SystemLimitSettingsConflict) {
                return JsonResponseFactory::error('SYSTEM_LIMITS_VERSION_CONFLICT', '系统限制已变化，请刷新后重试。', 409, $requestId);
            }
            if ($throwable instanceof NetworkProxySettingsConflict) {
                return JsonResponseFactory::error('NETWORK_PROXY_VERSION_CONFLICT', '代理配置已变化，请刷新后重试。', 409, $requestId);
            }
            Log::error('System settings request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            $code = $throwable instanceof BasicSystemSettingsUnavailable
                || $throwable instanceof SystemLimitSettingsUnavailable
                || $throwable instanceof NetworkProxySettingsUnavailable
                ? 'SYSTEM_SETTINGS_UNAVAILABLE'
                : 'SYSTEM_SETTINGS_REQUEST_FAILED';
            return JsonResponseFactory::error($code, '系统设置暂时不可用。', 503, $requestId);
        }
    }
}
