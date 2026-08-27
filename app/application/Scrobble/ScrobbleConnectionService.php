<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * 管理当前账号的 Last.fm、ListenBrainz 与 Maloja 连接，并只输出脱敏投影。
 *
 * 每个 Provider 每账号最多一个连接。写入必须携带 expectedVersion，重新授权通过覆盖加密凭据完成；省略
 * credentials 表示仅修改启用状态、用户名或端点，永远不会回读旧秘密。禁用或删除连接时，同一短事务
 * 取消尚未发送的 queued 任务；running 任务会在 Worker 发请求前再次检查连接状态。服务不测试远端网络，
 * 因此用户保存配置不会让 HTTP 请求进程等待第三方。
 */
final readonly class ScrobbleConnectionService
{
    private const PROVIDERS = ['lastfm', 'listenbrainz', 'maloja'];

    public function __construct(
        private ScrobbleCredentialCipher $cipher = new ScrobbleCredentialCipher(),
        private ScrobbleEndpointPolicy $endpointPolicy = new ScrobbleEndpointPolicy(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 返回三个固定 Provider 的配置状态、队列计数和最近五次最终失败。
     *
     * 查询严格使用 actor ID 过滤；响应不含密文、token 摘要、歌曲名称、端点解析 IP 或远端正文。
     * 未配置 Provider 也会返回占位项，使前端无需猜测服务端支持列表。
     *
     * @param array<string, mixed> $actor 已验证 Web Session 身份。
     * @return array{connections: list<array<string, mixed>>}
     */
    public function snapshot(array $actor): array
    {
        $userId = (string) ($actor['id'] ?? '');
        /** @var list<stdClass> $rows */
        $rows = Db::table('scrobble_connections')->where('user_id', $userId)->orderBy('provider')->get([
            'id', 'provider', 'username', 'endpoint_url', 'enabled', 'version', 'last_success_at',
            'last_failure_at', 'last_error_code', 'created_at', 'updated_at',
        ])->all();
        $byProvider = [];
        foreach ($rows as $row) $byProvider[(string) $row->provider] = $row;
        $connections = [];
        foreach (self::PROVIDERS as $provider) {
            $row = $byProvider[$provider] ?? null;
            $connections[] = $row instanceof stdClass
                ? $this->project($row, $userId)
                : [
                    'provider' => $provider, 'configured' => false, 'enabled' => false, 'username' => null,
                    'endpointUrl' => null, 'version' => 0, 'lastSuccessAt' => null, 'lastFailureAt' => null,
                    'lastErrorCode' => null, 'pendingCount' => 0, 'recentFailures' => [],
                ];
        }

        return ['connections' => $connections];
    }

    /**
     * 创建或版本化更新一个账号连接；credentials 只在创建或重新授权时接收并立即加密。
     *
     * @param array<string, mixed> $actor 当前 Session 账号，不能由请求正文替换。
     * @param array<string, mixed> $payload 只接受 enabled、username、endpointUrl、credentials、expectedVersion。
     * @return array<string, mixed> 更新后的脱敏连接。
     */
    public function save(array $actor, string $provider, array $payload, string $requestId): array
    {
        $provider = $this->provider($provider);
        $expectedVersion = $this->integer($payload['expectedVersion'] ?? null, 0, 2_147_483_647, 'expectedVersion');
        $enabled = $this->boolean($payload['enabled'] ?? true, 'enabled');
        $username = $this->optionalText($payload['username'] ?? null, 160, 'username');
        $endpointUrl = $provider === 'maloja'
            ? $this->endpointPolicy->normalize((string) ($payload['endpointUrl'] ?? ''))
            : null;
        $credentials = array_key_exists('credentials', $payload)
            ? $this->credentials($provider, $payload['credentials'])
            : null;
        $ciphertext = $credentials === null ? null : $this->cipher->encrypt($credentials);
        $userId = (string) ($actor['id'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s\Z');

        Db::transaction(function () use ($userId, $provider, $expectedVersion, $enabled, $username, $endpointUrl, $ciphertext, $now, $requestId): void {
            /** @var stdClass|null $existing */
            $existing = Db::table('scrobble_connections')->where('user_id', $userId)
                ->where('provider', $provider)->first(['id', 'version']);
            if (!$existing instanceof stdClass) {
                if ($expectedVersion !== 0 || $ciphertext === null) {
                    throw new ScrobbleConflict('连接快照已变化，请刷新后重试。');
                }
                $connectionId = (string) new Ulid();
                Db::table('scrobble_connections')->insert([
                    'id' => $connectionId, 'user_id' => $userId, 'provider' => $provider,
                    'username' => $username, 'endpoint_url' => $endpointUrl,
                    'credentials_ciphertext' => $ciphertext, 'enabled' => $enabled ? 1 : 0,
                    'version' => 1, 'last_success_at' => null, 'last_failure_at' => null,
                    'last_error_code' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $action = 'scrobble_connection.create';
            } else {
                $connectionId = (string) $existing->id;
                if ((int) $existing->version !== $expectedVersion) {
                    throw new ScrobbleConflict('连接快照已变化，请刷新后重试。');
                }
                $values = [
                    'username' => $username, 'endpoint_url' => $endpointUrl,
                    'enabled' => $enabled ? 1 : 0, 'version' => $expectedVersion + 1, 'updated_at' => $now,
                ];
                if ($ciphertext !== null) $values['credentials_ciphertext'] = $ciphertext;
                $changed = Db::table('scrobble_connections')->where('id', $connectionId)
                    ->where('version', $expectedVersion)->update($values);
                if ($changed !== 1) throw new ScrobbleConflict('连接快照已变化，请刷新后重试。');
                $action = $ciphertext === null ? 'scrobble_connection.update' : 'scrobble_connection.reauthorize';
            }
            if (!$enabled) {
                Db::table('scrobble_delivery_jobs')->where('connection_id', $connectionId)
                    ->where('status', 'queued')->update([
                        'status' => 'cancelled', 'completed_at' => $now,
                        'error_code' => 'SCROBBLE_CONNECTION_DISABLED', 'updated_at' => $now,
                    ]);
            }
            $this->audit->record($userId, $action, 'scrobble_connection', $connectionId, 'success', $requestId, [
                'provider' => $provider, 'enabled' => $enabled, 'credentials_replaced' => $ciphertext !== null,
            ]);
        });

        return $this->connectionProjection($userId, $provider);
    }

    /** 删除当前账号指定 Provider；级联任务删除，不影响内部播放历史和统计。 */
    public function delete(array $actor, string $provider, string $requestId): void
    {
        $provider = $this->provider($provider);
        $userId = (string) ($actor['id'] ?? '');
        Db::transaction(function () use ($provider, $userId, $requestId): void {
            /** @var stdClass|null $row */
            $row = Db::table('scrobble_connections')->where('user_id', $userId)
                ->where('provider', $provider)->first(['id']);
            if (!$row instanceof stdClass) return;
            Db::table('scrobble_connections')->where('id', (string) $row->id)->delete();
            $this->audit->record($userId, 'scrobble_connection.delete', 'scrobble_connection',
                (string) $row->id, 'success', $requestId, ['provider' => $provider]);
        });
    }

    /** @return array<string, mixed> */
    private function connectionProjection(string $userId, string $provider): array
    {
        /** @var stdClass|null $row */
        $row = Db::table('scrobble_connections')->where('user_id', $userId)
            ->where('provider', $provider)->first([
                'id', 'provider', 'username', 'endpoint_url', 'enabled', 'version', 'last_success_at',
                'last_failure_at', 'last_error_code', 'created_at', 'updated_at',
            ]);
        if (!$row instanceof stdClass) throw new ScrobbleInvalid('连接不存在。');

        return $this->project($row, $userId);
    }

    /** @return array<string, mixed> */
    private function project(stdClass $row, string $userId): array
    {
        $connectionId = (string) $row->id;
        $pending = Db::table('scrobble_delivery_jobs')->where('user_id', $userId)
            ->where('connection_id', $connectionId)->whereIn('status', ['queued', 'running'])->count();
        /** @var list<stdClass> $failures */
        $failures = Db::table('scrobble_delivery_jobs')->where('user_id', $userId)
            ->where('connection_id', $connectionId)->where('status', 'failed')
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(5)
            ->get(['delivery_type', 'attempt_count', 'error_code', 'completed_at'])->all();

        return [
            'provider' => (string) $row->provider, 'configured' => true,
            'enabled' => (int) $row->enabled === 1,
            'username' => $row->username === null ? null : (string) $row->username,
            'endpointUrl' => $row->endpoint_url === null ? null : (string) $row->endpoint_url,
            'version' => (int) $row->version,
            'lastSuccessAt' => $row->last_success_at === null ? null : (string) $row->last_success_at,
            'lastFailureAt' => $row->last_failure_at === null ? null : (string) $row->last_failure_at,
            'lastErrorCode' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            'pendingCount' => (int) $pending,
            'recentFailures' => array_map(static fn (stdClass $failure): array => [
                'type' => (string) $failure->delivery_type,
                'attempts' => (int) $failure->attempt_count,
                'errorCode' => (string) $failure->error_code,
                'failedAt' => (string) $failure->completed_at,
            ], $failures),
        ];
    }

    private function provider(string $provider): string
    {
        if (!in_array($provider, self::PROVIDERS, true)) throw new ScrobbleInvalid('不支持的 Scrobble 平台。');
        return $provider;
    }

    /** @return array<string, string> */
    private function credentials(string $provider, mixed $value): array
    {
        if (!is_array($value)) throw new ScrobbleInvalid('连接凭据无效。');
        $fields = match ($provider) {
            'lastfm' => ['apiKey', 'sharedSecret', 'sessionKey'],
            'listenbrainz', 'maloja' => ['token'],
        };
        if (array_values(array_keys($value)) !== $fields && array_diff(array_keys($value), $fields) !== []) {
            throw new ScrobbleInvalid('连接凭据包含未知字段。');
        }
        $result = [];
        foreach ($fields as $field) {
            $text = $value[$field] ?? null;
            if (!is_string($text) || ($text = trim($text)) === '' || strlen($text) > 1024) {
                throw new ScrobbleInvalid('连接凭据无效。');
            }
            $result[$field] = $text;
        }
        return $result;
    }

    private function optionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || strlen($value) > $max || trim($value) === '') {
            throw new ScrobbleInvalid($field . ' 无效。');
        }
        return trim($value);
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) throw new ScrobbleInvalid($field . ' 无效。');
        return $value;
    }

    private function integer(mixed $value, int $min, int $max, string $field): int
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new ScrobbleInvalid($field . ' 无效。');
        return $value;
    }
}
