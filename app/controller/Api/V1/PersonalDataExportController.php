<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\SessionService;
use app\application\Export\PersonalDataExportConflict;
use app\application\Export\PersonalDataExportInvalid;
use app\application\Export\PersonalDataExportNotFound;
use app\application\Export\PersonalDataExportService;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供当前 Web Session 账号的个人数据导出排队、查询、取消与下载接口。
 *
 * 这些接口刻意不使用 AuthorizationService，因此个人 Bearer 令牌不能读取隐私导出状态或下载产物。
 * Controller 从 SessionService 重新读取活动账号；写路由另由 CSRF 中间件保护。下载只接收 Job ULID，
 * 物理路径、固定文件名、普通文件身份、大小和有效期全部由领域服务从服务端事实重新解析。
 */
final class PersonalDataExportController
{
    /** 返回当前账号最近 30 个导出任务，不暴露产物物理路径。 */
    public function index(Request $request): Response
    {
        return $this->run($request, static fn (array $actor, string $requestId): array =>
            (new PersonalDataExportService())->list((string) $actor['id']));
    }

    /** 在账号高成本任务额度内创建一个异步导出任务。 */
    public function create(Request $request): Response
    {
        return $this->run($request, static fn (array $actor, string $requestId): array => [
            'job' => (new PersonalDataExportService())->queue((string) $actor['id'], $requestId),
        ], 201);
    }

    /** queued 任务立即取消，running 任务登记请求并由 Worker 在安全文件边界收敛。 */
    public function cancel(Request $request, string $jobId): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($jobId, $request): array {
            $payload = $request->post();
            $version = is_array($payload) ? ($payload['expectedVersion'] ?? null) : null;
            if (!is_int($version)) throw new PersonalDataExportInvalid('导出任务版本无效。');
            return ['job' => (new PersonalDataExportService())->cancel(
                (string) $actor['id'], $jobId, $version, $requestId,
            )];
        });
    }

    /** 下载仍属于当前账号且未过期的固定 JSON 产物。 */
    public function download(Request $request, string $jobId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            $artifact = (new PersonalDataExportService())->resolveDownload((string) $actor['id'], $jobId);
            return response('', 200, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Checksum-SHA256' => $artifact['sha256'],
                'X-Request-ID' => $requestId,
            ])->download($artifact['path'], $artifact['downloadName']);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 统一执行 Session 重验和安全响应信封，不记录请求正文、用户数据、文件名或摘要。 */
    private function run(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            return JsonResponseFactory::create(['data' => $operation($actor, $requestId), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $status, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 仅映射稳定领域错误，不向客户端或日志泄露个人导出内容和物理路径。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof PersonalDataExportInvalid) {
            return JsonResponseFactory::error('PERSONAL_EXPORT_INVALID', $throwable->getMessage(), 422, $requestId);
        }
        if ($throwable instanceof PersonalDataExportNotFound) {
            return JsonResponseFactory::error('PERSONAL_EXPORT_NOT_FOUND', '个人数据导出不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof PersonalDataExportConflict) {
            return JsonResponseFactory::error('PERSONAL_EXPORT_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error($throwable->reasonCode, '账号高成本任务已达到上限。', 429,
                $requestId, ['current' => $throwable->current, 'maximum' => $throwable->maximum]);
        }
        Log::error('Personal data export API failed.', [
            'request_id' => $requestId, 'exception_class' => $throwable::class,
        ]);
        return JsonResponseFactory::error('PERSONAL_EXPORT_UNAVAILABLE', '个人数据导出暂时不可用。', 503, $requestId);
    }
}
