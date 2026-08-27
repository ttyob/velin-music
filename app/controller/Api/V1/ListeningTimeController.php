<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Playback\ListeningTimeInvalid;
use app\application\Playback\ListeningTimeService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 提供当前账号的日、周、月、年听歌时长聚合（PERS-007、API-PLAY-007）。
 *
 * 身份和时区只来自每次数据库复验后的认证 actor，请求不能指定其他用户或绕过 `play` 能力。控制器仅
 * 映射 period/from/to，应用服务负责日期上限、账号隔离和稀疏聚合；响应不包含歌曲、播放器、设备或
 * 原始事件。查询无副作用且统一 `no-store`，异常日志只记录 requestId 和异常类，不记录个人统计范围。
 */
final class ListeningTimeController
{
    /** 返回闭日期范围内的非零统计桶；省略日期时采用各周期的默认展示窗口。 */
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $period = $this->query($request->get('period'), 'day');
            $from = $this->query($request->get('from'));
            $to = $this->query($request->get('to'));
            $summary = (new ListeningTimeService())->summary(
                $actor,
                $period ?? 'day',
                $from,
                $to,
            );

            return JsonResponseFactory::create([
                'data' => ['listeningTime' => $summary],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
        } catch (ListeningTimeInvalid) {
            return JsonResponseFactory::error(
                'LISTENING_TIME_RANGE_INVALID',
                '听歌时长周期或日期范围无效。',
                422,
                $requestId,
            );
        } catch (Throwable $throwable) {
            Log::error('Listening-time summary failed.', [
                'request_id' => $requestId,
                'exception_class' => $throwable::class,
            ]);

            return JsonResponseFactory::error(
                'LISTENING_TIME_UNAVAILABLE',
                '听歌时长统计暂时不可用。',
                503,
                $requestId,
            );
        }
    }

    /**
     * 读取可空单值查询参数；缺失或空文本使用调用方默认值，数组和对象直接失败。
     *
     * 这样 `period[]=week`、`from[]=...` 不会被静默忽略并退回默认范围，避免调用页面误把错误查询展示为
     * 真实统计。方法无数据库或日志副作用，错误统一由 show 映射为稳定 422。
     */
    private function query(mixed $value, ?string $default = null): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            throw new ListeningTimeInvalid('query parameter is invalid.');
        }
        return $value;
    }
}
