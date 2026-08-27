<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Airplay\AirplayService;
use app\application\Airplay\AirplayUnavailable;
use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 把内置 OwnTone AirPlay 输出能力暴露给当前 Web Session。
 *
 * 所有入口实时要求 play+cast。浏览器只能提交 `airplay:<数字>`、歌曲 ULID 和固定控制值，不能提交媒体
 * URL、OwnTone 地址、PIN、密码、mDNS 字段或任意编码参数。POST 路由另由 CSRF 保护；领域服务在创建
 * 短期票据前继续复验音乐库授权和文件身份，并在任何队列副作用前取得账号级全局租约。
 */
final class AirplayController
{
    /** 返回 OwnTone 当前发现的去地址化 AirPlay 输出，不取得播放租约。 */
    public function devices(Request $request): Response
    {
        return $this->run($request, static fn (array $actor, string $requestId): array => [
            'devices' => (new AirplayService())->discover($actor),
        ], 'AirPlay device discovery failed.');
    }

    /** 使用原始歌曲票据投放到一个 AirPlay 输出；请求不接受格式或 URL。 */
    public function play(Request $request): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request): array {
            $payload = self::objectPayload($request, ['deviceId', 'songId']);
            if (!is_string($payload['deviceId'] ?? null) || !is_string($payload['songId'] ?? null)) {
                throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 播放请求无效。');
            }
            return ['playback' => (new AirplayService())->play(
                $actor,
                $payload['deviceId'],
                $payload['songId'],
                $requestId,
            )];
        }, 'AirPlay playback request failed.');
    }

    /** 执行固定播放、跳转、音量或状态动作，拒绝协议外字段。 */
    public function control(Request $request): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) {
                throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 控制请求无效。');
            }
            $operation = $payload['operation'] ?? null;
            $allowed = match ($operation) {
                'seek' => ['deviceId', 'operation', 'positionMs'],
                'volume' => ['deviceId', 'operation', 'volume'],
                'pause', 'resume', 'stop', 'status' => ['deviceId', 'operation'],
                default => throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 控制动作无效。'),
            };
            self::assertExactKeys($payload, $allowed);
            if (!is_string($payload['deviceId'] ?? null)) {
                throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 输出标识无效。');
            }
            $value = match ($operation) {
                'seek' => $payload['positionMs'] ?? null,
                'volume' => $payload['volume'] ?? null,
                default => null,
            };
            if ($operation === 'seek' && (!is_int($value) || $value < 0 || $value > 604_800_000)) {
                throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 跳转位置无效。');
            }
            if ($operation === 'volume' && (!is_int($value) || $value < 0 || $value > 100)) {
                throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 音量无效。');
            }
            return ['control' => (new AirplayService())->control(
                $actor,
                $payload['deviceId'],
                $operation,
                $value,
                $requestId,
            )];
        }, 'AirPlay control request failed.');
    }

    /**
     * 统一执行认证、投放能力检查和脱敏错误映射。
     *
     * @param callable(array<string,mixed>,string):array<string,mixed> $operation
     */
    private function run(Request $request, callable $operation, string $logMessage): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            if (!in_array('cast', $actor['capabilities'] ?? [], true)) {
                throw new AuthorizationDenied('Cast capability is required.');
            }
            return JsonResponseFactory::create(['data' => $operation($actor, $requestId), 'meta' => [
                'requestId' => $requestId,
                'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied
                || ($throwable instanceof AirplayUnavailable
                    && $throwable->reasonCode === 'AIRPLAY_PERMISSION_DENIED')) {
                return JsonResponseFactory::error('AIRPLAY_PERMISSION_DENIED', '没有 AirPlay 投放权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaStreamNotFound) {
                return JsonResponseFactory::error('MEDIA_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof MediaStreamUnavailable) {
                return JsonResponseFactory::error('MEDIA_FILE_UNAVAILABLE', '音频文件暂时不可用。', 503, $requestId);
            }
            if ($throwable instanceof AirplayUnavailable) {
                return JsonResponseFactory::error(
                    $throwable->reasonCode,
                    'AirPlay 音响操作失败。',
                    self::httpStatusFor($throwable),
                    $requestId,
                );
            }
            Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('AIRPLAY_UNAVAILABLE', 'AirPlay 服务暂时不可用。', 503, $requestId);
        }
    }

    /** 资源占用为 409，设备缺失为 404，封闭请求错误为 422，其余 companion 故障为 503。 */
    private static function httpStatusFor(AirplayUnavailable $failure): int
    {
        if ($failure->reasonCode === 'AIRPLAY_DEVICE_BUSY') return 409;
        if ($failure->reasonCode === 'AIRPLAY_DEVICE_NOT_FOUND') return 404;
        if (str_ends_with($failure->reasonCode, '_INVALID')) return 422;
        return 503;
    }

    /** @return array<string,mixed> */
    private static function objectPayload(Request $request, array $allowedKeys): array
    {
        $payload = $request->post();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 请求无效。');
        }
        self::assertExactKeys($payload, $allowedKeys);
        return $payload;
    }

    /** 未知字段失败关闭，不能让调用方误以为 URL、PIN 或编码参数已生效。 */
    private static function assertExactKeys(array $payload, array $allowedKeys): void
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($allowedKeys);
        if ($keys !== $allowedKeys) {
            throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 请求字段无效。');
        }
    }
}
