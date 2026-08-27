<?php

declare(strict_types=1);

namespace app\application\Auth;

use app\application\Subsonic\SubsonicCredentialCipher;
use app\http\RequestContext;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;

/**
 * Verifies local credentials with identity-safe errors and bounded failure throttling.
 *
 * Login attempts are keyed by a deployment-secret HMAC of the normalized username. The database
 * therefore supports throttling without storing failed usernames. Unknown users still execute an
 * Argon2id verification against a fixed valid digest, reducing externally observable account
 * enumeration through timing differences (AUTH-008, API-AUTH-007).
 */
final class CredentialService
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$4VpqdwTAaRH9sldgaP0MNA$CRGQDuo3w7hFLd2ZCQx5G0B2czoxhiY0ndZ5fWhUCUk';

    public function __construct(
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly SubsonicCredentialCipher $subsonicCredentials = new SubsonicCredentialCipher(),
    ) {
    }

    /**
     * Verifies credentials and records both the throttle event and immutable audit outcome.
     *
     * Password input is capped before this service by the controller to prevent unbounded request
     * memory. A disabled or soft-deleted user follows exactly the same public failure path as an
     * unknown username. Successful hashes are upgraded in place when PHP's Argon2 parameters
     * change; that update does not extend or create a Web session.
     */
    public function authenticate(string $username, string $password, string $requestId): LoginDecision
    {
        $identity = strtolower(trim($username));
        $identityHash = hash_hmac(
            'sha256',
            $identity,
            RequestContext::authenticationHashKey(),
        );
        $nowTimestamp = time();
        $windowStart = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp - self::WINDOW_SECONDS);

        $failures = Db::table('auth_login_attempts')
            ->where('identity_hash', $identityHash)
            ->where('success', 0)
            ->where('attempted_at', '>=', $windowStart)
            ->orderBy('attempted_at')
            ->get(['attempted_at']);

        if ($failures->count() >= self::MAX_FAILURES) {
            $first = (string) ($failures->first()->attempted_at ?? $windowStart);
            $firstTimestamp = strtotime($first) ?: ($nowTimestamp - self::WINDOW_SECONDS);
            $retryAfter = max(1, self::WINDOW_SECONDS - ($nowTimestamp - $firstTimestamp));
            $this->auditLogger->record(
                null,
                'auth.login',
                'session',
                null,
                'denied',
                $requestId,
                ['reason' => 'rate_limited'],
            );

            return new LoginDecision(false, retryAfterSeconds: $retryAfter);
        }

        /** @var stdClass|null $user */
        $user = Db::table('users')->where('username', $identity)->first();
        $hash = $user instanceof stdClass ? (string) $user->password_hash : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = password_verify($password, $hash);
        $now = gmdate('Y-m-d\TH:i:s\Z', $nowTimestamp);
        $eligible = $user instanceof stdClass
            && $user->status === 'active'
            && $user->deleted_at === null
            && ($user->account_expires_at === null || (string) $user->account_expires_at > $now);
        $authenticated = $passwordMatches && $eligible;

        Db::table('auth_login_attempts')->insert([
            'identity_hash' => $identityHash,
            'success' => $authenticated ? 1 : 0,
            'attempted_at' => $now,
        ]);

        if (!$authenticated) {
            $this->auditLogger->record(
                null,
                'auth.login',
                'session',
                null,
                'failure',
                $requestId,
                ['reason' => 'invalid_credentials'],
            );

            return new LoginDecision(false);
        }

        $userId = (string) $user->id;
        // The original password exists only at this verified boundary. Re-encrypting on each
        // successful login refreshes compatibility state after a future password/key change and
        // never persists a reusable MD5 challenge.
        $subsonicCiphertext = $this->subsonicCredentials->encrypt($password);
        $userUpdates = [
            'subsonic_secret_ciphertext' => $subsonicCiphertext,
            'last_login_at' => $now,
            'updated_at' => $now,
        ];
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $replacement = password_hash($password, PASSWORD_ARGON2ID);
            if (is_string($replacement)) {
                $userUpdates['password_hash'] = $replacement;
            }
        }

        Db::table('users')->where('id', $userId)->update($userUpdates);

        Db::table('auth_login_attempts')
            ->where('identity_hash', $identityHash)
            ->where('attempted_at', '<', $windowStart)
            ->delete();
        return new LoginDecision(true, $userId);
    }
}
