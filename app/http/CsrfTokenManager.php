<?php

declare(strict_types=1);

namespace app\http;

use Workerman\Protocols\Http\Session;

/**
 * Owns synchronizer tokens for Cookie-authenticated Web requests (API-AUTH-001).
 *
 * Tokens live only in the server-side Webman Session and the browser's in-memory request flow;
 * neither the database nor JavaScript storage receives a persistent copy. Authentication rotates
 * the token after Session ID regeneration to prevent an anonymous token from surviving login.
 */
final class CsrfTokenManager
{
    private const SESSION_KEY = 'csrf_token';

    /** Returns the current token, creating a 256-bit token when the session has none. */
    public function getOrCreate(Session $session): string
    {
        $token = $session->get(self::SESSION_KEY);
        if (is_string($token) && strlen($token) === 64) {
            return $token;
        }

        return $this->rotate($session);
    }

    /** Replaces the token after a trust-boundary change and returns the new value. */
    public function rotate(Session $session): string
    {
        $token = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Compares an untrusted header with the session token in constant time.
     *
     * Missing or malformed values fail closed. This method never creates a token during a write
     * request, so clients must first complete the explicit CSRF bootstrap operation.
     */
    public function verify(Session $session, mixed $candidate): bool
    {
        $expected = $session->get(self::SESSION_KEY);

        return is_string($expected)
            && strlen($expected) === 64
            && is_string($candidate)
            && strlen($candidate) === 64
            && hash_equals($expected, $candidate);
    }
}
