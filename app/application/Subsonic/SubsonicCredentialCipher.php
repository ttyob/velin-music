<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use RuntimeException;

/**
 * Encrypts the credential required by the legacy Subsonic salt+token challenge.
 *
 * Argon2id remains the login authority, but arbitrary client salts require access to the original
 * password after a successful Web authentication. Sodium secretbox provides confidentiality and
 * integrity under a deployment-owned key. Every write uses a random nonce, the database stores only
 * `v1:` plus base64(nonce+ciphertext), and decryption rejects unknown versions or modified payloads.
 * This class never logs, hashes for authentication, or persists values itself.
 */
final readonly class SubsonicCredentialCipher
{
    private string $key;

    /**
     * @param string|null $deploymentSecret Explicit test key or VELIN_CREDENTIAL_KEY when null.
     * Production must supply a long independent secret; the fallback exists only for local startup.
     */
    public function __construct(?string $deploymentSecret = null)
    {
        $secret = $deploymentSecret ?? getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($secret) < 16) {
            throw new RuntimeException('VELIN_CREDENTIAL_KEY must contain at least 16 bytes.');
        }
        $this->key = hash('sha256', $secret, true);
    }

    /**
     * Encrypts one already validated local password using a fresh nonce.
     *
     * The caller owns database transaction timing. An exception leaves existing ciphertext intact;
     * no partial payload is returned. Password length is bounded again to protect accidental misuse
     * by future call sites that do not pass through current validators.
     */
    public function encrypt(string $password): string
    {
        if ($password === '' || strlen($password) > 1024) {
            throw new RuntimeException('Credential plaintext is outside the supported boundary.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($password, $nonce, $this->key);

        return 'v1:' . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypts and authenticates one versioned payload entirely in memory.
     *
     * Null, malformed base64, wrong-key, truncated, and tampered values share one safe exception.
     * The returned plaintext must be used only for an immediate constant-time challenge comparison
     * and must never enter a response, log, cache, queue, or exception message.
     */
    public function decrypt(?string $payload): string
    {
        if (!is_string($payload) || !str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Subsonic credential is unavailable.');
        }
        $decoded = base64_decode(substr($payload, 3), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Subsonic credential is unavailable.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key;
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Subsonic credential is unavailable.');
        }

        return $plaintext;
    }
}
