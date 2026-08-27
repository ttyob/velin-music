<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Library\LibraryConflict;
use app\application\Library\LibraryDirectoryBrowseFailed;
use app\application\Library\LibraryDirectoryBrowserService;
use app\application\Library\LibraryManagementService;
use app\application\Library\LibraryNotFound;
use app\application\Library\LibraryPathInvalid;
use app\application\Library\LibraryValidationFailed;
use app\application\Library\LibraryValidator;
use app\application\Library\OneDriveAuthorizationFailed;
use app\application\Library\GoogleDriveAuthorizationFailed;
use app\application\Library\RemoteLibraryUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes bounded music-library configuration and user scope management.
 *
 * All operations require global manage_library and then application services apply object-level
 * management grants. Physical paths are returned only through these protected administration
 * operations. No controller performs directory traversal or invokes scanner/media binaries.
 */
final class LibraryController
{
    /** Returns libraries within the actor's management scope. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $libraries = (new LibraryManagementService())->listLibraries($actor);

            return JsonResponseFactory::create([
                'data' => [
                    'libraries' => $libraries,
                    'total' => count($libraries),
                ],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library list request failed.');
        }
    }

    /**
     * 浏览当前管理员可管理音乐库中的一个根内目录。
     *
     * 请求使用受 CSRF 保护的 POST body 承载根内相对目录，避免目录名称进入 URL、访问日志或浏览器历史。
     * Controller 不接收物理路径、远端 URL、对象 ID 或任何文件命令；领域服务再次复验对象级 manage
     * 范围并只返回一层分页元数据。操作没有文件和数据库副作用，网络失败也不会回退为库存推断。
     */
    public function browseDirectory(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['path', 'limit', 'offset']) !== []) {
                throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_REQUEST_INVALID', '目录浏览请求格式无效。');
            }
            $path = $payload['path'] ?? '';
            $limit = $payload['limit'] ?? 100;
            $offset = $payload['offset'] ?? 0;
            if (!is_string($path) || !is_int($limit) || !is_int($offset)) {
                throw new LibraryDirectoryBrowseFailed('LIBRARY_DIRECTORY_REQUEST_INVALID', '目录浏览请求格式无效。');
            }
            $data = (new LibraryDirectoryBrowserService())->browse($libraryId, $path, $limit, $offset, $actor);
            return JsonResponseFactory::create(['data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library directory browse request failed.');
        }
    }

    /**
     * Registers an existing root after CSRF, logical input, filesystem, and overlap validation.
     *
     * Success means configuration is persisted with `never_scanned`; it does not claim media has
     * been indexed. A future command will enqueue the first scan outside this HTTP process.
     */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            $validation = (new LibraryValidator())->validateCreate(is_array($payload) ? $payload : []);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请检查表单中的错误。',
                    422,
                    $requestId,
                    ['fields' => $validation->errors],
                );
            }
            $library = (new LibraryManagementService())->createLibrary(
                $validation->input,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['library' => $library],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library creation request failed.');
        }
    }

    /**
     * Applies an optimistic library edit after capability and object-scope authorization.
     *
     * The service determines whether the target is the protected default library before selecting
     * its strict field allowlist. This controller does not normalize paths or silently discard fields.
     */
    public function update(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            $library = (new LibraryManagementService())->updateLibrary(
                $libraryId,
                is_array($payload) ? $payload : [],
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['library' => $library],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library update request failed.');
        }
    }

    /**
     * Deletes only an empty custom configuration using the version shown to the operator.
     *
     * The body accepts no path or cascade flag. Service-level dependency checks guarantee this
     * command cannot remove media files, active work, the default library, or referenced history.
     */
    public function delete(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['expectedVersion']) {
                throw new LibraryValidationFailed(['request' => ['删除请求格式无效。']]);
            }
            $value = $payload['expectedVersion'];
            $expectedVersion = is_int($value) ? $value
                : (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 ? (int) $value : 0);
            (new LibraryManagementService())->deleteLibrary(
                $libraryId,
                $expectedVersion,
                $actor,
                $requestId,
            );

            return new Response(204, [], '');
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library deletion request failed.');
        }
    }

    /** Returns minimal grant candidates after checking object-level management scope. */
    public function grants(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $data = (new LibraryManagementService())->grantOptions($libraryId, $actor);

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library grants request failed.');
        }
    }

    /**
     * Atomically replaces read grants while preserving manager and implicit super-admin access.
     *
     * CSRF middleware runs before body mapping. The full selected ID set is bounded and validated
     * by the service; clients cannot submit role names, usernames, or physical paths here.
     */
    public function replaceGrants(Request $request, string $libraryId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            $rawUserIds = is_array($payload) ? ($payload['userIds'] ?? null) : null;
            if (!is_array($rawUserIds)
                || array_filter($rawUserIds, static fn (mixed $id): bool => !is_string($id)) !== []) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请提交有效的用户授权列表。',
                    422,
                    $requestId,
                    ['fields' => ['userIds' => ['用户授权列表格式无效。']]],
                );
            }
            /** @var list<string> $userIds */
            $userIds = array_values($rawUserIds);
            $data = (new LibraryManagementService())->replaceReadGrants(
                $libraryId,
                $userIds,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'Library grant update failed.');
        }
    }

    /** Maps known safe failures and logs unexpected exceptions without request bodies or paths. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行此操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof LibraryNotFound) {
            return JsonResponseFactory::error('LIBRARY_NOT_FOUND', '音乐库不存在。', 404, $requestId);
        }
        if ($throwable instanceof LibraryDirectoryBrowseFailed) {
            return JsonResponseFactory::error($throwable->errorCode, $throwable->getMessage(),
                $throwable->httpStatus, $requestId);
        }
        if ($throwable instanceof LibraryPathInvalid) {
            return JsonResponseFactory::error(
                'LIBRARY_PATH_INVALID',
                $throwable->getMessage(),
                422,
                $requestId,
                ['fields' => ['rootPath' => [$throwable->getMessage()]]],
            );
        }
        if ($throwable instanceof LibraryValidationFailed) {
            return JsonResponseFactory::error(
                'VALIDATION_FAILED',
                $throwable->getMessage(),
                422,
                $requestId,
                ['fields' => $throwable->errors],
            );
        }
        if ($throwable instanceof LibraryConflict) {
            return JsonResponseFactory::error('LIBRARY_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof RemoteLibraryUnavailable) {
            return JsonResponseFactory::error(
                $throwable->errorCode,
                $throwable->getMessage(),
                422,
                $requestId,
            );
        }
        if ($throwable instanceof OneDriveAuthorizationFailed) {
            return JsonResponseFactory::error(
                $throwable->errorCode,
                $throwable->getMessage(),
                $throwable->httpStatus,
                $requestId,
            );
        }
        if ($throwable instanceof GoogleDriveAuthorizationFailed) {
            return JsonResponseFactory::error(
                $throwable->errorCode,
                $throwable->getMessage(),
                $throwable->httpStatus,
                $requestId,
            );
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error(
            'LIBRARY_MANAGEMENT_UNAVAILABLE',
            '音乐库管理服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
