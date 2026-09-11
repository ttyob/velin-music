<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\SessionService;
use app\application\Auth\SetupAlreadyCompleted;
use app\application\Auth\SetupLibraryValidator;
use app\application\Auth\SetupService;
use app\application\Auth\SetupValidator;
use app\application\Library\DefaultLibraryService;
use app\application\Library\LibraryDirectoryBrowseFailed;
use app\application\Library\SetupLibraryDirectoryBrowserService;
use app\http\CsrfTokenManager;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes the one-time installation workflow for an otherwise empty Velin Music database.
 *
 * Read and write operations both return 404 after initialization to avoid advertising a dormant
 * privileged endpoint. Input validation occurs before the setup service takes SQLite's write lock;
 * the service repeats the completion check under BEGIN IMMEDIATE and owns all business writes.
 */
final class SetupController
{
    /**
     * Returns environment readiness and a Session-bound CSRF token only before initialization.
     *
     * @return Response HTTP 200 for a fresh system; HTTP 404 after any user exists.
     */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $setup = new SetupService();
        if ($setup->isCompleted()) {
            return JsonResponseFactory::error(
                'NOT_FOUND',
                '请求的资源不存在。',
                404,
                $requestId,
            );
        }

        $csrfToken = (new CsrfTokenManager())->getOrCreate($request->session());

