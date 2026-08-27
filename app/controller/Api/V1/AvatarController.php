<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Account\AccountAvatarService;
use app\application\Auth\SessionService;
use app\application\Preference\UserPreferenceConflict;
use app\application\Preference\UserPreferenceInvalid;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/** 提供仅当前 Session 账号可读写的头像字节接口，不接受 userId、文件名或服务器路径。 */
final class AvatarController
{
    /** 返回自定义 PNG 或本地 identicon，并支持私有 ETag 条件请求。 */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            $image = (new AccountAvatarService())->image($actor);
            $headers = [
                'Content-Type' => 'image/png', 'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=86400, must-revalidate', 'ETag' => $image->etag,
                'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $requestId,
            ];
            if (trim((string) $request->header('if-none-match', '')) === $image->etag) {
                return response('', 304, $headers);
            }
            $headers['Content-Length'] = (string) strlen($image->bytes);
            return response($image->bytes, 200, $headers);
        } catch (Throwable $throwable) {
            Log::error('Current-user avatar read failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('AVATAR_UNAVAILABLE', '头像暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 接收原始图片请求体并在共享偏好版本锁下替换头像。
     *
     * `X-Expected-Version` 必须是规范十进制正整数；正文最大 5 MiB，MIME 与实际图像由服务层双重校验。
     * 路由层已完成 CSRF，Controller 和日志都不读取文件名或记录原始字节。
     */
    public function upload(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            $rawVersion = (string) $request->header('x-expected-version', '');
            if (preg_match('/^[1-9][0-9]{0,9}$/', $rawVersion) !== 1) throw new UserPreferenceInvalid('Avatar version is invalid.');
            $result = (new AccountAvatarService())->upload(
                $actor,
                $request->rawBody(),
                (string) $request->header('content-type', ''),
                (int) $rawVersion,
                $requestId,
            );
            return JsonResponseFactory::create([
                'data' => ['avatar' => $result],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Current-user avatar upload failed.');
        }
    }

    /** 删除自定义头像并回退 identicon；命令正文只接受 expectedVersion。 */
    public function delete(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new SessionService())->currentUser($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            $payload = $request->post();
            $version = is_array($payload) ? ($payload['expectedVersion'] ?? null) : null;
            if (!is_int($version)) throw new UserPreferenceInvalid('Avatar version is invalid.');
            $result = (new AccountAvatarService())->delete($actor, $version, $requestId);
            return JsonResponseFactory::create([
                'data' => ['avatar' => $result],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId, 'Current-user avatar delete failed.');
        }
    }

    /** 将头像校验、版本冲突和意外失败映射为稳定且不含图片细节的响应。 */
    private function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof UserPreferenceInvalid) {
            return JsonResponseFactory::error('AVATAR_INVALID', '请选择 5 MiB 以内的 JPEG、PNG 或 WebP 图片。', 422, $requestId);
        }
        if ($throwable instanceof UserPreferenceConflict) {
            return JsonResponseFactory::error('USER_PREFERENCE_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
        return JsonResponseFactory::error('AVATAR_UNAVAILABLE', '头像服务暂时不可用。', 503, $requestId);
    }
}
