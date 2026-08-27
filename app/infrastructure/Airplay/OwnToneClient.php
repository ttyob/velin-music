<?php

declare(strict_types=1);

namespace app\infrastructure\Airplay;

use app\application\Airplay\AirplayGateway;
use app\application\Airplay\AirplayUnavailable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 通过固定回环地址调用 OwnTone 29.3 JSON API。
 *
 * base_uri 不读取请求、Host 或 `.env`，避免该客户端成为 SSRF 代理；连接只允许回环上的 companion。
 * 所有响应按大小和字段类型校验，错误正文不进入异常或日志。播放 URL 必须由 Velin 票据服务生成并指向
 * 同一回环 Webman，调用者不能提交任意 URI。网络或协议失败统一映射为稳定 AirPlay 错误且不自动重试。
 */
final class OwnToneClient implements AirplayGateway
{
    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => 'http://127.0.0.1:3689',
            'connect_timeout' => 0.5,
            'timeout' => 4.0,
            'http_errors' => false,
            // 固定回环 companion 不能继承宿主 HTTP_PROXY，否则请求可能离开本机并泄露控制路径。
            'proxy' => '',
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /** {@inheritDoc} */
    public function outputs(): array
    {
        $payload = $this->json('GET', '/api/outputs');
        if (!is_array($payload['outputs'] ?? null) || count($payload['outputs']) > 128) {
            throw new AirplayUnavailable('AIRPLAY_PROTOCOL_INVALID', 'OwnTone 输出列表无效。');
        }
        $outputs = [];
        foreach ($payload['outputs'] as $output) {
            // OwnTone 29.3 的 JSON 文档写作 AirPlay，但实际运行时会按协商结果返回 AirPlay 1/AirPlay 2。
            // 使用封闭枚举兼容这三个官方值，仍明确排除同一 companion 发现的 Chromecast/本地输出。
            if (!is_array($output)
                || !in_array($output['type'] ?? null, ['AirPlay', 'AirPlay 1', 'AirPlay 2'], true)) continue;
            $id = $output['id'] ?? null;
            $name = $output['name'] ?? null;
            $volume = $output['volume'] ?? null;
            if (!is_string($id) || preg_match('/^[0-9]{1,20}$/D', $id) !== 1
                || !is_string($name) || trim($name) === '' || mb_strlen($name) > 200
                || !is_int($volume) || $volume < 0 || $volume > 100
                || !is_bool($output['selected'] ?? null)) {
                throw new AirplayUnavailable('AIRPLAY_PROTOCOL_INVALID', 'OwnTone 输出字段无效。');
            }
            $outputs[] = [
                'id' => $id,
                'name' => trim($name),
                'selected' => $output['selected'],
                'volume' => $volume,
                'requiresAuth' => ($output['has_password'] ?? false) === true
                    || ($output['requires_auth'] ?? false) === true
                    || ($output['needs_auth_key'] ?? false) === true,
            ];
        }
        return $outputs;
    }

    /** {@inheritDoc} */
    public function selectOutput(string $outputId): void
    {
        $output = $this->output($outputId);
        if ($output['requiresAuth']) {
            throw new AirplayUnavailable('AIRPLAY_PAIRING_REQUIRED', 'AirPlay 输出需要先完成配对。');
        }
        $this->emptyResponse('PUT', '/api/outputs/set', ['json' => ['outputs' => [$outputId]]]);
    }

