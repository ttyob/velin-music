<?php

declare(strict_types=1);

namespace app\application\System;

use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 管理可由刮削渠道和网络音乐库复用的命名代理连接。
 *
 * 列表和管理响应永不包含密码或密文。运行时只能通过 profile ID 取连接，且被引用 profile 停用、损坏
 * 或解密失败时必须失败关闭，避免管理员以为请求走代理而实际直连。服务不测试网络；写操作使用短事务、
 * 乐观版本锁和脱敏审计，删除前同时检查渠道与音乐库引用。
 */
final readonly class NetworkProxyProfileService
{
    private const SCHEMES = ['http', 'https', 'socks5'];

    public function __construct(
        private NetworkProxyCredentialCipher $cipher = new NetworkProxyCredentialCipher(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /** @return list<array<string,mixed>> 返回全部脱敏 profile，读取不访问网络。 */
    public function catalog(): array
    {
        try {
            /** @var list<stdClass> $rows */
            $rows = Db::table('network_proxy_profiles')->orderBy('name')->get()->all();
        } catch (QueryException $exception) {
            throw new NetworkProxyProfileUnavailable('代理目录不可用。', previous: $exception);
        }
        return array_map(fn (stdClass $row): array => $this->snapshot($row), $rows);
    }

    /**
     * 创建命名代理；密码只在本次调用内加密，事务失败不会留下 profile 或审计记录。
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function create(array $command, string $actorId, string $requestId): array
    {
        $normalized = $this->validate($command, true, null);
        $id = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            return Db::transaction(function () use ($normalized, $id, $now, $actorId, $requestId): array {
                Db::table('network_proxy_profiles')->insert($normalized + [
                    'id' => $id, 'version' => 1, 'created_by' => $actorId, 'updated_by' => $actorId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record($actorId, 'network_proxy.create', 'network_proxy_profile', $id,
                    'success', $requestId, ['enabled' => (bool) $normalized['enabled'], 'scheme' => $normalized['scheme']]);
                return $this->find($id);
            });
        } catch (QueryException $exception) {
            throw new NetworkProxyProfileConflict('代理名称已存在。', previous: $exception);
        }
    }

    /**
     * 使用 expectedVersion 原子替换可编辑字段；省略 password 保留密文，显式空值只可与空用户名一起清除。
     *
     * @param array<string,mixed> $command
     * @return array<string,mixed>
     */
    public function update(string $id, array $command, string $actorId, string $requestId): array
    {
        $before = $this->row($id);
        $expectedVersion = $command['expectedVersion'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 1) throw new NetworkProxyProfileInvalid();
        $normalized = $this->validate($command, false, $before);
        try {
            return Db::transaction(function () use ($id, $expectedVersion, $normalized, $actorId, $requestId): array {
                $changed = Db::table('network_proxy_profiles')->where('id', $id)->where('version', $expectedVersion)
                    ->update($normalized + [
                        'version' => Db::raw('version + 1'), 'updated_by' => $actorId,
                        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
                if ($changed !== 1) throw new NetworkProxyProfileConflict('代理配置已变化，请刷新后重试。');
                $this->audit->record($actorId, 'network_proxy.update', 'network_proxy_profile', $id,
                    'success', $requestId, ['enabled' => (bool) $normalized['enabled'], 'scheme' => $normalized['scheme']]);
                return $this->find($id);
            });
        } catch (QueryException $exception) {
            throw new NetworkProxyProfileConflict('代理名称已存在。', previous: $exception);
        }
    }

    /**
     * 删除未被引用的 profile。检查与删除位于同一短事务；外键是并发兜底，失败不级联修改渠道或音乐库。
     */
    public function delete(string $id, int $expectedVersion, string $actorId, string $requestId): void
    {
        if ($expectedVersion < 1) throw new NetworkProxyProfileInvalid();
        Db::transaction(function () use ($id, $expectedVersion, $actorId, $requestId): void {
            $this->row($id);
            if (Db::table('music_sources')->where('proxy_profile_id', $id)->exists()
                || Db::table('music_libraries')->where('proxy_profile_id', $id)->exists()
                || Db::table('onedrive_device_authorizations')->where('proxy_profile_id', $id)->exists()
                || Db::table('google_drive_authorizations')->where('proxy_profile_id', $id)->exists()) {
                throw new NetworkProxyProfileInUse('代理仍被数据源或音乐库使用。');
            }
            $changed = Db::table('network_proxy_profiles')->where('id', $id)->where('version', $expectedVersion)->delete();
            if ($changed !== 1) throw new NetworkProxyProfileConflict('代理配置已变化，请刷新后重试。');
            $this->audit->record($actorId, 'network_proxy.delete', 'network_proxy_profile', $id,
                'success', $requestId);
        });
    }

    /**
     * 为一次受控出站请求解密指定连接。
     *
     * profile 必须存在且启用；返回密码只可进入 Guzzle 单次 `proxy` 选项或 helper stdin，调用方应在
     * finally 中清零。任何配置或密文异常都抛出，不返回 null，也不读取进程环境代理。
     *
     * @return array{scheme:string,host:string,port:int,username:string,password:string}
     */
    public function connection(string $id): array
    {
        $row = $this->row($id);
        if ((int) $row->enabled !== 1) throw new NetworkProxyProfileUnavailable('所选代理已停用。');
        try {
            $host = $this->normalizeHost((string) $row->host);
            $password = $row->password_ciphertext === null ? '' : $this->cipher->decrypt((string) $row->password_ciphertext);
        } catch (RuntimeException) {
            throw new NetworkProxyProfileUnavailable('所选代理凭据不可用。');
        }
        if ($host === '' || !in_array((string) $row->scheme, self::SCHEMES, true)) {
            throw new NetworkProxyProfileUnavailable('所选代理配置不可用。');
        }
        return ['scheme' => (string) $row->scheme, 'host' => $host, 'port' => (int) $row->port,
            'username' => (string) $row->username, 'password' => $password];
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->snapshot($this->row($id));
    }

    private function row(string $id): stdClass
    {
        if (!Ulid::isValid($id)) throw new NetworkProxyProfileNotFound();
        $row = Db::table('network_proxy_profiles')->where('id', $id)->first();
        if (!$row instanceof stdClass) throw new NetworkProxyProfileNotFound();
        return $row;
    }

    /** @return array<string,mixed> */
    private function snapshot(stdClass $row): array
    {
        return ['id' => (string) $row->id, 'name' => (string) $row->name,
            'enabled' => (int) $row->enabled === 1, 'scheme' => (string) $row->scheme,
            'host' => (string) $row->host, 'port' => (int) $row->port, 'username' => (string) $row->username,
            'passwordConfigured' => $row->password_ciphertext !== null, 'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at];
    }

    /** @param array<string,mixed> $command @return array<string,mixed> */
    private function validate(array $command, bool $creating, ?stdClass $before): array
    {
        $allowed = ['name', 'enabled', 'scheme', 'host', 'port', 'username', 'password', 'expectedVersion'];
        if (array_is_list($command) || array_diff(array_keys($command), $allowed) !== []) throw new NetworkProxyProfileInvalid();
        foreach (['name', 'scheme', 'host', 'username'] as $field) {
            if (!is_string($command[$field] ?? null)) throw new NetworkProxyProfileInvalid();
        }
        if (!is_bool($command['enabled'] ?? null) || !is_int($command['port'] ?? null)) throw new NetworkProxyProfileInvalid();
        if ($creating && (!array_key_exists('password', $command) || array_key_exists('expectedVersion', $command))) {
            throw new NetworkProxyProfileInvalid();
        }
        $name = trim($command['name']);
        $scheme = strtolower(trim($command['scheme']));
        $host = $this->normalizeHost($command['host']);
        $username = trim($command['username']);
        if ($name === '' || mb_strlen($name) > 80 || !in_array($scheme, self::SCHEMES, true)
            || $command['port'] < 1 || $command['port'] > 65535 || strlen($username) > 255
            || preg_match('/[\x00-\x20\x7F:@\/]/', $username) === 1 || ($command['enabled'] && $host === '')) {
            throw new NetworkProxyProfileInvalid();
        }
        $ciphertext = $before?->password_ciphertext;
        if (array_key_exists('password', $command)) {
            if (!is_string($command['password']) || strlen($command['password']) > 1024) throw new NetworkProxyProfileInvalid();
            $password = $command['password'];
            try {
                $ciphertext = $password === '' ? null : $this->cipher->encrypt($password);
            } finally {
                if ($password !== '') sodium_memzero($password);
            }
        }
        if (($username === '') !== ($ciphertext === null)) throw new NetworkProxyProfileInvalid();
        return ['name' => $name, 'enabled' => $command['enabled'] ? 1 : 0, 'scheme' => $scheme,
            'host' => $host, 'port' => $command['port'], 'username' => $username,
            'password_ciphertext' => $ciphertext];
    }

    /** 校验单一 DNS/IP 主机；允许管理员明确使用私网代理，但拒绝 URL、端口、路径和控制字符。 */
    private function normalizeHost(string $input): string
    {
        $host = strtolower(rtrim(trim($input), '.'));
        if ($host === '') return '';
        if (strlen($host) > 253 || preg_match('/[\x00-\x20\x7F\/@?#]/', $host) === 1) throw new NetworkProxyProfileInvalid();
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $host;
        if (str_contains($host, ':') || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            throw new NetworkProxyProfileInvalid();
        }
        return $host;
    }
}

/** profile 输入、主机、端口或凭据组合无效。 */
final class NetworkProxyProfileInvalid extends RuntimeException {}
/** profile 不存在或 ID 格式无效。 */
final class NetworkProxyProfileNotFound extends RuntimeException {}
/** profile 版本或唯一名称冲突。 */
final class NetworkProxyProfileConflict extends RuntimeException {}
/** profile 仍被出站策略引用，不能删除。 */
final class NetworkProxyProfileInUse extends RuntimeException {}
/** profile 已停用、损坏、缺失迁移或凭据无法解密。 */
final class NetworkProxyProfileUnavailable extends RuntimeException {}
