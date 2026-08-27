<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Storage\StorageWriteBlocked;
use app\application\Upload\UploadAdminService;
use app\application\Upload\UploadConflict;
use app\application\Upload\UploadInvalid;
use app\application\Upload\UploadNotFound;
use app\application\Upload\UploadSessionService;
use app\application\Upload\UploadStorageFailed;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供后台管理员使用的受控上传创建、分片、发布和会话 API。
 *
 * Controller 只接受具有 manage_storage 的 Cookie Session，并映射音乐库 ID、固定状态、ULID、乐观
 * 版本、原始分片和有界分页。跨账号读取由后台投影按目标库 manage 授权裁剪，创建与发布再由领域服务
 * 重验同一权限。接口不接受物理路径、不允许覆盖目标，所有响应使用 private/no-store；个人令牌不能
 * 绕过 CSRF，未知错误不回传 SQL、文件名或服务器路径。
 */
final class UploadController
{
    /** 返回当前上传者可写的活动本地音乐库、分片协议大小和文件类型，不创建目录或任务。 */
    public function options(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            return $this->response((new UploadSessionService())->options($actor), $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload options failed.');
        }
    }

    /** 管理员按筛选读取自己可管理音乐库中的上传会话。 */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            $status = $this->text($request->get('status'));
            if ($status !== null && !in_array($status, [
                'created', 'uploading', 'ready', 'publishing', 'completed', 'cancelled', 'expired', 'failed',
            ], true)) throw new UploadInvalid('上传状态筛选无效。');
            $libraryId = $this->optionalUlid($request->get('libraryId'));
            $userId = $this->optionalUlid($request->get('userId'));
            $limit = $this->integer($request->get('limit'), 50, 1, 100);
            $offset = $this->integer($request->get('offset'), 0, 0, 10_000);
            $result = (new UploadAdminService())->sessions(
                $actor,
                $status,
                $libraryId,
                $userId,
                $limit,
                $offset,
            );
            return $this->response($result, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload session list failed.');
        }
    }

    /** 在后台对象范围重新校验后返回会话。 */
    public function show(Request $request, string $sessionId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            $session = (new UploadAdminService())->detail($actor, $sessionId);
            return $this->response(['session' => $session], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload session detail failed.');
        }
    }

    /** 创建绑定单一音乐库根的不可变上传清单；目标物理路径始终由服务端解析。 */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            $payload = $this->payload($request);
            $libraryId = $payload['libraryId'] ?? null;
            $files = $payload['files'] ?? null;
            if (!is_string($libraryId) || !is_array($files) || !array_is_list($files)) {
                throw new UploadInvalid('后台上传会话请求无效。');
            }
            $session = (new UploadSessionService())->create(
                $actor,
                $libraryId,
                $files,
                (string) $request->header('idempotency-key', ''),
                $requestId,
            );
            return $this->response(['session' => $session], $requestId, 201);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload session create failed.');
        }
    }

    /** 接收一个最多 8 MiB 的连续原始分片；响应进度是后续偏移的唯一依据。 */
    public function chunk(Request $request, string $sessionId, string $fileId, string $byteOffset): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            if (preg_match('/^(0|[1-9][0-9]{0,12})$/', $byteOffset) !== 1) {
                throw new UploadInvalid('上传分片偏移无效。');
            }
            $bytes = $request->rawBody();
            if (strlen($bytes) < 1 || strlen($bytes) > \app\application\Upload\UploadStorageService::CHUNK_MAX_BYTES) {
                throw new UploadInvalid('上传分片大小无效。');
            }
            $session = (new UploadSessionService())->appendChunk(
                $actor,
                $sessionId,
                $fileId,
                (int) $byteOffset,
                $bytes,
                strtolower((string) $request->header('x-chunk-sha256', '')),
            );
            return $this->response(['session' => $session], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload chunk failed.');
        }
    }

    /** 把完整会话排入校验与非覆盖发布 Worker；HTTP 请求不做哈希或文件移动。 */
    public function publish(Request $request, string $sessionId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            $payload = $this->payload($request);
            $version = $payload['expectedVersion'] ?? null;
            if (!is_int($version) || $version < 1) throw new UploadInvalid('上传会话版本无效。');
            $session = (new UploadSessionService())->queuePublish($actor, $sessionId, $version, $requestId);
            return $this->response(['session' => $session], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload publish failed.');
        }
    }

    /** 以 expectedVersion 取消未被 Worker 领取的会话，路由层另外强制 CSRF。 */
    public function cancel(Request $request, string $sessionId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->authorizeUploader($request);
            $payload = $this->payload($request);
            $version = $payload['expectedVersion'] ?? null;
            if (!is_int($version) || $version < 1) throw new UploadInvalid('上传会话版本无效。');
            $session = (new UploadAdminService())->cancel($actor, $sessionId, $version, $requestId);
            return $this->response(['session' => $session], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Admin upload session cancel failed.');
        }
    }

    /**
     * 认证允许进入后台上传流程的 Cookie Session。
     *
     * 只接受 manage_storage；对象和音乐库 manage 授权由领域服务再次校验。前台不注册上传 API，
     * Bearer token 即使携带历史能力文本也不能借路由绕过后台 Session 与 CSRF 边界。
     *
     * @return array<string,mixed>
     */
    private function authorizeUploader(Request $request): array
    {
        return (new AuthorizationService())->requireCapability($request, 'manage_storage');
    }

    /** 只接受关联对象请求体，禁止数组列表或标量被隐式转换为命令。 */
    private function payload(Request $request): array
    {
        $payload = $request->post();
        if (!is_array($payload) || array_is_list($payload)) throw new UploadInvalid('上传请求正文无效。');
        return $payload;
    }

    /** 只接收非空标量文本，数组不会被 PHP 强制转换为筛选值。 */
    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** 可选对象筛选必须是 ULID，空值明确表示不筛选。 */
    private function optionalUlid(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $text) !== 1) {
            throw new UploadInvalid('上传管理筛选标识无效。');
        }
        return $text;
    }

    /** 严格解析有界整数；越界或非整数不使用默认值掩盖。 */
    private function integer(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') return $default;
        if (filter_var($value, FILTER_VALIDATE_INT) === false) throw new UploadInvalid('上传分页无效。');
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) throw new UploadInvalid('上传分页超出范围。');
        return $integer;
    }

    /** 使用共享响应信封返回管理数据，禁止中间代理缓存账号与文件进度。 */
    private function response(array $data, string $requestId, int $status = 200): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], $status, $requestId)->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * 将领域异常映射为稳定 HTTP 语义，日志只保留 request ID 与异常类。
     *
     * 文件名、哈希、用户 ID、路径、筛选值和原始异常消息均不记录或返回，避免后台故障
     * 途径成为另一个数据枚举面。
     */
    private function failure(Throwable $throwable, string $requestId, string $message): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有歌曲上传权限。', 403, $requestId);
        }
        if ($throwable instanceof UploadNotFound) {
            return JsonResponseFactory::error('UPLOAD_NOT_FOUND', '上传会话不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof UploadConflict) {
            return JsonResponseFactory::error('UPLOAD_CONFLICT', '上传状态已变化，请刷新后重试。', 409, $requestId);
        }
        if ($throwable instanceof UploadInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查上传管理参数。', 422, $requestId);
        }
        if ($throwable instanceof UploadStorageFailed) {
            return JsonResponseFactory::error('UPLOAD_STORAGE_FAILED', '上传暂存无法安全清理。', 507, $requestId);
        }
        if ($throwable instanceof StorageWriteBlocked) {
            return JsonResponseFactory::error($throwable->reasonCode, '存储空间或挂载状态不允许继续上传。', 507,
                $requestId);
        }
        Log::error($message, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error('UPLOAD_UNAVAILABLE', '歌曲上传暂时不可用。', 503, $requestId);
    }
}
