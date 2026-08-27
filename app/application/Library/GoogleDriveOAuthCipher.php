<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/**
 * 以独立 HKDF context 加密 Google PKCE verifier、client secret 与 refresh token。
 *
 * 三类秘密即使数据库列被交换也不能解密。secretbox 随机 nonce 保证相同明文不会生成相同密文；版本、
 * Base64、MAC 或长度不合法均失败关闭，不提供明文兼容。调用方只能在 Google 请求附近短暂持有明文。
 */
final readonly class GoogleDriveOAuthCipher
{
    private string $pkceKey;
    private string $clientSecretKey;
    private string $refreshTokenKey;

    /** 测试可注入根密钥；生产使用部署初始化生成的独立 `VELIN_CREDENTIAL_KEY`。 */
    public function __construct(?string $deploymentSecret = null)
    {
        $root = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        $this->pkceKey = hash_hkdf('sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-google-drive-pkce-v1');
        $this->clientSecretKey = hash_hkdf('sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-google-drive-client-secret-v1');
        $this->refreshTokenKey = hash_hkdf('sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-google-drive-refresh-token-v1');
    }

    public function encryptPkceVerifier(string $value): string { return $this->encrypt($value, $this->pkceKey, 128); }
    public function decryptPkceVerifier(?string $value): string { return $this->decrypt($value, $this->pkceKey, 128); }
    public function encryptClientSecret(string $value): string { return $this->encrypt($value, $this->clientSecretKey, 512); }
    public function decryptClientSecret(?string $value): string { return $this->decrypt($value, $this->clientSecretKey, 512); }
    public function encryptRefreshToken(string $value): string { return $this->encrypt($value, $this->refreshTokenKey, 65_536); }
    public function decryptRefreshToken(?string $value): string { return $this->decrypt($value, $this->refreshTokenKey, 65_536); }

    private function encrypt(string $plaintext, string $key, int $maximumBytes): string
    {
        if ($plaintext === '' || strlen($plaintext) > $maximumBytes) {
            throw new RuntimeException('Google OAuth credential is outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function decrypt(?string $ciphertext, string $key, int $maximumBytes): string
    {
        if (!is_string($ciphertext) || !str_starts_with($ciphertext, 'v1:')
            || strlen($ciphertext) > (int) ceil(($maximumBytes + 64) * 4 / 3) + 3) {
            throw new RuntimeException('Google OAuth credential is unavailable.');
        }
        $decoded = base64_decode(substr($ciphertext, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Google OAuth credential is unavailable.');
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > $maximumBytes) {
            throw new RuntimeException('Google OAuth credential is unavailable.');
        }
        return $plaintext;
    }
}
