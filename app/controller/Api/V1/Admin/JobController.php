<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Job\JobCenterInvalid;
use app\application\Job\JobCenterConflict;
use app\application\Job\JobCenterService;
use app\application\Job\JobCenterNotFound;
use app\application\Job\JobReportTooLarge;
use app\application\Job\JobReportUnavailable;
use app\application\Job\ScanReportService;
use app\application\Scan\ScanJobNotFound;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 对外提供扫描、备份、刮削整理、待入库、上传和元数据任务的统一投影与受控命令。
 *
 * Controller 只完成 Session 认证、聚合入口能力校验和严格参数映射；每个来源适配器仍会重新校验自身
 * 能力与对象范围。统一取消入口只做类型/版本分派，实际转换继续由来源状态机负责并由 CSRF 保护；
 * 没有来源命令的任务不会开放伪造的取消、重试或回滚。
 * 响应禁止缓存，缺失与失权对象统一为 404，内部异常不会返回 SQL、路径或任务原始错误。
 */
final class JobController
{
    /**
     * 返回一个有界统一任务页，并声明当前身份真正可用的任务类型词表。
     *
     * 查询参数只允许固定类型、状态、ULID、UTC 日期和有界分页；非法值返回 422，不能静默转换成更宽
     * 查询。本方法不触发任务、文件或数据库写入。
     */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability(
                $request,
                ['manage_library', 'run_scrape', 'manage_storage', 'edit_metadata'],
            );
            $jobs = (new JobCenterService())->list(
                $actor,
                $this->text($request->get('type')),
                $this->text($request->get('status')),
                $this->text($request->get('libraryId')),
                $this->integer($request->get('limit'), 50, 1, 100),
                $this->integer($request->get('offset'), 0, 0, 10_000),
                $this->text($request->get('requestedBy')),
                $this->text($request->get('createdFrom')),
                $this->text($request->get('createdTo')),
            );

