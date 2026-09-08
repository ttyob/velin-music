<?php

declare(strict_types=1);

namespace app\controller\Api\V1;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationService;
use app\application\System\BasicSystemSettingsService;
use app\application\System\PublicUrlConfig;
use app\application\System\DlnaSettingsService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;

/**
 * 返回匿名、无部署秘密的 App/API 版本与功能协商快照（APP-API-002/004）。
 *
 * 客户端必须先读取本端点再登录。外部票据只有在配置了规范 HTTP(S) 投放 Origin 时才声明可用；服务不
 * 从 Host/X-Forwarded-Host 推导接收器 URL，避免代理头投毒。HTTP 仅兼容传统局域网 Renderer，其明文
 * 风险由部署者承担；响应不代表登录账号拥有具体 capability，登录后仍须读取 `/me.capabilities` 并由
 * 每个资源端点实时授权。
 */
final class AppCapabilityController
{
    public function show(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        $external = self::externalTicketAvailable();
        $serverDlna = self::serverManagedDlnaAvailable();
        return JsonResponseFactory::create(['data' => [
            'product' => 'Velin Music',
            'siteName' => self::configuredSiteName(),
            'apiVersion' => 'v1',
            'minimumAppVersion' => ['android' => '0.1.0', 'ios' => '0.1.0',
                'windows' => '0.1.0', 'macos' => '0.1.0', 'linux' => '0.1.0'],
            'authentication' => ['authorizationCodePkceS256' => true, 'rotatingRefreshToken' => true,
                'accessTokenLifetimeSeconds' => 900],
            'features' => [
                'catalog' => true, 'search' => true, 'queue' => true, 'favorites' => true,
                'playlists' => true, 'bookmarks' => true, 'lyrics' => true, 'offlineManifest' => true,
                'realtimeSse' => true, 'playbackExternalTicket' => $external,
                'serverManagedDlna' => $serverDlna, 'serverManagedAirplay' => true,
            ],
            'outputScopes' => ['onDevice', 'nearbyNetwork', 'serverManaged'],
            'externalProtocols' => $external ? ['dlna', 'google_cast'] : [],
        ], 'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')]], 200, $requestId)
            ->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * 接受 App 一次性的版本化播放能力声明并返回服务端收敛结果，不持久化设备指纹。
     *
     * 只有白名单 MIME、格式、歌词层级和本地输出协议会进入响应；未知字段或越界数值返回 422，绝不被
     * 当作更高权限。该声明只帮助客户端选择本机解码/附近投放计划，不授予 play、download、transcode
     * 或 cast，目标资源端点仍按 token scope 和实时账号权限独立检查。
     */
    public function negotiate(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->currentActor($request);
            if ($actor === null) throw new AuthenticationRequired('Authentication required.');
            if (($actor['authenticationType'] ?? null) !== 'app_access_token') {
                return JsonResponseFactory::error('APP_ACCESS_TOKEN_REQUIRED', '需要 App 访问令牌。', 403, $requestId);
            }
            $payload = $request->post();
            if (!is_array($payload) || array_is_list($payload)) throw new \InvalidArgumentException();
            $keys = array_keys($payload);
            sort($keys);
            if ($keys !== ['apiVersion', 'appVersion', 'localOutputs', 'lyrics', 'platform', 'playback']) {
                throw new \InvalidArgumentException();
            }
            if (($payload['apiVersion'] ?? null) !== 'v1'
                || !is_string($payload['appVersion'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,39}$/D', $payload['appVersion']) !== 1
                || !is_string($payload['platform'] ?? null)
                || !in_array($payload['platform'], ['android', 'ios', 'windows', 'macos', 'linux'], true)) {
                throw new \InvalidArgumentException();
            }
            $playback = $this->playback($payload['playback'] ?? null);
            $lyrics = $this->stringList($payload['lyrics'] ?? null, ['plain', 'line', 'word'], 3);
            $outputs = $this->stringList($payload['localOutputs'] ?? null,
                ['system_route', 'airplay', 'dlna', 'google_cast'], 4);
            return JsonResponseFactory::create(['data' => ['negotiated' => [
                'apiVersion' => 'v1', 'platform' => $payload['platform'], 'appVersion' => $payload['appVersion'],
                'playback' => $playback, 'lyrics' => $lyrics, 'localOutputs' => $outputs,
            ], 'accountCapabilities' => $actor['capabilities'] ?? []], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId)->withHeader('Cache-Control', 'private, no-store');
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (\InvalidArgumentException) {
            return JsonResponseFactory::error('APP_CAPABILITY_DECLARATION_INVALID',
                'App 能力声明无效。', 422, $requestId);
        } catch (\Throwable $throwable) {
            Log::error('App capability negotiation failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('APP_CAPABILITY_UNAVAILABLE',
                'App 能力协商暂时不可用。', 503, $requestId);
        }
    }

    /** 只声明共享配置边界确认可用的显式 HTTP(S) Origin；非法或缺失配置均安全关闭附近投放。 */
    public static function externalTicketAvailable(): bool
    {
        try {
            return PublicUrlConfig::externalPlaybackOrigin() !== null;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** 只在版本化全局设置明确开启且配置可读时声明服务端 DLNA；缺失/损坏状态失败关闭。 */
    public static function serverManagedDlnaAvailable(): bool
    {
        try {
            return (new DlnaSettingsService())->get()['enabled'] === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 返回匿名页面可展示的站点名称，同时保留固定 product 字段作为客户端兼容标识。
     *
     * 该值只来自已校验的 `site.basic` 公共展示字段，不投影版本、操作者或其他系统配置。初始化尚未
     * 完成、迁移缺失或持久数据损坏时回退产品名，使登录和离线入口仍可渲染；读取没有写入或网络
     * 副作用，修复配置后下一次协商即可恢复自定义名称。
     */
    public static function configuredSiteName(): string
    {
        try {
            return (new BasicSystemSettingsService())->get()['siteName'];
        } catch (\Throwable) {
            return 'Velin Music';
        }
    }

    /** @return array<string,mixed> */
    private function playback(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) throw new \InvalidArgumentException();
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['maxBitDepth', 'maxBitrateKbps', 'maxSampleRate', 'mimeTypes',
            'preferredTranscode', 'supportsRange']) throw new \InvalidArgumentException();
        $mimes = $this->stringList($value['mimeTypes'] ?? null, [
            'audio/mpeg', 'audio/flac', 'audio/aac', 'audio/mp4', 'audio/ogg', 'audio/wav', 'audio/aiff',
        ], 16);
        $sampleRate = $value['maxSampleRate'] ?? null;
        $bitDepth = $value['maxBitDepth'] ?? null;
        $bitrate = $value['maxBitrateKbps'] ?? null;
        $preferred = $value['preferredTranscode'] ?? null;
        if (!is_int($sampleRate) || $sampleRate < 8_000 || $sampleRate > 768_000
            || !is_int($bitDepth) || $bitDepth < 8 || $bitDepth > 64
            || !is_int($bitrate) || $bitrate < 32 || $bitrate > 2_000
            || !is_string($preferred) || !in_array($preferred, ['raw', 'mp3', 'aac', 'opus'], true)
            || !is_bool($value['supportsRange'] ?? null)) throw new \InvalidArgumentException();
        return ['mimeTypes' => $mimes, 'maxSampleRate' => $sampleRate, 'maxBitDepth' => $bitDepth,
            'preferredTranscode' => $preferred, 'maxBitrateKbps' => $bitrate,
            'supportsRange' => $value['supportsRange']];
    }

    /** @param list<string> $allowed @return list<string> */
    private function stringList(mixed $value, array $allowed, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new \InvalidArgumentException();
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) throw new \InvalidArgumentException();
            $result[$item] = true;
        }
        return array_keys($result);
    }
}
