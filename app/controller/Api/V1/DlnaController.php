<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Dlna\DlnaService;
use app\application\Dlna\DlnaUnavailable;
use app\application\Media\MediaStreamNotFound;
use app\application\Media\MediaStreamService;
use app\application\Media\MediaStreamUnavailable;
use app\application\Subsonic\SubsonicTranscodeService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use app\http\TranscodeResponse;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 把内置 DLNA DMC 暴露给当前 Web Session 用户。
 *
 * 所有入口都实时要求 play 与独立 cast 能力；转码格式继承这两项基础授权。Controller 只接受 Renderer
 * UDN、歌曲 ULID、发现接口签发的短期不透明路由令牌、固定格式和有界控制值，不接受设备 URL、媒体 URL、
 * 路径、SOAP 或 FFmpeg 参数。路由令牌由服务端加密并绑定设备与期限，浏览器不能用它选择任意目标地址。
 * POST 路由由 CSRF 中间件保护，领域服务继续复验设备白名单、歌曲库授权和文件身份。
 */
final class DlnaController
{
    /** 返回当前账号的去地址化设备历史、格式与可恢复活动快照，不执行 SSDP 或 SOAP。 */
    public function session(Request $request): Response
    {
        return $this->run($request, static fn (array $actor, string $requestId): array => [
            'session' => (new DlnaService())->session($actor),
        ], 'DLNA session restoration failed.');
    }

    /** 执行一次有界 SSDP 发现；结果不包含设备地址或控制 URL。 */
    public function devices(Request $request): Response
    {
        return $this->run($request, static fn (array $actor, string $requestId): array => [
            'devices' => (new DlnaService())->discover($actor),
        ], 'DLNA device discovery failed.');
    }

