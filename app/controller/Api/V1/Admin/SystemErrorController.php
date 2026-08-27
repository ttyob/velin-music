<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\SystemError\SystemErrorConflict;
use app\application\SystemError\SystemErrorInvalid;
use app\application\SystemError\SystemErrorNotFound;
use app\application\SystemError\SystemErrorService;
use app\application\SystemError\SystemErrorValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供仅 `manage_system` 管理员可访问的全局异常记录。
 *
 * 普通管理员、个人令牌和客户端都不能读取部署级异常。列表不返回调用栈，详情只返回服务端捕获时已
 * 脱敏的相对应用帧；状态写入要求 Session CSRF、版本锁和审计。控制器不接受删除、原始日志下载、
 * 请求正文回放或任意备注，避免把诊断页变成秘密外泄和不可审计的日志管理入口。
 */
final class SystemErrorController
{
    /** 返回有界异常页和待处理摘要；搜索、来源与状态均先经过固定校验。 */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $this->actor($request);
            $validator = new SystemErrorValidator();
            $page = (new SystemErrorService())->list(
                $validator->status($request->get('status')),
                $validator->severity($request->get('severity')),
                $validator->source($request->get('source')),
                $validator->search($request->get('q')),
                $validator->page($request->get('limit'), 50, 1, 100),
                $validator->page($request->get('offset'), 0, 0, 10_000),
            );
            return $this->response($page, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'System error list failed.');
        }
    }

    /** 返回一条异常的脱敏详情和相对应用调用栈，不读取文件或原始日志。 */
    public function show(Request $request, string $errorId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $this->actor($request);
            $id = (new SystemErrorValidator())->id($errorId);
            return $this->response(['error' => (new SystemErrorService())->find($id)], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'System error detail failed.');
        }
    }

    /** 以版本锁解决或重开一条异常；复发导致版本变化时返回 409，要求管理员重载。 */
    public function update(Request $request, string $errorId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $validator = new SystemErrorValidator();
            $error = (new SystemErrorService())->changeStatus(
                $this->actor($request),
                $validator->id($errorId),
                $validator->commandStatus($request->post('status')),
                $validator->version($request->post('expectedVersion')),
                $requestId,
            );
            return $this->response(['error' => $error], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'System error status update failed.');
        }
    }

    /**
     * 只接受持有 manage_system 的同源后台 Session。
     *
     * 个人令牌和 App access token 即使被授予同名能力也不能读取部署诊断；异常类、路由和 requestId
     * 超出自动化令牌的必要范围。可信代理登录最终建立普通 Session，因此仍可按其实际角色访问。
     */
    private function actor(Request $request): array
    {
        $actor = (new AuthorizationService())->requireCapability($request, 'manage_system');
        if (isset($actor['authenticationType'])) {
            throw new AuthorizationDenied('System error records require an administration session.');
        }
        return $actor;
    }

    /** 使用禁止缓存的统一信封返回管理员私有诊断。 */
    private function response(array $data, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /** 映射稳定错误；未知异常只记录类别和 requestId，不把诊断服务自身错误正文返回浏览器。 */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有查看系统异常的权限。', 403, $requestId);
        }
        if ($throwable instanceof SystemErrorInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查异常筛选或状态参数。', 422, $requestId);
        }
        if ($throwable instanceof SystemErrorNotFound) {
            return JsonResponseFactory::error('SYSTEM_ERROR_NOT_FOUND', '异常记录不存在。', 404, $requestId);
        }
        if ($throwable instanceof SystemErrorConflict) {
            return JsonResponseFactory::error('SYSTEM_ERROR_VERSION_CONFLICT', '异常记录已发生变化，请刷新后重试。', 409, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error('SYSTEM_ERROR_SERVICE_UNAVAILABLE', '异常记录服务暂时不可用。', 503, $requestId);
    }
}