            return $this->response($jobs, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task list failed.');
        }
    }

    /**
     * 在来源适配器重复实时能力与对象范围校验后返回一个任务。
     *
     * ULID 不存在和已失权使用相同 404；读取只返回统一安全字段，不执行任何来源任务命令。
     */
    public function show(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability(
                $request,
                ['manage_library', 'run_scrape', 'manage_storage', 'edit_metadata'],
            );
            $job = (new JobCenterService())->find($actor, $jobId);

            return $this->response(['job' => $job], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task detail failed.');
        }
    }

    /**
     * 取消一个详情投影明确声明可取消的扫描或上传任务。
     *
     * `type` 必须与当前统一投影一致；上传还要求当前 expectedVersion。Controller 不直接写来源表或
     * 删除文件，具体的即时/协作取消与上传受控清理全部由领域服务完成。路由层另行强制 CSRF。
     */
    public function cancel(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability(
                $request,
                ['manage_library', 'manage_storage'],
            );
            $payload = $request->post();
            $type = is_array($payload) && is_string($payload['type'] ?? null) ? $payload['type'] : '';
            $version = is_array($payload) ? ($payload['expectedVersion'] ?? null) : null;
            if ($version !== null && !is_int($version)) throw new JobCenterInvalid('Invalid task version.');
            $job = (new JobCenterService())->cancel($actor, $jobId, $type, $version, $requestId);
            return $this->response(['job' => $job], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task cancellation failed.');
        }
    }

    /**
     * 下载一个终态扫描任务的完整识别报告。
     *
     * `type=scan` 是必填来源保护字段，避免不同事实表意外出现同一 ULID 时按探测顺序读取错误对象。
     * 服务在生成前重新校验 manage_library 和目标音乐库 manage 范围；响应不经过 JSON 信封，也不
     * 缓存到浏览器或代理。产物只位于 runtime 私有目录，客户端不能提交或推导物理路径。
     */
    public function report(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            if ($this->text($request->get('type')) !== 'scan') {
                throw new JobCenterInvalid('Unsupported task report type.');
            }
            $artifact = (new ScanReportService())->create($actor, $jobId);
            return response('', 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Checksum-SHA256' => $artifact['sha256'],
                'X-Report-Records' => (string) $artifact['recordCount'],
                'X-Request-ID' => $requestId,
            ])->download($artifact['path'], $artifact['downloadName']);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task report download failed.');
        }
    }

    /**
     * 为详情投影明确允许的失败或已取消扫描创建一个新任务。
     *
     * Controller 只接收固定 `type` 并重新认证；来源服务负责终态校验、对象权限、限额、活动任务冲突
     * 和原子审计。路由使用 CSRF，返回的是新任务而不是对原任务的乐观状态修改。
     */
    public function retry(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability($request, ['manage_library']);
            $payload = $request->post();
            $type = is_array($payload) && is_string($payload['type'] ?? null) ? $payload['type'] : '';
            $job = (new JobCenterService())->retry($actor, $jobId, $type, $requestId);
            return $this->response(['job' => $job], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task retry failed.');
        }
    }

    /**
     * 清理一个统一任务的非待确认终态事实。
     *
     * 请求正文必须包含与当前投影一致的固定 type，不能携带路径、删除条件或来源参数。Controller
     * 只负责认证和参数映射；来源状态、对象范围、暂存清理、外键顺序和审计由任务领域服务处理。
     */
    public function clear(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability(
                $request,
                ['manage_library', 'run_scrape', 'manage_storage', 'edit_metadata'],
            );
            $payload = $request->post();
            $type = is_array($payload) && is_string($payload['type'] ?? null) ? $payload['type'] : '';
            $job = (new JobCenterService())->clear($actor, $jobId, $type, $requestId);
            return $this->response(['job' => $job], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task cleanup failed.');
        }
    }

    /**
     * 清理当前筛选范围内的全部非待确认终态任务。
     *
     * 请求正文只允许包含统一列表的 type/status 筛选；权限范围、终态判断、并发冲突和审计均由任务
     * 领域服务负责。活动任务不会被取消，也不会因为批量清理而删除媒体或其他已应用业务事实。
     */
    public function clearAll(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireAnyCapability(
                $request,
                ['manage_library', 'run_scrape', 'manage_storage', 'edit_metadata'],
            );
            $payload = $request->post();
            $type = $this->optionalBodyText($payload, 'type');
            $status = $this->optionalBodyText($payload, 'status');
            $result = (new JobCenterService())->clearAll($actor, $type, $status, $requestId);
            return $this->response(['result' => $result], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Unified task bulk cleanup failed.');
        }
    }

    /** 只接收并裁剪标量字符串，数组或对象不会转换成 SQL 筛选值。 */
    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** 批量命令的可选筛选字段必须显式为字符串；错误类型不能静默退化成“全部任务”。 */
    private function optionalBodyText(mixed $payload, string $key): ?string
    {
        if (!is_array($payload) || !array_key_exists($key, $payload)) return null;
        if (!is_string($payload[$key])) throw new JobCenterInvalid('Invalid task filter.');
        $value = trim($payload[$key]);
        return $value === '' ? null : $value;
    }

    /** 解析严格有界整数；格式或范围错误会拒绝请求，不使用更宽的默认分页。 */
    private function integer(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new JobCenterInvalid('Invalid pagination.');
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new JobCenterInvalid('Pagination outside supported range.');
        }

        return $integer;
    }

    /** 使用共享 no-store 信封包装成功的私有任务投影。 */
    private function response(array $data, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /**
     * 把稳定领域错误映射为 HTTP 语义，未知异常只记录请求 ID 与异常类。
     *
     * 日志和响应均不包含任务 ID、音乐库名称、筛选条件、物理路径或来源错误正文，避免故障路径扩大
     * 管理数据暴露面。
     */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有查看任务中心的权限。', 403, $requestId);
        }
        if ($throwable instanceof JobCenterInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查任务筛选或标识。', 422, $requestId);
        }
        if ($throwable instanceof JobCenterNotFound || $throwable instanceof ScanJobNotFound) {
            return JsonResponseFactory::error('JOB_NOT_FOUND', '任务不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof JobCenterConflict) {
            return JsonResponseFactory::error('JOB_COMMAND_CONFLICT', '任务状态已经变化，请刷新后重试。', 409, $requestId);
        }
        if ($throwable instanceof JobReportUnavailable) {
            return JsonResponseFactory::error('JOB_REPORT_NOT_READY', '任务结束后才能下载完整报告。', 409, $requestId);
        }
        if ($throwable instanceof JobReportTooLarge) {
            return JsonResponseFactory::error('JOB_REPORT_TOO_LARGE', '完整报告超过当前下载安全上限。', 413, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                '账号高成本任务已达到上限。',
                429,
                $requestId,
                ['current' => $throwable->current, 'maximum' => $throwable->maximum],
            );
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('JOB_CENTER_UNAVAILABLE', '任务中心暂时不可用。', 503, $requestId);
    }
}