    /** {@inheritDoc} */
    public function playUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || preg_match('#^/dlna/v1/streams/velin_dlna_[A-Za-z0-9_-]+$#D', (string) ($parts['path'] ?? '')) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AirplayUnavailable('AIRPLAY_MEDIA_URL_INVALID', 'AirPlay 媒体票据地址无效。');
        }
        $response = $this->request('POST', '/api/queue/items/add', ['query' => [
            'clear' => 'true',
            'playback' => 'start',
            'shuffle' => 'false',
            'uris' => $url,
        ], 'timeout' => 12.0]);
        $this->expectStatus($response, [200, 201, 204]);
    }

    /** {@inheritDoc} */
    public function status(string $outputId): array
    {
        $output = $this->output($outputId);
        $player = $this->json('GET', '/api/player');
        $state = $player['state'] ?? null;
        $position = $player['item_progress_ms'] ?? null;
        $duration = $player['item_length_ms'] ?? null;
        if (!in_array($state, ['play', 'pause', 'stop'], true)
            || !is_int($position) || $position < 0 || !is_int($duration) || $duration < 0) {
            throw new AirplayUnavailable('AIRPLAY_PROTOCOL_INVALID', 'OwnTone 播放状态无效。');
        }
        return [
            'state' => $output['selected'] ? $state : 'stop',
            'positionMs' => min($position, $duration > 0 ? $duration : $position),
            'durationMs' => $duration,
            'volume' => $output['volume'],
        ];
    }

    /** {@inheritDoc} */
    public function control(string $outputId, string $operation, ?int $value = null): void
    {
        $output = $this->output($outputId);
        if (!$output['selected'] && $operation !== 'stop') {
            throw new AirplayUnavailable('AIRPLAY_DEVICE_NOT_ACTIVE', 'AirPlay 输出不是当前播放目标。');
        }
        $options = [];
        $path = match ($operation) {
            'pause' => '/api/player/pause',
            'resume' => '/api/player/play',
            'stop' => '/api/player/stop',
            'seek' => '/api/player/seek',
            'volume' => '/api/player/volume',
            default => throw new AirplayUnavailable('AIRPLAY_REQUEST_INVALID', 'AirPlay 控制动作无效。'),
        };
        if ($operation === 'seek') $options['query'] = ['position_ms' => $value];
        if ($operation === 'volume') $options['query'] = ['volume' => $value, 'output_id' => $outputId];
        $this->emptyResponse('PUT', $path, $options);
    }

    /** @return array{id:string,name:string,selected:bool,volume:int,requiresAuth:bool} */
    private function output(string $outputId): array
    {
        if (preg_match('/^[0-9]{1,20}$/D', $outputId) !== 1) {
            throw new AirplayUnavailable('AIRPLAY_DEVICE_INVALID', 'AirPlay 输出标识无效。');
        }
        foreach ($this->outputs() as $output) {
            if ($output['id'] === $outputId) return $output;
        }
        throw new AirplayUnavailable('AIRPLAY_DEVICE_NOT_FOUND', 'AirPlay 输出不存在。');
    }

    /** @return array<string,mixed> */
    private function json(string $method, string $path): array
    {
        $response = $this->request($method, $path);
        $this->expectStatus($response, [200]);
        $body = (string) $response->getBody();
        $payload = strlen($body) <= 262_144 ? json_decode($body, true) : null;
        if (!is_array($payload) || array_is_list($payload)) {
            throw new AirplayUnavailable('AIRPLAY_PROTOCOL_INVALID', 'OwnTone JSON 响应无效。');
        }
        return $payload;
    }

    private function emptyResponse(string $method, string $path, array $options = []): void
    {
        $this->expectStatus($this->request($method, $path, $options), [200, 204]);
    }

    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request($method, $path, $options);
        } catch (Throwable $failure) {
            throw new AirplayUnavailable('AIRPLAY_UNAVAILABLE', 'OwnTone 请求失败。', $failure);
        }
    }

    /** @param list<int> $allowed */
    private function expectStatus(ResponseInterface $response, array $allowed): void
    {
        if (!in_array($response->getStatusCode(), $allowed, true)) {
            throw new AirplayUnavailable(
                $response->getStatusCode() === 404 ? 'AIRPLAY_DEVICE_NOT_FOUND' : 'AIRPLAY_OPERATION_FAILED',
                'OwnTone 操作未成功。',
            );
        }
    }
}
