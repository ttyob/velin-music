<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Scan\ScanJobConflict;
use app\application\Scan\ScanJobNotFound;
use app\application\Scan\ScanJobService;
use app\application\Scan\ScanValidator;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供管理员范围内的异步扫描创建、详情和取消接口。
 *
 * 扫描列表已经统一进入“任务与问题”，本 Controller 不再提供独立列表端点。这里仅做认证、能力校验、
 * 有界输入映射和 HTTP 错误转换；创建在持久事务提交后返回 202，不等待目录遍历。详情、文件结果和
 * 取消都会由应用服务重复校验任务所属音乐库的实时 manage 权限。
 */
final class ScanController
{
    /** 返回一个扫描详情；应用服务会重新验证当前音乐库管理范围，不存在与失权统一映射为 404。 */
    public function show(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $job = (new ScanJobService())->findJob($jobId, $actor);

            return JsonResponseFactory::create([
                'data' => ['job' => $job],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Scan detail request failed.');
        }
    }

    /** 返回一页安全扫描文件快照；只含业务字段和文件名，不返回目录或探测命令输出。 */
    public function files(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $result = (new ScanJobService())->listFileResults(
                $jobId,
                $actor,
                is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50,
                is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0,
            );

            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Scan file detail request failed.');
        }
    }

    /**
     * 创建持久扫描任务并返回 HTTP 202，请求进程不执行文件系统遍历。
     *
     * CSRF 在解析命令前完成；`scanType` 使用固定白名单，音乐库路径只从受保护配置读取，浏览器不能
     * 提交或替换。事务提交失败不会留下半个任务，成功后的文件处理由 Worker 异步执行。
     */
    public function create(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            $validation = (new ScanValidator())->validateCreate(is_array($payload) ? $payload : []);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请检查扫描参数。',
                    422,
                    $requestId,
                    ['fields' => $validation->errors],
                );
            }
            $job = (new ScanJobService())->createJob(
                $libraryId,
                $validation->input,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['job' => $job],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 202, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Scan enqueue request failed.');
        }
    }

    /** 记录立即取消或协作取消请求并返回权威快照；运行中任务只在 Worker 安全点进入终态。 */
    public function cancel(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $job = (new ScanJobService())->cancelJob($jobId, $actor, $requestId);

            return JsonResponseFactory::create([
                'data' => ['job' => $job],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Scan cancellation request failed.');
        }
    }

    /** 只映射稳定领域错误；未知异常仅记录类型和 request ID，不记录请求正文、路径或命令输出。 */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行此操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof ScanJobNotFound) {
            return JsonResponseFactory::error('SCAN_JOB_NOT_FOUND', '扫描任务或音乐库不存在。', 404, $requestId);
        }
        if ($throwable instanceof ScanJobConflict) {
            return JsonResponseFactory::error('SCAN_JOB_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error($throwable->reasonCode, '账号高成本任务已达到上限。', 429,
                $requestId, ['current' => $throwable->current, 'maximum' => $throwable->maximum]);
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error(
            'SCAN_MANAGEMENT_UNAVAILABLE',
            '扫描任务服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
