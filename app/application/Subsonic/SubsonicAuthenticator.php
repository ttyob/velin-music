<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use app\http\RequestContext;
use stdClass;
use support\Db;
use Throwable;

/**
 * Authenticates the legacy Subsonic salt+token request independently from Web Sessions.
 *
 * Required parameters are bounded before database/crypto work. The token is compared in constant
 * time against MD5(decrypted password + client salt), exactly as Subsonic v1.16.1 requires. Missing,
 * disabled, expired, unprovisioned, wrong-key, and incorrect-token identities share one failure.
 * Successful authentication resolves current capabilities and active library grants on every API
 * call; the encrypted credential never creates a Cookie session or grants catalog scope itself.
 */
final readonly class SubsonicAuthenticator
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;
    private const DUMMY_SECRET = 'velin-subsonic-dummy-credential';

    public function __construct(
        private SubsonicCredentialCipher $cipher = new SubsonicCredentialCipher(),
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
    ) {
    }

    /**
     * Validates protocol parameters and returns a current principal projection.
     *
     * @param array<string, mixed> $parameters Merged query/form values for u,t,s,v,c.
     * @return array<string, mixed> Actor compatible with internal authorization/media services.
     * @throws SubsonicRequestInvalid Required protocol structure is malformed.
     * @throws SubsonicAuthenticationFailed Credentials are unavailable, throttled, or incorrect.
     */
    public function authenticate(array $parameters): array
    {
        $username = $this->requiredText($parameters, 'u', 254);
        $token = strtolower($this->requiredText($parameters, 't', 32));
        $salt = $this->requiredText($parameters, 's', 64);
        $version = $this->requiredText($parameters, 'v', 20);
        $this->requiredText($parameters, 'c', 64);
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1
            || preg_match('/^[\x21-\x7E]{1,64}$/', $salt) !== 1
            || preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $version) !== 1) {
            throw new SubsonicRequestInvalid('Subsonic authentication parameters are invalid.');
        }

        $identity = strtolower(trim($username));
        $identityHash = hash_hmac('sha256', $identity, RequestContext::authenticationHashKey());
        $windowStart = gmdate('Y-m-d\TH:i:s\Z', time() - self::WINDOW_SECONDS);
        $failureQuery = Db::table('auth_login_attempts')
            ->where('identity_hash', $identityHash)
            ->where('success', 0)
            ->where('attempted_at', '>=', $windowStart);
        if ((clone $failureQuery)->count() >= self::MAX_FAILURES) {
            throw new SubsonicAuthenticationFailed('Subsonic authentication failed.');
        }

        /** @var stdClass|null $user */
        $user = Db::table('users')->where('username', $identity)->first([
            'id', 'username', 'display_name', 'email', 'is_super_admin', 'permission_version',
            'status', 'deleted_at', 'account_expires_at', 'subsonic_secret_ciphertext',
        ]);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $eligible = $user instanceof stdClass
            && $user->status === 'active'
            && $user->deleted_at === null
            && ($user->account_expires_at === null || (string) $user->account_expires_at > $now);
        $secret = self::DUMMY_SECRET;
        if ($eligible) {
            try {
                $secret = $this->cipher->decrypt(
                    is_string($user->subsonic_secret_ciphertext) ? $user->subsonic_secret_ciphertext : null,
                );
            } catch (Throwable) {
                $eligible = false;
            }
        }
        $matches = hash_equals(md5($secret . $salt), $token);
        sodium_memzero($secret);
        if (!$eligible || !$matches || !$user instanceof stdClass) {
            Db::table('auth_login_attempts')->insert([
                'identity_hash' => $identityHash,
                'success' => 0,
                'attempted_at' => $now,
            ]);
            throw new SubsonicAuthenticationFailed('Subsonic authentication failed.');
        }

        $failureQuery->delete();
        $userId = (string) $user->id;
        $isSuperAdmin = (int) $user->is_super_admin === 1;

        return [
            'id' => $userId,
            'username' => (string) $user->username,
            'displayName' => (string) $user->display_name,
            'email' => $user->email === null ? null : (string) $user->email,
            'isSuperAdmin' => $isSuperAdmin,
            'permissionVersion' => (int) $user->permission_version,
            'capabilities' => $this->capabilities->resolve($userId, $isSuperAdmin),
            'libraries' => $this->libraries->resolve($userId, $isSuperAdmin),
        ];
    }

    /** Returns one trimmed scalar or rejects missing/oversized/ambiguous array input. */
    private function requiredText(array $parameters, string $name, int $maximum): string
    {
        $value = $parameters[$name] ?? null;
        if (!is_string($value)) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is missing.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maximum) {
            throw new SubsonicRequestInvalid('A required Subsonic parameter is invalid.');
        }

        return $value;
    }
}