    /**
     * 为当前账号有权歌曲创建短期票据并投放到一个 Renderer。
     *
     * 可选 positionMs/startPaused 只供 Web 在目标超过真实投递范围时重新建立媒体连接；位置有统一上限且
     * 服务层再按曲库时长收口，浏览器仍不能提交媒体 URL、字节偏移或 FFmpeg 参数。任一步失败均由服务层
     * 撤销新票据，不自动重复不可幂等的 SetURI。
     */
    public function play(Request $request): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request): array {
            $payload = self::objectPayload(
                $request,
                ['deviceId', 'songId', 'format'],
                ['routeToken', 'positionMs', 'startPaused'],
            );
            $deviceId = $payload['deviceId'] ?? null;
            $songId = $payload['songId'] ?? null;
            $format = $payload['format'] ?? null;
            $routeToken = $payload['routeToken'] ?? null;
            $positionMs = $payload['positionMs'] ?? 0;
            $startPaused = $payload['startPaused'] ?? false;
            if (!is_string($deviceId) || !is_string($songId) || !is_string($format)
                || ($routeToken !== null && !is_string($routeToken))
                || !is_int($positionMs) || $positionMs < 0 || $positionMs > 604_800_000
                || !is_bool($startPaused) || ($startPaused && $positionMs === 0)
                || !in_array($format, ['raw', 'mp3', 'aac', 'opus'], true)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 投放请求无效。');
            }
            return ['playback' => (new DlnaService())->play(
                $actor,
                $deviceId,
                $songId,
                $format,
                $requestId,
                $routeToken,
                $positionMs,
                $startPaused,
            )];
        }, 'DLNA playback request failed.');
    }

    /**
     * 在改变 Renderer URI 之前生成来源无关的精确长度转码缓存。
     *
     * 请求只接受歌曲 ULID 与固定有损格式，仍实时要求 play、cast 和歌曲库权限。成功响应为
     * 204，不返回媒体 URL、缓存键或音频正文。本地文件与 WebDAV 都以统一媒体 ETag 和去地址化编码计划
     * 命中共享派生缓存；WebDAV 原始音频仍只经一次性 Range 代理读取，不会完整持久化。失败不会取得设备
     * 租约或修改音响状态，前端不得继续发送 play，从而避免 Renderer 在等待 Header 时反复断开。
     */
    public function prepare(Request $request): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request): Response {
            $payload = self::objectPayload($request, ['songId', 'format']);
            $songId = $payload['songId'] ?? null;
            $format = $payload['format'] ?? null;
            if (!is_string($songId) || !is_string($format) || !in_array($format, ['mp3', 'aac', 'opus'], true)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 转码预热请求无效。');
            }
            $media = (new MediaStreamService())->resolve($actor, $songId);
            $response = (new SubsonicTranscodeService())->negotiate($media, [
                'format' => $format,
                'converted' => 'true',
                'estimateContentLength' => 'true',
            ], $requestId, $actor, persistentCache: true, lowLatency: true, cacheOnly: true);
            if (!$response instanceof TranscodeResponse || !$response->plan->cacheOnly) {
                throw new DlnaUnavailable('DLNA_TRANSCODE_PREPARE_FAILED', 'DLNA 转码预热失败。');
            }
            return $response;
        }, 'DLNA transcode preparation failed.');
    }

    /** 执行固定 Renderer 控制动作；status 也使用 POST，避免 UDN 进入访问日志查询串。 */
    public function control(Request $request): Response
    {
        return $this->run($request, static function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 控制请求无效。');
            }
            $operation = $payload['operation'] ?? null;
            $allowed = match ($operation) {
                'seek' => ['deviceId', 'operation', 'positionMs'],
                'volume' => ['deviceId', 'operation', 'volume'],
                'pause', 'resume', 'stop', 'status' => ['deviceId', 'operation'],
                default => throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 控制动作无效。'),
            };
            // routeToken 是发现后的可选快速路由；允许旧页面在滚动部署期间继续使用安全 SSDP 回退。
            if (array_key_exists('routeToken', $payload)) $allowed[] = 'routeToken';
            self::assertExactKeys($payload, $allowed);
            $deviceId = $payload['deviceId'] ?? null;
            if (!is_string($deviceId)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 设备标识无效。');
            }
            $routeToken = $payload['routeToken'] ?? null;
            if ($routeToken !== null && !is_string($routeToken)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 路由令牌无效。');
            }
            $value = match ($operation) {
                'seek' => $payload['positionMs'] ?? null,
                'volume' => $payload['volume'] ?? null,
                default => null,
            };
            if (($operation === 'seek' || $operation === 'volume') && !is_int($value)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 控制数值无效。');
            }
            return ['control' => (new DlnaService())->control(
                $actor,
                $deviceId,
                $operation,
                $value,
                $requestId,
                $routeToken,
            )];
        }, 'DLNA control request failed.');
    }

    /**
     * 统一执行 Session 授权和安全错误映射。
     *
     * @param callable(array<string,mixed>,string):(array<string,mixed>|Response) $operation
     */
    private function run(Request $request, callable $operation, string $logMessage): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'play');
            if (!in_array('cast', $actor['capabilities'] ?? [], true)) {
                throw new AuthorizationDenied('Cast capability is required.');
            }
            $result = $operation($actor, $requestId);
            if ($result instanceof Response) return $result;
            return JsonResponseFactory::create([
                'data' => $result,
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            if ($throwable instanceof AuthenticationRequired) {
                return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
            }
            if ($throwable instanceof AuthorizationDenied
                || ($throwable instanceof DlnaUnavailable && $throwable->reasonCode === 'DLNA_PERMISSION_DENIED')) {
                return JsonResponseFactory::error('DLNA_PERMISSION_DENIED', '没有投放到音响的权限。', 403, $requestId);
            }
            if ($throwable instanceof MediaStreamNotFound) {
                return JsonResponseFactory::error('MEDIA_NOT_FOUND', '歌曲不存在或无权访问。', 404, $requestId);
            }
            if ($throwable instanceof MediaStreamUnavailable) {
                return JsonResponseFactory::error('MEDIA_FILE_UNAVAILABLE', '音频文件暂时不可用。', 503, $requestId);
            }
            if ($throwable instanceof DlnaUnavailable) {
                return JsonResponseFactory::error(
                    $throwable->reasonCode,
                    'DLNA 音响操作失败。',
                    self::httpStatusFor($throwable),
                    $requestId,
                );
            }
            Log::error($logMessage, ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('DLNA_UNAVAILABLE', 'DLNA 服务暂时不可用。', 503, $requestId);
        }
    }

    /**
     * 把稳定 DLNA 领域错误映射为不会泄露设备或账号身份的 HTTP 语义。
     *
     * 账号占用是可重试的资源冲突，固定返回 409；Redis 协调不可用以及 helper 故障返回 503。客户端只能
     * 根据机器码显示“其他账号正在使用”，响应不得包含占用者 ID、用户名、租约摘要或剩余 TTL。
     */
    private static function httpStatusFor(DlnaUnavailable $failure): int
    {
        if ($failure->reasonCode === 'DLNA_DEVICE_BUSY') return 409;
        if ($failure->reasonCode === 'DLNA_DEVICE_NOT_FOUND') return 404;
        if (str_ends_with($failure->reasonCode, '_INVALID')
            || $failure->reasonCode === 'DLNA_DEVICE_NOT_ALLOWED') return 422;
        return 503;
    }

    /**
     * 读取严格 JSON 对象，保证必填字段存在，并只放行显式声明的可选字段。
     *
     * 可选字段缺失与显式 null 是不同的协议输入，但都不会绕过后续类型校验；本层只负责字段集合，具体值
     * 仍由对应动作验证。未知字段始终失败，避免客户端误以为任意 URL、地址或编码参数已经生效。本方法无
     * 外部副作用，同一请求重复校验结果一致。
     *
     * @param list<string> $requiredKeys
     * @param list<string> $optionalKeys
     * @return array<string,mixed>
     */
    private static function objectPayload(
        Request $request,
        array $requiredKeys,
        array $optionalKeys = [],
    ): array
    {
        $payload = $request->post();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 请求无效。');
        }
        self::assertRequiredAndOptionalKeys($payload, $requiredKeys, $optionalKeys);
        return $payload;
    }

    /**
     * 校验封闭对象的必填与可选字段集合。
     *
     * `$requiredKeys` 中每个字段必须出现；`$optionalKeys` 可出现零次或一次，PHP 数组键天然保证不会重复。
     * 两个声明集合由服务端常量调用点提供，不能来自请求。任何缺失或未知字段抛出稳定请求错误，不修改
     * 输入，也不执行设备、票据或租约副作用。
     *
     * @param array<string,mixed> $payload
     * @param list<string> $requiredKeys
     * @param list<string> $optionalKeys
     */
    private static function assertRequiredAndOptionalKeys(
        array $payload,
        array $requiredKeys,
        array $optionalKeys,
    ): void {
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey, $payload)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 请求字段无效。');
            }
        }
        $allowedKeys = [...$requiredKeys, ...$optionalKeys];
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 请求字段无效。');
            }
        }
    }

    /** 未知字段必须失败，避免调用方误以为某个未实现的控制参数已经生效。 */
    private static function assertExactKeys(array $payload, array $allowedKeys): void
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($allowedKeys);
        if ($keys !== $allowedKeys) {
            throw new DlnaUnavailable('DLNA_REQUEST_INVALID', 'DLNA 请求字段无效。');
        }
    }
}
