<?php

declare(strict_types=1);

namespace app\application\Scrobble;

use JsonException;
use RuntimeException;

/**
 * 使用用途隔离的 Sodium secretbox 加密每个账号的第三方 Scrobble 凭据。
 *
 * 根密钥来自部署级 VELIN_CREDENTIAL_KEY，但通过 HKDF 派生独立子密钥，不能与 Subsonic 密码密文或
 * 分享签名互换。每次保存使用随机 nonce，SQLite 和在线备份只得到带认证的版本化密文；解密失败统一
 * 返回不可用错误，避免通过异常差异判断密钥、格式或篡改原因。明文 JSON 只允许在连接写入和单次投递
 * 内存中存在，不得进入日志、审计元数据、响应或任务表。
 */
final readonly class ScrobbleCredentialCipher
{
    private string $key;

    /** 测试可显式传入根密钥；生产根密钥至少 16 字节并由 Compose Secret/环境变量提供。 */
    public function __construct(?string $deploymentSecret = null)
    {
        $root = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) {
            throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        }
        $this->key = hash_hkdf('sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-scrobble-credentials-v1');
    }

    /**
     * 加密已经完成 Provider 级校验的字符串字段。
     *
     * @param array<string, string> $credentials 不得包含空值、显示名称或自定义 URL。
     * @throws JsonException 编码失败时不产生可持久化的半成品。
     */
    public function encrypt(array $credentials): string
    {
        if ($credentials === [] || count($credentials) > 8) {
            throw new RuntimeException('Scrobble credentials are outside the supported boundary.');
        }
        $plaintext = json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($plaintext) > 4096) {
            sodium_memzero($plaintext);
            throw new RuntimeException('Scrobble credentials are outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        sodium_memzero($plaintext);

        return 'v1:' . base64_encode($nonce . $ciphertext);
    }

    /**
     * 验证并解密一份版本化凭据；返回后由调用方负责尽快释放，不允许缓存或序列化。
     *
     * @return array<string, string>
     */
    public function decrypt(?string $payload): array
    {
        if (!is_string($payload) || !str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Scrobble credential is unavailable.');
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Scrobble credential is unavailable.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Scrobble credential is unavailable.');
        }
        try {
            $decodedCredentials = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Scrobble credential is unavailable.', 0, $exception);
        } finally {
            sodium_memzero($plaintext);
        }
        if (!is_array($decodedCredentials) || $decodedCredentials === []) {
            throw new RuntimeException('Scrobble credential is unavailable.');
        }
        $result = [];
        foreach ($decodedCredentials as $key => $value) {
            if (!is_string($key) || !is_string($value) || $key === '' || $value === '') {
                throw new RuntimeException('Scrobble credential is unavailable.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
