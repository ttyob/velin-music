<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\User\UserConflict;
use app\application\User\UserBulkInvalid;
use app\application\User\UserBulkService;
use app\application\User\UserManagementService;
use app\application\User\UserNotFound;
use app\application\User\UserValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Exposes global account management to principals with manage_users.
 *
 * Every operation re-resolves the revocable session and effective role capabilities before data
 * access. Controllers map HTTP input only; password hashing, super-admin protection, transactions,
 * session revocation, permission versions, and audit records remain in application services.
 */
final class UserController
{
    /** 返回一个账号的脱敏详情；播放历史和导出记录不在本接口中。 */
    public function show(Request $request, string $userId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            return JsonResponseFactory::create([
                'data' => ['user' => (new UserManagementService())->detailUser($userId, $actor)],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User detail request failed.');
        }
    }

    /** Returns a bounded filtered account list without authentication secrets or private activity. */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            (new AuthorizationService())->requireCapability($request, 'manage_users');
            $result = (new UserManagementService())->listUsers([
                'status' => $request->get('status'),
                'q' => is_string($request->get('q')) ? $request->get('q') : '',
                'role' => $request->get('role'),
                'capability' => $request->get('capability'),
                'libraryId' => $request->get('libraryId'),
                'lastLoginFrom' => $request->get('lastLoginFrom'),
                'lastLoginTo' => $request->get('lastLoginTo'),
            ], is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50);

            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User list request failed.');
        }
    }

    /**
     * Creates one local non-super account and assigns a built-in role plus optional direct capabilities.
     *
     * CSRF middleware runs before this method. Unknown input fields are ignored and plaintext
     * credentials never enter logs or error metadata.
     */
    public function create(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            $validation = (new UserValidator())->validateCreate(is_array($payload) ? $payload : []);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED',
                    '请检查表单中的错误。',
                    422,
                    $requestId,
                    ['fields' => $validation->errors],
                );
            }

            $user = (new UserManagementService())->createUser(
                $validation->input,
                (string) $actor['id'],
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['user' => $user],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User creation request failed.');
        }
    }

    /**
     * 保存用户编辑弹窗允许修改的资料、基础角色和用户直授能力。
     *
     * 请求字段必须精确匹配固定契约并已通过 CSRF。Controller 只完成结构校验；领域服务继续复验目标
     * 身份、超级管理员边界、当前操作者自改角色限制以及资料/权限双版本锁。用户名、密码、状态、
     * 音乐库和限额不在本端点中，失败不会产生部分资料、角色或直授能力更新。
     */
    public function update(Request $request, string $userId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            $expected = [
                'accountExpiresOn', 'directCapabilities', 'displayName', 'email',
                'expectedPermissionVersion', 'expectedProfileVersion', 'locale', 'roleKey', 'timezone',
            ];
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys);
            if (!is_array($payload) || array_is_list($payload) || $keys !== $expected) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED', '请检查表单中的错误。', 422, $requestId,
                    ['fields' => ['form' => ['用户编辑请求结构无效。']]],
                );
            }
            $validation = (new UserValidator())->validateUpdate($payload);
            if (!$validation->isValid() || $validation->input === null) {
                return JsonResponseFactory::error(
                    'VALIDATION_FAILED', '请检查表单中的错误。', 422, $requestId,
                    ['fields' => $validation->errors],
                );
            }
            $user = (new UserManagementService())->updateUser(
                $userId, $validation->input, $actor, $requestId,
            );
            return JsonResponseFactory::create([
                'data' => ['user' => $user],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User update request failed.');
        }
    }

    /**
     * Applies an active/disabled transition to one stable user ID.
     *
     * CSRF middleware protects the command. Status changes are not optimistic: the response is
     * returned only after permission-version update, session revocation, and audit commit.
     */
    public function changeStatus(Request $request, string $userId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            $status = is_array($payload) && is_string($payload['status'] ?? null)
                ? $payload['status']
                : '';
            $user = (new UserManagementService())->changeStatus(
                $userId,
                $status,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['user' => $user],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User status request failed.');
        }
    }

    /**
     * 替换普通账号的单一内置角色。
     *
     * 请求必须精确包含 roleKey 与 expectedPermissionVersion，并已通过 CSRF；Controller 不接受能力数组、
     * 超级管理员标记或角色数据库 ID。领域服务负责目标保护、乐观锁、事务、审计与实时撤权语义。
     */
    public function changeRole(Request $request, string $userId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            $keys = is_array($payload) ? array_keys($payload) : [];
            sort($keys);
            if (!is_array($payload) || array_is_list($payload)
                || $keys !== ['expectedPermissionVersion', 'roleKey']
                || !is_string($payload['roleKey']) || !is_int($payload['expectedPermissionVersion'])) {
                throw new UserConflict('角色变更请求无效。');
            }
            $user = (new UserManagementService())->changeRole(
                $userId,
                $payload['roleKey'],
                $payload['expectedPermissionVersion'],
                $actor,
                $requestId,
            );
            return JsonResponseFactory::create([
                'data' => ['user' => $user],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User role request failed.');
        }
    }

    /** 计算批量启停或库授权的影响范围，并返回五分钟冻结预览令牌。 */
    public function bulkPreview(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new UserBulkInvalid('批量命令无效。');
            return JsonResponseFactory::create([
                'data' => ['preview' => (new UserBulkService())->preview($payload, $actor)],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User bulk preview failed.');
        }
    }

    /** 提交与预览快照完全一致的批量命令；事实变化时整体拒绝。 */
    public function bulkApply(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_users');
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new UserBulkInvalid('批量命令无效。');
            return JsonResponseFactory::create([
                'data' => ['result' => (new UserBulkService())->apply($payload, $actor, $requestId)],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->mapFailure($throwable, $requestId, 'User bulk apply failed.');
        }
    }

    /** Maps known authorization/domain failures and logs only unexpected server errors. */
    private function mapFailure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有执行此操作的权限。', 403, $requestId);
        }
        if ($throwable instanceof UserNotFound) {
            return JsonResponseFactory::error('USER_NOT_FOUND', '用户不存在。', 404, $requestId);
        }
        if ($throwable instanceof UserConflict) {
            return JsonResponseFactory::error('USER_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof UserBulkInvalid) {
            return JsonResponseFactory::error('USER_BULK_INVALID', $throwable->getMessage(), 422, $requestId);
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error(
            'USER_MANAGEMENT_UNAVAILABLE',
            '用户管理服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
