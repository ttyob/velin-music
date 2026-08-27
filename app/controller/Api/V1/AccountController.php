<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Account\SelfAccountConflict;
use app\application\Account\SelfAccountInvalid;
use app\application\Account\SelfAccountService;
use app\application\Account\SelfAccountValidator;
use app\application\Account\SelfSessionNotFound;
use app\application\Auth\SessionService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供当前 Web Session 账号的资料、密码与会话自助 API。
 *
 * 每个方法都先通过 SessionService 复验账号状态；URL 只允许选择当前账号自己的会话，不能指定用户。
 * 写请求由显式路由执行 CSRF 校验，密码和 Cookie/Session 原值不会进入日志。领域服务负责事务、版本
 * 锁与审计，Controller 只映射有界输入和稳定 HTTP 错误。
 */
final class AccountController
{
    /** 用个人偏好 expectedVersion 更新显示名、邮箱和时区，冲突返回 409。 */
    public function updateProfile(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->actor($request);
            $payload = $request->post();
            $command = (new SelfAccountValidator())->profile(is_array($payload) ? $payload : []);
            $profile = (new SelfAccountService())->updateProfile(
                $actor,
                $command['displayName'],
                $command['email'],
                $command['timezone'],
                $command['expectedVersion'],
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['profile' => $profile],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Self profile update failed.');
        }
    }

    /**
     * 复验当前密码并更换密码，成功后保留本次 Web Session、撤销其他活动 Session。
     *
     * 当前 Session 只在请求内转换为单向 SHA-256 查找摘要；摘要不会返回客户端或写入日志。最低长度为
     * 用户已确认的 8 位，服务端仍对超长输入设限。改密不自动退出当前页面，便于用户看到撤销数量。
     */
    public function changePassword(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->actor($request);
            $payload = $request->post();
            $command = (new SelfAccountValidator())->password(is_array($payload) ? $payload : []);
            $result = (new SelfAccountService())->changePassword(
                $actor,
                $command['currentPassword'],
                $command['newPassword'],
                $this->sessionHash($request),
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Self password update failed.');
        }
    }

    /** 返回当前账号最多 100 个未过期活动会话，不包含任何会话或设备指纹。 */
    public function sessions(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $items = (new SelfAccountService())->sessions($this->actor($request), $this->sessionHash($request));

            return JsonResponseFactory::create([
                'data' => ['sessions' => $items],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Self sessions read failed.');
        }
    }

    /**
     * 撤销当前账号的一个活动会话；若撤销本会话，数据库提交成功后再清空浏览器 Session。
     *
     * 文件 Session 清理不能与 SQLite 组成同一事务，所以数据库撤销是权威边界。清理失败也不会恢复已
     * 撤销凭据；后续请求会被 SessionService 拒绝。跨账号、过期和已撤销 ID 统一按 404 处理。
     */
    public function revokeSession(Request $request, string $sessionId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $result = (new SelfAccountService())->revokeSession(
                $this->actor($request),
                $sessionId,
                $this->sessionHash($request),
                $requestId,
            );
            if ($result['revokedCurrent']) {
                $request->session()->flush();
                $request->sessionRegenerateId(true);
            }

            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Self session revoke failed.');
        }
    }

    /** 返回已复验 actor；未登录使用稳定 SelfAccountInvalid，避免每个方法重复分支。 */
    private function actor(Request $request): array
    {
        $actor = (new SessionService())->currentUser($request);
        if ($actor === null) {
            throw new SelfAccountInvalid('Authentication required.');
        }

        return $actor;
    }

    /** 生成与 SessionService 数据库索引一致的单向摘要，原始 ID 不离开 Controller。 */
    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->sessionId());
    }

    /** 将已知自助账号错误映射为稳定响应，意外异常只记录类别与请求 ID。 */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof SelfSessionNotFound) {
            return JsonResponseFactory::error('SESSION_NOT_FOUND', '会话不存在或已失效。', 404, $requestId);
        }
        if ($throwable instanceof SelfAccountConflict) {
            return JsonResponseFactory::error('SELF_ACCOUNT_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof SelfAccountInvalid) {
            $authenticationRequired = $throwable->getMessage() === 'Authentication required.';
            return JsonResponseFactory::error(
                $authenticationRequired ? 'AUTHENTICATION_REQUIRED' : 'VALIDATION_FAILED',
                $authenticationRequired ? '请先登录。' : '请检查提交的账号信息或当前密码。',
                $authenticationRequired ? 401 : 422,
                $requestId,
            );
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('SELF_ACCOUNT_UNAVAILABLE', '账号服务暂时不可用。', 503, $requestId);
    }
}
