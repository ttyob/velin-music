<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/**
 * 使用用途隔离的 Sodium secretbox 保存 WebDAV 密码。
 *
 * 根密钥来自部署级 `VELIN_CREDENTIAL_KEY`，再经 HKDF 派生 WebDAV 专用子密钥，不能与 Subsonic 或
 * Scrobble 密文互换。明文只允许在管理请求校验和单次 WebDAV 调用内存中存在；不得写日志、审计、
 * 任务、缓存文件名或 API 响应。密文被篡改、密钥轮换或格式未知时统一失败关闭。
 */
final readonly class WebDavCredentialCipher
{
    private string $key;

    /** 测试可注入根密钥；生产必须提供至少 16 字节的独立部署密钥。 */
    public function __construct(?string $deploymentSecret = null)
    {
        $root = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        $this->key = hash_hkdf(
            'sha256',
            $root,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'velin-webdav-credentials-v1',
        );
    }

    /** 加密已通过长度校验的密码；每次调用使用随机 nonce，因此相同密码不会产生相同数据库值。 */
    public function encrypt(string $password): string
    {
        if ($password === '' || strlen($password) > 1024) {
            throw new RuntimeException('WebDAV credential is outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($password, $nonce, $this->key);
        return 'v1:' . base64_encode($nonce . $ciphertext);
    }

    /** 解密并认证密码；调用方使用后应尽快 `sodium_memzero()`，不得长期缓存返回值。 */
    public function decrypt(?string $payload): string
    {
        if (!is_string($payload) || !str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('WebDAV credential is unavailable.');
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('WebDAV credential is unavailable.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key,
        );
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > 1024) {
            throw new RuntimeException('WebDAV credential is unavailable.');
        }
        return $plaintext;
    }
}