        return JsonResponseFactory::create([
            'data' => [
                'required' => true,
                'csrfToken' => $csrfToken,
                'environment' => [
                    'php' => [
                        'ready' => version_compare(PHP_VERSION, '8.1.0', '>='),
                        'version' => PHP_VERSION,
                    ],
                    'database' => ['ready' => true, 'driver' => 'sqlite'],
                    'passwordHashing' => [
                        'ready' => defined('PASSWORD_ARGON2ID'),
                        'algorithm' => 'argon2id',
                    ],
                ],
                'defaults' => [
                    'siteName' => 'Velin Music',
                    'locale' => 'zh-CN',
                    'timezone' => 'Asia/Shanghai',
                ],
            ],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /**
     * Creates the first super administrator and immediately establishes a revocable Web session.
     *
     * CSRF is enforced by route middleware before this method. Validation errors use HTTP 422;
     * a concurrent winner closes setup and yields 404. Unexpected failures are logged with only
     * server-owned context and returned as a stable error without database details.
     */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $payload = $request->post();
        $validation = (new SetupValidator())->validate(is_array($payload) ? $payload : []);
        if (!$validation->isValid() || $validation->input === null) {
            return JsonResponseFactory::error(
                'VALIDATION_FAILED',
                '请检查表单中的错误。',
                422,
                $requestId,
                ['fields' => $validation->errors],
            );
        }

        try {
            $userId = (new SetupService())->initialize($validation->input, $requestId);
            $csrfToken = (new SessionService())->establish($request, $userId, $requestId);
            $user = (new SessionService())->currentUser($request);

            return JsonResponseFactory::create([
                'data' => [
                    'initialized' => true,
                    'csrfToken' => $csrfToken,
                    'user' => $user,
                ],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (SetupAlreadyCompleted) {
            return JsonResponseFactory::error(
                'NOT_FOUND',
                '请求的资源不存在。',
                404,
                $requestId,
            );
        } catch (Throwable $throwable) {
            Log::error('Setup request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'SETUP_FAILED',
                '初始化未完成，请稍后重试。',
                500,
                $requestId,
            );
        }
    }

    /**
     * 返回登录后首次配置默认音乐库所需的选择器边界、策略白名单和 CSRF 令牌。
     *
     * 该入口只对尚未建立默认库的超级管理员开放。`browseRootPath` 是容器内固定逻辑挂载，不是宿主物理
     * 路径；前端只能结合目录浏览接口返回的相对路径展示选择结果。默认库完成后返回 404，避免保留一个
     * 可探测的特权初始化入口。策略默认值与提交白名单一起返回，前端不能自行扩展枚举。本方法只读取
     * 设置状态和 Session，不访问媒体目录。
     */
    public function libraryShow(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $actor = (new \app\application\Auth\SessionService())->currentUser($request);
        if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        if (($actor['isSuperAdmin'] ?? false) !== true) return JsonResponseFactory::error('PERMISSION_DENIED', '只有管理员可以完成首次设置。', 403, $requestId);
        if (!(new DefaultLibraryService())->isConfigured()) {
            return JsonResponseFactory::create([
                'data' => [
                    'required' => true,
                    'csrfToken' => (new CsrfTokenManager())->getOrCreate($request->session()),
                    'browseRootPath' => '/storage',
                    'defaultRelativePath' => 'music',
                    'defaultScrapeStorageMode' => 'managed_cache',
                    'scrapeStorageModes' => ['managed_cache', 'adjacent'],
                    'defaultScanMode' => 'manual',
                    'scanModes' => ['manual', 'scheduled', 'watch'],
                ],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        }
        return JsonResponseFactory::error('NOT_FOUND', '请求的资源不存在。', 404, $requestId);
    }

    /**
     * 浏览首次初始化可选择的媒体目录。
     *
     * 请求体只接受 `/storage` 根内相对位置与分页参数，并由 CSRF 中间件保护。Controller 先复验登录、
     * 超级管理员和“尚未配置”状态，再把只读枚举交给固定根服务；已完成初始化返回 404。已知路径失败
     * 返回不含物理路径的稳定错误，未知异常仅记录请求 ID 和异常类型，本方法没有文件或数据库写副作用。
     */
    public function browseLibraryDirectory(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $actor = (new SessionService())->currentUser($request);
        if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        if (($actor['isSuperAdmin'] ?? false) !== true) return JsonResponseFactory::error('PERMISSION_DENIED', '只有管理员可以完成首次设置。', 403, $requestId);
        if ((new DefaultLibraryService())->isConfigured()) return JsonResponseFactory::error('NOT_FOUND', '请求的资源不存在。', 404, $requestId);

        try {
            $payload = $request->post();
            if (!is_array($payload) || array_diff(array_keys($payload), ['path', 'limit', 'offset']) !== []) {
                throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_REQUEST_INVALID', '目录浏览请求格式无效。');
            }
            $path = $payload['path'] ?? '';
            $limit = $payload['limit'] ?? 100;
            $offset = $payload['offset'] ?? 0;
            if (!is_string($path) || !is_int($limit) || !is_int($offset)) {
                throw new LibraryDirectoryBrowseFailed('SETUP_DIRECTORY_REQUEST_INVALID', '目录浏览请求格式无效。');
            }
            $data = (new SetupLibraryDirectoryBrowserService())->browse($path, $limit, $offset);
            return JsonResponseFactory::create(['data' => $data,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 200, $requestId);
        } catch (LibraryDirectoryBrowseFailed $failure) {
            return JsonResponseFactory::error($failure->errorCode, $failure->getMessage(),
                $failure->httpStatus, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Setup library directory browse failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('SETUP_DIRECTORY_UNAVAILABLE', '媒体目录当前不可浏览。', 503, $requestId);
        }
    }

    /**
     * 保存目录选择器确认的默认音乐库、派生资源策略和扫描模式，并开放普通业务 API。
     *
     * 当前主体必须仍是超级管理员且默认库尚未建立；请求路径即使来自受控选择器，也必须再次经过格式、
     * 真实路径、策略对应的读写权限、缓存隔离和事务内并发复验。成功会原子写入库、管理员授权、默认
     * 指针和审计；失败回滚所有数据库写入且不创建、移动或修改媒体文件，也不会在请求内启动扫描。
     */
    public function configureLibrary(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $actor = (new \app\application\Auth\SessionService())->currentUser($request);
        if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        if (($actor['isSuperAdmin'] ?? false) !== true) return JsonResponseFactory::error('PERMISSION_DENIED', '只有管理员可以完成首次设置。', 403, $requestId);
        $payload = $request->post();
        $validation = (new SetupLibraryValidator())->validate(is_array($payload) ? $payload : []);
        if (!$validation->isValid() || $validation->input === null) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查音乐库设置。', 422, $requestId, ['fields' => $validation->errors]);
        }
        try {
            (new SetupService())->configureDefaultLibrary($validation->input, $actor, $requestId);
            $user = (new \app\application\Auth\SessionService())->currentUser($request);
            return JsonResponseFactory::create(['data' => ['configured' => true, 'user' => $user], 'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 201, $requestId);
        } catch (SetupAlreadyCompleted) {
            return JsonResponseFactory::error('NOT_FOUND', '请求的资源不存在。', 404, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Default library setup failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('SETUP_LIBRARY_FAILED', '默认音乐库未配置，请检查目录后重试。', 422, $requestId);
        }
    }
}
