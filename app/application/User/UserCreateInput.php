<?php

declare(strict_types=1);

namespace app\application\User;

/**
 * Carries a validated non-super-admin account command into the user transaction.
 *
 * Plaintext password lifetime is limited to the synchronous request. The service hashes it before
 * opening a write transaction and never emits this DTO to logs, audit metadata, or responses.
 */
final readonly class UserCreateInput
{
    public function __construct(
        public string $username,
        public string $displayName,
        public ?string $email,
        public string $password,
        public string $roleKey,
        public string $locale,
        public string $timezone,
        public ?string $accountExpiresAt,
        /** @var list<string> 用户直授能力键；当前仅允许 cast。 */
        public array $directCapabilities = [],
    ) {
    }
}
