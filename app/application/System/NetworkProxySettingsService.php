<?php

declare(strict_types=1);

namespace app\application\System;

use app\infrastructure\Audit\AuditLogger;
use JsonException;
use RuntimeException;
use stdClass;
use support\Db;

/**
 * 管理固定数据源可选择使用的全局出站代理。
 *
 * 配置保存在独立版本化 `network.proxy` 对象中，密码使用用途隔离密文。后台读取永不解密或回显密码；
 * 运行时连接只提供给受控数据源 helper 和封面下载器。管理员可配置局域网代理，因此主机允许私网 IP，
 * 但协议、主机、端口和凭据均严格收窄，且该配置不能改变 WebDAV、Last.fm、Jackett 等其他客户端。
 */
final readonly class NetworkProxySettingsService
{
    private const KEY = 'network.proxy';
    private const SCHEMES = ['http', 'https', 'socks5'];

    public function __construct(
        private NetworkProxyCredentialCipher $cipher = new NetworkProxyCredentialCipher(),
        private AuditLogger $audit = new AuditLogger(),
    ) {
    }

    /**
     * 返回后台表单所需的脱敏快照，不读取密码明文且没有网络或写库副作用。
     *
     * 缺行、损坏 JSON、非法主机或异常密文字段表示迁移/数据故障并失败关闭；`passwordConfigured` 只
     * 表达是否存在密文，不泄露密码长度、版本或内容。
     *
     * @return array{enabled:bool,scheme:string,host:string,port:int,username:string,passwordConfigured:bool,version:int,updatedAt:string}
     */
    public function snapshot(): array
    {
        $setting = $this->setting();
        return [
            'enabled' => $setting['enabled'],
            'scheme' => $setting['scheme'],
            'host' => $setting['host'],
            'port' => $setting['port'],
            'username' => $setting['username'],
            'passwordConfigured' => is_string($setting['passwordCiphertext']),
            'version' => $setting['version'],
            'updatedAt' => $setting['updatedAt'],
        ];
    }

    /**
     * 为一次受控数据源请求返回代理连接；全局关闭时返回 null。
     *
     * 返回密码只能进入 helper 标准输入或 Guzzle 的单次 `proxy` 选项，调用方必须在 finally 中清理。
     * 启用配置缺少主机或密文无法认证时抛出不可用异常，不能静默直连造成管理员误判流量边界。
     *
     * @return array{scheme:string,host:string,port:int,username:string,password:string}|null
     */
    public function connection(): ?array
    {
        $setting = $this->setting();
        if (!$setting['enabled']) {
            return null;
        }
        $password = '';
        if (is_string($setting['passwordCiphertext'])) {
            try {
                $password = $this->cipher->decrypt($setting['passwordCiphertext']);
            } catch (RuntimeException) {
                throw new NetworkProxySettingsUnavailable();
            }
        }
        return [
            'scheme' => $setting['scheme'],
            'host' => $setting['host'],
            'port' => $setting['port'],
            'username' => $setting['username'],
            'password' => $password,
        ];
    }

    /**
     * 原子保存代理配置并写入脱敏审计。
     *
     * `password` 省略时保留旧密文；输入新值时替换，用户名和显式空密码同时提交时清除认证。启用时
     * 要求有效主机和端口，用户名与密码必须同时存在或同时为空。保存事务不访问 DNS、代理或任何数据源，CAS 失败不会产生部分设置
     * 或审计记录。审计只记录布尔变化和协议，不保存主机、用户名、密码或密文。
     *
     * @return array{enabled:bool,scheme:string,host:string,port:int,username:string,passwordConfigured:bool,version:int,updatedAt:string}
     */
    public function update(array $command, string $actorId, string $requestId): array
    {
        return Db::transaction(function () use ($command, $actorId, $requestId): array {
            $before = $this->setting();
            $allowed = ['enabled', 'scheme', 'host', 'port', 'username', 'expectedVersion', 'password'];
            $requiredCount = array_key_exists('password', $command) ? 7 : 6;
            if (array_is_list($command) || array_diff(array_keys($command), $allowed) !== []
                || count($command) !== $requiredCount || !is_bool($command['enabled'] ?? null)
                || !is_string($command['scheme'] ?? null) || !is_string($command['host'] ?? null)
                || !is_int($command['port'] ?? null) || !is_string($command['username'] ?? null)
                || !is_int($command['expectedVersion'] ?? null)) {
                throw new NetworkProxySettingsInvalid();
            }
            if ($command['expectedVersion'] !== $before['version']) {
                throw new NetworkProxySettingsConflict();
            }
            $scheme = strtolower(trim($command['scheme']));
            $host = $this->normalizeHost($command['host']);
            $username = trim($command['username']);
            if (!in_array($scheme, self::SCHEMES, true) || $command['port'] < 1 || $command['port'] > 65535
                || strlen($username) > 255 || preg_match('/[\x00-\x20\x7F:@\/]/', $username) === 1
                || ($command['enabled'] && $host === '')) {
                throw new NetworkProxySettingsInvalid();
            }
            $ciphertext = $before['passwordCiphertext'];
            if (array_key_exists('password', $command)) {
                if (!is_string($command['password'])) {
                    throw new NetworkProxySettingsInvalid();
                }
                $password = $command['password'];
                if ($password === '' && $username === '') {
                    $ciphertext = null;
                } elseif ($password !== '') {
                    try {
                        $ciphertext = $this->cipher->encrypt($password);
                    } catch (RuntimeException) {
                        throw new NetworkProxySettingsInvalid();
                    } finally {
                        sodium_memzero($password);
                    }
                }
            }
            if (($username === '') !== ($ciphertext === null)) {
                throw new NetworkProxySettingsInvalid();
            }
            $value = [
                'enabled' => $command['enabled'], 'scheme' => $scheme, 'host' => $host,
                'port' => $command['port'], 'username' => $username, 'passwordCiphertext' => $ciphertext,
            ];
            $changed = Db::table('system_settings')->where('setting_key', self::KEY)
                ->where('version', $command['expectedVersion'])->update([
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'version' => Db::raw('version + 1'),
                    'updated_by' => $actorId,
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            if ($changed !== 1) {
                throw new NetworkProxySettingsConflict();
            }
            $this->audit->record($actorId, 'network.proxy.update', 'system_setting', self::KEY, 'success', $requestId, [
                'enabled' => $command['enabled'],
                'scheme' => $scheme,
                'host_changed' => $host !== $before['host'],
                'credential_changed' => array_key_exists('password', $command),
                'version' => $command['expectedVersion'] + 1,
            ]);
            return $this->snapshot();
        });
    }

    /** @return array{enabled:bool,scheme:string,host:string,port:int,username:string,passwordCiphertext:?string,version:int,updatedAt:string} */
    private function setting(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::KEY)->first();
        if (!$row instanceof stdClass) {
            throw new NetworkProxySettingsUnavailable();
        }
        try {
            $value = json_decode((string) $row->value_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NetworkProxySettingsUnavailable();
        }
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), ['enabled', 'scheme', 'host', 'port', 'username', 'passwordCiphertext']) !== []
            || count($value) !== 6 || !is_bool($value['enabled'] ?? null)
            || !is_string($value['scheme'] ?? null) || !is_string($value['host'] ?? null)
            || !is_int($value['port'] ?? null) || !is_string($value['username'] ?? null)
            || (!is_null($value['passwordCiphertext'] ?? null) && !is_string($value['passwordCiphertext']))) {
            throw new NetworkProxySettingsUnavailable();
        }
        try {
            $host = $this->normalizeHost($value['host']);
        } catch (NetworkProxySettingsInvalid) {
            throw new NetworkProxySettingsUnavailable();
        }
        if (!in_array($value['scheme'], self::SCHEMES, true) || $value['port'] < 1 || $value['port'] > 65535
            || strlen($value['username']) > 255 || preg_match('/[\x00-\x20\x7F:@\/]/', $value['username']) === 1
            || ($value['enabled'] && $host === '')
            || (($value['username'] === '') !== ($value['passwordCiphertext'] === null))) {
            throw new NetworkProxySettingsUnavailable();
        }
        return [
            'enabled' => $value['enabled'], 'scheme' => $value['scheme'], 'host' => $host,
            'port' => $value['port'], 'username' => $value['username'],
            'passwordCiphertext' => $value['passwordCiphertext'], 'version' => (int) $row->version,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /** 校验代理主机为单一 DNS 名或 IP；私网地址被允许，但路径、端口、URL 和控制字符被拒绝。 */
    private function normalizeHost(string $input): string
    {
        $host = strtolower(rtrim(trim($input), '.'));
        if ($host === '') return '';
        if (strlen($host) > 253 || preg_match('/[\x00-\x20\x7F\/@?#]/', $host) === 1) {
            throw new NetworkProxySettingsInvalid();
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return $host;
        if (str_contains($host, ':')) throw new NetworkProxySettingsInvalid();
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            throw new NetworkProxySettingsInvalid();
        }
        return $host;
    }
}

/** 代理设置请求字段、主机、端口或凭据不符合固定契约。 */
final class NetworkProxySettingsInvalid extends \RuntimeException {}

/** 代理设置的 expectedVersion 已过期，命令未写入。 */
final class NetworkProxySettingsConflict extends \RuntimeException {}

/** 代理设置缺失、损坏或凭据无法解密。 */
final class NetworkProxySettingsUnavailable extends \RuntimeException {}
