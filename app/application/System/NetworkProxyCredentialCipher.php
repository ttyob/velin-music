<?php

declare(strict_types=1);

namespace app\application\System;

use RuntimeException;

/**
 * 使用用途隔离的 Sodium secretbox 保存系统出站代理密码。
 *
 * 根密钥来自部署级 `VELIN_CREDENTIAL_KEY`，并通过独立 HKDF 上下文派生代理专用子密钥，代理密码密文
 * 不能与 WebDAV、Jackett 或其他凭据互换。明文只允许存在于保存校验、单次 helper 标准输入或 HTTP
 * 请求调用栈；不得进入 API、命令行、日志、审计或异常记录。密文损坏、版本未知或密钥轮换后统一失败
 * 关闭，不回退为空密码发起请求。
 */
final readonly class NetworkProxyCredentialCipher
{
    private string $key;

    /** 测试可注入根密钥；生产使用至少 16 字节的独立部署密钥。 */
    public function __construct(?string $deploymentSecret = null)
    {
        $root = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) {
            throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        }
        $this->key = hash_hkdf(
            'sha256',
            $root,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'velin-network-proxy-credentials-v1',
        );
    }

    /** 加密已通过长度校验的密码；随机 nonce 保证相同密码不会生成相同数据库值。 */
    public function encrypt(string $password): string
    {
        if ($password === '' || strlen($password) > 1024 || preg_match('/[\x00\r\n]/', $password) === 1) {
            throw new RuntimeException('Network proxy credential is outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($password, $nonce, $this->key));
    }

    /** 解密并认证密码；调用方使用后必须尽快 `sodium_memzero()`，不得跨请求缓存。 */
    public function decrypt(?string $payload): string
    {
        if (!is_string($payload) || !str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Network proxy credential is unavailable.');
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Network proxy credential is unavailable.');
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > 1024
            || preg_match('/[\x00\r\n]/', $plaintext) === 1) {
            throw new RuntimeException('Network proxy credential is unavailable.');
        }
        return $plaintext;
    }
}
