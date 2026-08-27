<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * Represents a credential check without exposing whether an identity exists.
 *
 * Controllers map all non-rate-limited failures to the same status and message. userId is
 * available only on success and must not be reflected in failed authentication responses.
 */
final readonly class LoginDecision
{
    public function __construct(
        public bool $authenticated,
        public ?string $userId = null,
        public int $retryAfterSeconds = 0,
    ) {
    }

    /** Returns true when the identity digest has exceeded the bounded failure window. */
    public function isRateLimited(): bool
    {
        return !$this->authenticated && $this->retryAfterSeconds > 0;
    }
}
