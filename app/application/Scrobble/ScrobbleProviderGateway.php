<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 把统一投递任务映射到 Last.fm 与 ListenBrainz 协议；Maloja 使用其 ListenBrainz 兼容入口。
 *
 * 固定 Provider 只能请求代码内置官方 HTTPS 地址。Maloja URL 由 ScrobbleEndpointPolicy 解析并通过
 * CURLOPT_RESOLVE 固定公开 IP，禁止重定向、代理继承和 DNS rebinding。所有请求具有较小连接/总超时，
 * 响应正文只在内存中做有界 JSON 判定，绝不写入日志或任务错误字段。调用者负责解密凭据，本类不会缓存。
 */
final readonly class ScrobbleProviderGateway
{
    private ClientInterface $http;

    public function __construct(
        ?ClientInterface $http = null,
        private ScrobbleEndpointPolicy $endpointPolicy = new ScrobbleEndpointPolicy(),
    ) {
        $this->http = $http ?? new Client(['http_errors' => false]);
    }

    /**
     * 执行一次远端投递，成功静默返回；失败只抛出稳定分类和重试属性。
     *
     * @param array<string, mixed> $connection 含 provider、endpoint_url 的内部连接行。
     * @param array<string, string> $credentials 已验证、只在本次调用内存存在的凭据。
     * @param array<string, mixed> $job 不含文件路径的不可变歌曲快照。
     */
    public function deliver(array $connection, array $credentials, array $job): void
    {
        try {
            match ((string) ($connection['provider'] ?? '')) {
                'lastfm' => $this->lastFm($credentials, $job),
                'listenbrainz' => $this->listenBrainz(
                    'https://api.listenbrainz.org/1/submit-listens', $credentials, $job, null,
                ),
                'maloja' => $this->maloja((string) ($connection['endpoint_url'] ?? ''), $credentials, $job),
                default => throw new ScrobbleDeliveryFailure('SCROBBLE_PROVIDER_UNSUPPORTED', false),
            };
        } catch (ScrobbleDeliveryFailure $failure) {
            throw $failure;
        } catch (ScrobbleInvalid) {
            throw new ScrobbleDeliveryFailure('SCROBBLE_ENDPOINT_BLOCKED', false);
        } catch (GuzzleException) {
            throw new ScrobbleDeliveryFailure('SCROBBLE_REMOTE_UNAVAILABLE', true);
        } catch (Throwable) {
            throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_INVALID', true);
        }
    }

    /** 按 Last.fm 2.0 签名规则提交 updateNowPlaying 或 scrobble，format 不参与 api_sig。 */
    private function lastFm(array $credentials, array $job): void
    {
        foreach (['apiKey', 'sharedSecret', 'sessionKey'] as $field) {
            if (!isset($credentials[$field]) || $credentials[$field] === '') {
                throw new ScrobbleDeliveryFailure('SCROBBLE_CREDENTIAL_UNAVAILABLE', false);
            }
        }
        $scrobble = (string) $job['delivery_type'] === 'scrobble';
        $parameters = [
            'method' => $scrobble ? 'track.scrobble' : 'track.updateNowPlaying',
            'artist' => (string) $job['artist_name'], 'track' => (string) $job['song_title'],
            'album' => (string) ($job['album_title'] ?? ''),
            'duration' => (string) max(1, (int) ceil((int) $job['duration_ms'] / 1000)),
            'api_key' => $credentials['apiKey'], 'sk' => $credentials['sessionKey'],
        ];
        if ($scrobble) $parameters['timestamp'] = (string) (new DateTimeImmutable((string) $job['occurred_at']))->getTimestamp();
        ksort($parameters, SORT_STRING);
        $signatureMaterial = '';
        foreach ($parameters as $key => $value) $signatureMaterial .= $key . $value;
        $parameters['api_sig'] = md5($signatureMaterial . $credentials['sharedSecret']);
        $parameters['format'] = 'json';
        $response = $this->request('https://ws.audioscrobbler.com/2.0/', [
            'form_params' => $parameters,
        ]);
        $document = $this->document($response);
        if (isset($document['error'])) {
            $error = (int) $document['error'];
            if (in_array($error, [4, 9, 10, 13, 26], true)) {
                throw new ScrobbleDeliveryFailure('SCROBBLE_AUTH_REJECTED', false);
            }
            if ($error === 29) throw new ScrobbleDeliveryFailure('SCROBBLE_RATE_LIMITED', true);
            throw new ScrobbleDeliveryFailure('SCROBBLE_REMOTE_REJECTED', false);
        }
        if ($scrobble && !isset($document['scrobbles'])) throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_INVALID', true);
        if (!$scrobble && !isset($document['nowplaying'])) throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_INVALID', true);
    }

    /** 使用 Maloja 的 ListenBrainz 兼容 API，同时获得 playing_now 与 single 两种语义。 */
    private function maloja(string $endpointUrl, array $credentials, array $job): void
    {
        if (!isset($credentials['token']) || $credentials['token'] === '') {
            throw new ScrobbleDeliveryFailure('SCROBBLE_CREDENTIAL_UNAVAILABLE', false);
        }
        $normalized = $this->endpointPolicy->normalize($endpointUrl);
        $resolved = $this->endpointPolicy->resolve($normalized);
        if (!extension_loaded('curl') || !defined('CURLOPT_RESOLVE')) {
            throw new ScrobbleDeliveryFailure('SCROBBLE_SECURE_TRANSPORT_UNAVAILABLE', true);
        }
        $ip = str_contains($resolved['ip'], ':') ? '[' . $resolved['ip'] . ']' : $resolved['ip'];
        $curl = [constant('CURLOPT_RESOLVE') => [$resolved['host'] . ':443:' . $ip]];
        $url = rtrim($normalized, '/') . '/apis/listenbrainz/1/submit-listens';
        $this->listenBrainz($url, $credentials, $job, $curl);
    }

    /** @param array<int, mixed>|null $curlOptions */
    private function listenBrainz(string $url, array $credentials, array $job, ?array $curlOptions): void
    {
        if (!isset($credentials['token']) || $credentials['token'] === '') {
            throw new ScrobbleDeliveryFailure('SCROBBLE_CREDENTIAL_UNAVAILABLE', false);
        }
        $isScrobble = (string) $job['delivery_type'] === 'scrobble';
        $listen = [
            'track_metadata' => [
                'artist_name' => (string) $job['artist_name'],
                'track_name' => (string) $job['song_title'],
                'release_name' => $job['album_title'] ?? null,
                'additional_info' => [
                    'duration_ms' => (int) $job['duration_ms'],
                    'submission_client' => 'Velin Music',
                ],
            ],
        ];
        if ($isScrobble) $listen['listened_at'] = (new DateTimeImmutable((string) $job['occurred_at']))->getTimestamp();
        $options = [
            'headers' => ['Authorization' => 'Token ' . $credentials['token'], 'Accept' => 'application/json'],
            'json' => ['listen_type' => $isScrobble ? 'single' : 'playing_now', 'payload' => [$listen]],
        ];
        if ($curlOptions !== null) $options['curl'] = $curlOptions;
        $response = $this->request($url, $options);
        $document = $this->document($response);
        $status = strtolower((string) ($document['status'] ?? ''));
        if (!in_array($status, ['ok', 'success'], true)) {
            throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_INVALID', true);
        }
    }

    /**
     * 统一禁用重定向和环境代理，并在读取正文前按 HTTP 状态分类。
     *
     * @param array<string, mixed> $options
     */
    private function request(string $url, array $options): ResponseInterface
    {
        $response = $this->http->request('POST', $url, $options + [
            'allow_redirects' => false, 'connect_timeout' => 3.0, 'timeout' => 8.0,
            'proxy' => '', 'http_errors' => false, 'headers' => ['Accept' => 'application/json'],
        ]);
        $status = $response->getStatusCode();
        if (in_array($status, [401, 403], true)) throw new ScrobbleDeliveryFailure('SCROBBLE_AUTH_REJECTED', false);
        if ($status === 429) throw new ScrobbleDeliveryFailure('SCROBBLE_RATE_LIMITED', true);
        if ($status >= 500) throw new ScrobbleDeliveryFailure('SCROBBLE_REMOTE_UNAVAILABLE', true);
        if ($status >= 300 && $status < 400) throw new ScrobbleDeliveryFailure('SCROBBLE_REDIRECT_REJECTED', false);
        if ($status < 200 || $status >= 300) throw new ScrobbleDeliveryFailure('SCROBBLE_REMOTE_REJECTED', false);
        return $response;
    }

    /** @return array<string, mixed> 只解析最多 64 KiB 的对象响应，禁止把正文带入异常。 */
    private function document(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if (strlen($body) > 65_536) throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_TOO_LARGE', false);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new ScrobbleDeliveryFailure('SCROBBLE_RESPONSE_INVALID', true);
        return $decoded;
    }
}
