<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/**
 * 为 OneDrive device code 与 refresh token 提供互不兼容的认证加密。
 *
 * 两类秘密都从部署级 `VELIN_CREDENTIAL_KEY` 派生，但 HKDF context 不同，数据库列被交换时也无法解密。
 * secretbox 随机 nonce 保证相同明文不会产生相同密文；版本、Base64、MAC 或长度不合法均失败关闭。调用方
 * 只能在 Microsoft 请求附近短暂持有明文，并应在使用后尽力清零；本类不记录秘密且不提供明文回退。
 */
final readonly class OneDriveOAuthCipher
{
    private string $deviceCodeKey;
    private string $refreshTokenKey;

    /** 测试可注入独立根密钥；生产部署密钥不得少于 16 字节。 */
    public function __construct(?string $deploymentSecret = null)
    {
        $root = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        $this->deviceCodeKey = hash_hkdf(
            'sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-onedrive-device-code-v1',
        );
        $this->refreshTokenKey = hash_hkdf(
            'sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-onedrive-refresh-token-v1',
        );
    }

    /** 加密 Microsoft device code；该密文只允许保存到有到期时间的授权会话。 */
    public function encryptDeviceCode(string $deviceCode): string
    {
        return $this->encrypt($deviceCode, $this->deviceCodeKey, 8192);
    }

    /** 解密尚未到期且 actor 已匹配的 device code。 */
    public function decryptDeviceCode(?string $ciphertext): string
    {
        return $this->decrypt($ciphertext, $this->deviceCodeKey, 8192);
    }

    /** 加密可轮换 refresh token；随机 nonce 允许使用条件更新安全替换密文。 */
    public function encryptRefreshToken(string $refreshToken): string
    {
        return $this->encrypt($refreshToken, $this->refreshTokenKey, 65_536);
    }

    /** 解密 refresh token；认证失败时不区分密钥错误、篡改或数据损坏。 */
    public function decryptRefreshToken(?string $ciphertext): string
    {
        return $this->decrypt($ciphertext, $this->refreshTokenKey, 65_536);
    }

    /** 使用指定用途密钥生成带版本前缀的 secretbox 密文。 */
    private function encrypt(string $plaintext, string $key, int $maximumBytes): string
    {
        if ($plaintext === '' || strlen($plaintext) > $maximumBytes) {
            throw new RuntimeException('OneDrive OAuth credential is outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    /** 认证并限制解密后的秘密长度，禁止畸形密文造成无界内存使用。 */
    private function decrypt(?string $ciphertext, string $key, int $maximumBytes): string
    {
        if (!is_string($ciphertext) || !str_starts_with($ciphertext, 'v1:')
            || strlen($ciphertext) > (int) ceil(($maximumBytes + 64) * 4 / 3) + 3) {
            throw new RuntimeException('OneDrive OAuth credential is unavailable.');
        }
        $decoded = base64_decode(substr($ciphertext, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('OneDrive OAuth credential is unavailable.');
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > $maximumBytes) {
            throw new RuntimeException('OneDrive OAuth credential is unavailable.');
        }
        return $plaintext;
    }
}
