<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Notification\NotificationInvalid;
use app\application\Notification\NotificationNotFound;
use app\application\Notification\NotificationService;
use app\application\Notification\NotificationValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供仅管理后台可访问的当前管理员通知中心。
 *
 * 每个入口先要求至少一项后台管理能力，普通登录用户和个人令牌均不能把此控制器当作用户端收件箱。
 * 数据仍由 NotificationService 按当前管理员账号隔离，浏览器不能指定接收者、对象链接、摘要、去重键
 * 或到期时间；写操作继续由路由层强制 CSRF。权限失败不读取任何通知，避免枚举管理员事件。
 */
final class NotificationController
{
    /**
     * 返回当前管理员自己的通知分页、未读数和非安全类静音偏好。
     *
     * 筛选值与分页边界先经过固定词汇校验；账号范围完全来自会话，不能由查询参数切换。失败不会返回
     * 部分收件箱数据，也不会把通知 ID、筛选条件或偏好正文写入日志。
     */
    public function index(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = $this->actor($request);
            $validator = new NotificationValidator();
            $page = (new NotificationService())->list(
                $actor,
                $validator->status($request->get('status')),
                $validator->severity($request->get('severity')),
                $validator->optionalType($request->get('type')),
                $validator->page($request->get('limit'), 50, 1, 100),
                $validator->page($request->get('offset'), 0, 0, 10_000),
            );

            return $this->response($page, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification list failed.');
        }
    }

    /** 返回当前管理员未过期的未读数，供后台导航轮询；读取不改变已读状态或保留期限。 */
    public function counts(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            return $this->response((new NotificationService())->counts($this->actor($request)), $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification count failed.');
        }
    }

    /**
     * 把一个当前管理员拥有且未过期的通知幂等标为已读，并返回重新授权后的投影。
     *
     * 不透明 ID 即使属于其他管理员也按不存在处理；事务失败时不会留下半更新状态。
     */
    public function read(Request $request, string $notificationId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $notification = (new NotificationService())->markRead(
                $this->actor($request),
                (new NotificationValidator())->id($notificationId),
            );

            return $this->response(['notification' => $notification], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification mark-read failed.');
        }
    }

    /** 在一次幂等更新中标记当前管理员全部未过期通知为已读，不影响其他接收者或过期历史。 */
    public function readAll(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $updated = (new NotificationService())->markAllRead($this->actor($request));

            return $this->response(['updated' => $updated], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification mark-all-read failed.');
        }
    }

    /**
     * 清空当前管理员自己的全部通知。
     *
     * 身份与后台能力由 actor() 统一校验，控制器不接受接收者或筛选参数；服务层只删除当前会话账号
     * 的通知并返回行数。成功响应可安全重试，失败不会返回部分清空结果。
     */
    public function clear(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $cleared = (new NotificationService())->clearAll($this->actor($request));

            return $this->response(['cleared' => $cleared], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification clear failed.');
        }
    }

    /** 设置或清除一种允许静音的非安全通知类型；只影响后续发布，不删除或改写既有通知。 */
    public function preference(Request $request, string $type): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $validator = new NotificationValidator();
            $preference = (new NotificationService())->setMuted(
                $this->actor($request),
                $validator->type($type),
                $validator->muted($request->post('muted')),
            );

            return $this->response(['preference' => $preference], $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Notification preference failed.');
        }
    }

    /**
     * 解析后台管理员身份。任一管理能力只允许进入通知中心，不会替代通知对象自身的实时库权限裁剪。
     *
     * @return array<string,mixed>
     */
    private function actor(Request $request): array
    {
        return (new AuthorizationService())->requireAnyCapability($request, [
            'manage_users',
            'manage_library',
            'manage_storage',
            'manage_system',
            'view_audit',
            'run_scrape',
            'edit_metadata',
            'view_play_privacy',
        ]);
    }

    /** 使用禁止缓存的统一响应信封包装后台私有数据，并附加当前请求追踪标识。 */
    private function response(array $data, string $requestId): Response
    {
        return JsonResponseFactory::create([
            'data' => $data,
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /** 将领域异常映射为稳定错误，不记录接收者、通知 ID、筛选值或偏好请求正文。 */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有访问后台通知的权限。', 403, $requestId);
        }
        if ($throwable instanceof NotificationInvalid) {
            return JsonResponseFactory::error('VALIDATION_FAILED', '请检查通知筛选或偏好参数。', 422, $requestId);
        }
        if ($throwable instanceof NotificationNotFound) {
            return JsonResponseFactory::error('NOTIFICATION_NOT_FOUND', '通知不存在或已过期。', 404, $requestId);
        }

        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);

        return JsonResponseFactory::error('NOTIFICATION_UNAVAILABLE', '通知服务暂时不可用。', 503, $requestId);
    }
}
