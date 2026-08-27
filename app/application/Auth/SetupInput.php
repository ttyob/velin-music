<?php

declare(strict_types=1);

namespace app\application\Auth;

/**
 * Carries validated first-run administrator and site settings into the setup transaction.
 *
 * Plaintext passwords exist only for the duration of the request and must never be logged,
 * serialized, or stored on a queued job. The setup service immediately replaces the value with
 * an Argon2id digest before performing any database write.
 */
final readonly class SetupInput
{
    public function __construct(
        public string $username,
        public string $displayName,
        public ?string $email,
        public string $password,
        public string $siteName,
        public string $locale,
        public string $timezone,
    ) {
    }
}
