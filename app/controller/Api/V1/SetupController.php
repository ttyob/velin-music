<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\SessionService;
use app\application\Auth\SetupAlreadyCompleted;
use app\application\Auth\SetupService;
use app\application\Auth\SetupValidator;
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
}
