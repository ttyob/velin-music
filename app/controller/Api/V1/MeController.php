<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthorizationService;
use app\application\Preference\UserPreferenceConflict;
use app\application\Preference\UserPreferenceInvalid;
use app\application\Preference\UserPreferenceService;
use app\application\Preference\UserPreferenceValidator;
use app\application\Preference\PlaybackPreferenceService;
use app\application\Preference\PlaybackPreferenceValidator;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * Returns the authoritative current Web identity and preference snapshot.
 *
 * AuthorizationService 对 Cookie、PAT 与 App access token 使用各自撤销语义，并在每次调用重建账号状态、
 * 能力、音乐库授权和偏好。显式无效 Bearer 不回退 Cookie，防止移动凭据意外借用浏览器会话。
 */
final class MeController
{
    /** Returns HTTP 401 for every absent, expired, revoked, disabled, or deleted session. */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $user = (new AuthorizationService())->currentActor($request);
        } catch (Throwable $throwable) {
            Log::error('Current-user request failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'AUTHENTICATION_UNAVAILABLE',
                '暂时无法验证登录状态，请稍后重试。',
                503,
                $requestId,
            );
        }
        if ($user === null) {
            return JsonResponseFactory::error(
                'AUTHENTICATION_REQUIRED',
                '请先登录。',
                401,
                $requestId,
            );
        }

        return JsonResponseFactory::create([
            'data' => ['user' => $user],
            'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
        ], 200, $requestId);
    }

    /**
     * Updates only the authenticated user's UI locale under optimistic version control.
     *
     * 路由对 Cookie 请求验证 CSRF，对白名单 App/PAT Bearer 验证令牌后跳过 CSRF。AuthorizationService
     * 只提供凭据绑定且数据库复验后的当前用户 ID，请求不能选择其他账号。校验发生在短事务前；稳定
     * 422/409 让 Web 或 App 回滚乐观语言切换，日志不包含设置正文或身份秘密。
     */
    public function updatePreferences(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) {
                return JsonResponseFactory::error(
                    'AUTHENTICATION_REQUIRED',
                    '请先登录。',
                    401,
                    $requestId,
                );
            }
            $payload = $request->post();
            $command = (new UserPreferenceValidator())->locale(is_array($payload) ? $payload : []);
            $preferences = (new UserPreferenceService())->updateLocale(
                $actor,
                $command['locale'],
                $command['expectedVersion'],
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['preferences' => $preferences],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (UserPreferenceInvalid) {
            return JsonResponseFactory::error(
                'VALIDATION_FAILED',
                '语言或个人设置版本无效。',
                422,
                $requestId,
            );
        } catch (UserPreferenceConflict $throwable) {
            return JsonResponseFactory::error(
                'USER_PREFERENCE_CONFLICT',
                $throwable->getMessage(),
                409,
                $requestId,
            );
        } catch (Throwable $throwable) {
            Log::error('Current-user preference update failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'USER_PREFERENCE_UNAVAILABLE',
                '个人设置服务暂时不可用。',
                503,
                $requestId,
            );
        }
    }

    /**
     * 使用乐观版本更新当前账号的基础色主题。
     *
     * Cookie 请求强制 CSRF；已验证 App/PAT Bearer 不依赖 Cookie。请求只能包含主题 ID 和偏好版本；
     * 历史显示模式字段直接返回 422。服务再次验证主题仍为已发布预置项并保留语言、时区、动效和播放
     * 设置。409 要求客户端重新读取 `/me`，不会自动覆盖另一客户端的新选择。
     */
    public function updateTheme(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            $payload = $request->post();
            $command = (new UserPreferenceValidator())->theme(is_array($payload) ? $payload : []);
            $preferences = (new UserPreferenceService())->updateTheme(
                $actor,
                $command['themeId'],
                $command['expectedVersion'],
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['preferences' => $preferences],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (UserPreferenceInvalid) {
            return JsonResponseFactory::error(
                'THEME_PREFERENCE_INVALID',
                '基础色主题不可用。',
                422,
                $requestId,
            );
        } catch (UserPreferenceConflict $throwable) {
            return JsonResponseFactory::error('USER_PREFERENCE_CONFLICT', $throwable->getMessage(), 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Current-user theme update failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'USER_PREFERENCE_UNAVAILABLE',
                '个人设置服务暂时不可用。',
                503,
                $requestId,
            );
        }
    }

    /**
     * 返回当前账号播放偏好以及实时生效的管理员码率上限。
     *
     * 身份来自数据库复验后的 Cookie/PAT/App 凭据；响应不包含文件、歌曲、设备指纹或 FFmpeg 命令。
     * 读取不要求 CSRF，因为它不改变状态，但账号停用、会话/令牌撤销和权限变化在本次请求立即生效。
     */
    public function playbackPreferences(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            return JsonResponseFactory::create([
                'data' => ['preferences' => (new PlaybackPreferenceService())->snapshot($actor)],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Current-user playback preference read failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error(
                'PLAYBACK_PREFERENCE_UNAVAILABLE',
                '播放设置暂时不可用。',
                503,
                $requestId,
            );
        }
    }

    /**
     * 在共享个人偏好版本锁下保存播放模式、输出格式、码率与 ReplayGain。
     *
     * 路由先完成 Cookie CSRF 或白名单 Bearer 验证；严格校验拒绝数值字符串和未知枚举。409 表示其他
     * 客户端已更新同一账号，调用方必须重新读取服务端快照，不能自动重放陈旧表单覆盖新值。
     */
    public function updatePlaybackPreferences(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            $payload = $request->post();
            $command = (new PlaybackPreferenceValidator())->validate(is_array($payload) ? $payload : []);
            $preferences = (new PlaybackPreferenceService())->update($actor, $command, $requestId);
            return JsonResponseFactory::create([
                'data' => ['preferences' => $preferences],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (UserPreferenceInvalid) {
            return JsonResponseFactory::error(
                'PLAYBACK_PREFERENCE_INVALID',
                '播放模式、格式、码率或 ReplayGain 设置无效。',
                422,
                $requestId,
            );
        } catch (UserPreferenceConflict $throwable) {
            return JsonResponseFactory::error('USER_PREFERENCE_CONFLICT', $throwable->getMessage(), 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Current-user playback preference update failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error(
                'PLAYBACK_PREFERENCE_UNAVAILABLE',
                '播放设置暂时不可用。',
                503,
                $requestId,
            );
        }
    }

    /** 自动保存当前账号的减少动效选择；409 时客户端必须回读 `/me`。 */
    public function updateMotionPreference(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            $payload = $request->post();
            $command = (new UserPreferenceValidator())->motion(is_array($payload) ? $payload : []);
            $preferences = (new UserPreferenceService())->updateMotion(
                $actor, $command['reduceMotion'], $command['expectedVersion'], $requestId,
            );
            return JsonResponseFactory::create([
                'data' => ['preferences' => $preferences],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (UserPreferenceInvalid) {
            return JsonResponseFactory::error('MOTION_PREFERENCE_INVALID', '动效辅助设置无效。', 422, $requestId);
        } catch (UserPreferenceConflict $throwable) {
            return JsonResponseFactory::error('USER_PREFERENCE_CONFLICT', $throwable->getMessage(), 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Current-user motion preference update failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('USER_PREFERENCE_UNAVAILABLE', '个人设置服务暂时不可用。', 503, $requestId);
        }
    }
}
