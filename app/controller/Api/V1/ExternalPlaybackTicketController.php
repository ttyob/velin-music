<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\ExternalPlayback\ExternalPlaybackTicketService;
use app\application\ExternalPlayback\ExternalPlaybackUnavailable;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamUnavailable;
use app\application\Transcode\TranscodeCapacityExceeded;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 为 App 当前网络的 DLNA/Google Cast 接收器准备音频并签发无 Header 播放 URL。
 *
 * 路由要求 App Bearer，PAT 和 Cookie 即使拥有 play 也不能签发。请求不含设备标识或地址；Controller
 * 只把歌曲 ID 与严格格式能力交给服务。服务端管理投放仍走 `/dlna`、`/airplay` 并另需 cast，本端点
 * 不能枚举、占用或控制服务端网络的任何音响。准备接口只发布可重建的精确长度转码缓存，成功后返回
 * 204；源格式直放时不创建文件。缓存与后续票据仍在每次请求中重新验证账号、库授权和媒体身份。
 */
final class ExternalPlaybackTicketController
{
    /**
     * 签发一个五分钟内必须首用的外部播放票据。
     *
     * 调用方应先以相同 payload 调用 prepare；服务端仍独立重新协商，防止准备后账号能力、媒体身份或
     * 接收器声明变化。成功只返回短期 URL 与格式，不返回缓存键、设备信息或远端源定位；数据库事务失败
     * 不会留下可用票据，重复调用会创建彼此独立的随机 capability。
     */
    public function create(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $payload = self::payload($request);
            $ticket = (new ExternalPlaybackTicketService())->create($actor, $songId, $payload, $requestId);
            return JsonResponseFactory::create(['data' => $ticket, 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 201, $requestId)->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $throwable) {
            return self::failure($throwable, $requestId, 'External playback ticket creation failed.');
        }
    }

    /**
     * 在 App 触碰 Renderer 前准备同一能力声明对应的派生音频。
     *
     * raw 直放立即返回 204；转码分支由 supervisor 异步读取本地或 WebDAV 统一媒体源，完整写入私有临时
     * 文件并原子发布后才返回 204。客户端断开会取消 FFmpeg、关闭 WebDAV 回环租约并删除部分文件；重试
     * 相同媒体版本和编码计划会命中共享缓存，不再次转码。方法不签发票据、不取得 cast 租约，也不接收
     * Renderer 地址，因此只要求 App Bearer 的 play，实际转码仍由服务实时要求 transcode。
     */
    public function prepare(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            $response = (new ExternalPlaybackTicketService())->prepare(
                $actor,
                $songId,
                self::payload($request),
                $requestId,
            );

            return $response ?? response('', 204, ['Cache-Control' => 'no-store']);
        } catch (Throwable $throwable) {
            return self::failure($throwable, $requestId, 'External playback preparation failed.');
        }
    }

    /** @return array<string,mixed> 严格字段仍由领域服务统一校验。 */
    private static function payload(Request $request): array
    {
        $payload = $request->post();
        if (!is_array($payload)) {
            throw new ExternalPlaybackUnavailable(
                'EXTERNAL_TICKET_REQUEST_INVALID',
                '外部播放请求无效。',
            );
        }

        return $payload;
    }

    /**
     * 把准备与签发的共同失败映射为不泄露路径、票据、命令或上游响应的 HTTP 语义。
     *
     * 并发容量与账号限额允许稍后重试并携带固定 Retry-After；媒体不存在保持不可枚举 404，媒体身份或
     * WebDAV 失败返回 503。未知异常只记录 requestId 和类名，禁止记录异常 message，因为其中可能含内部
     * 回环 URL。该方法不重试、补偿或修改已经由下层持有的资源，supervisor 负责连接关闭时清理。
     */
    private static function failure(Throwable $throwable, string $requestId, string $logMessage): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有播放音乐的权限。', 403, $requestId);
        }
        if ($throwable instanceof MediaStreamNotFound) {
            return JsonResponseFactory::error('MEDIA_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
        }
        if ($throwable instanceof ExternalPlaybackUnavailable) {
            $status = $throwable->reasonCode === 'APP_ACCESS_TOKEN_REQUIRED' ? 403
                : ($throwable->reasonCode === 'EXTERNAL_TICKET_REQUEST_INVALID' ? 422 : 503);
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                $throwable->getMessage(),
                $status,
                $requestId,
            )->withHeader('Cache-Control', 'no-store');
        }
        if ($throwable instanceof MediaStreamUnavailable) {
            return JsonResponseFactory::error(
                'MEDIA_FILE_UNAVAILABLE',
                '音频文件暂时不可用。',
                503,
                $requestId,
            );
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                '当前账号的转码并发或码率已达到管理员限制。',
                429,
                $requestId,
                ['current' => $throwable->current, 'maximum' => $throwable->maximum],
            )->withHeader('Retry-After', '5');
        }
        if ($throwable instanceof TranscodeCapacityExceeded) {
            return JsonResponseFactory::error(
                'TRANSCODE_CAPACITY_EXCEEDED',
                '转码服务繁忙，请稍后重试。',
                503,
                $requestId,
            )->withHeader('Retry-After', '5');
        }
        Log::error($logMessage, [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);
        return JsonResponseFactory::error(
            'EXTERNAL_OUTPUT_UNAVAILABLE',
            '附近设备播放暂时不可用。',
            503,
            $requestId,
        );
    }
}
