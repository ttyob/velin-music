<?php

declare(strict_types=1);

namespace app\http;

use support\Request;

/**
 * Creates security-safe metadata shared by API controllers and audit records.
 *
 * Client-provided request IDs are intentionally ignored because correlation identifiers are
 * later trusted by operators when tracing security events. User agents are reduced to keyed
 * digests before persistence so the session list can distinguish clients without retaining a
 * high-entropy browser fingerprint or arbitrary header content.
 */
final class RequestContext
{
    /** Returns a cryptographically random, server-owned request correlation ID. */
    public static function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Returns a stable digest for the current user agent.
     *
     * The same deployment key used for login-identity digests prevents an offline dictionary
     * from recovering common user-agent strings if the database is exposed.
     */
    public static function userAgentHash(Request $request): string
    {
        $userAgent = (string) $request->header('user-agent', '');

        return hash_hmac('sha256', $userAgent, self::authenticationHashKey());
    }

    /**
     * Returns the deployment secret used only for non-reversible lookup digests.
     *
     * Production must set VELIN_AUTH_HASH_KEY to a long random secret. The development fallback
     * keeps a fresh checkout runnable but is intentionally namespaced and must not be treated as
     * an encryption key or reused for signing externally visible tokens.
     */
    public static function authenticationHashKey(): string
    {
        return getenv('VELIN_AUTH_HASH_KEY') ?: 'velin-development-auth-hash-key-change-me';
    }
}
