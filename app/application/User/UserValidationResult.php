<?php

declare(strict_types=1);

namespace app\application\User;

/** Represents a validated account command or safe field-level messages for HTTP 422. */
final readonly class UserValidationResult
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public ?UserCreateInput $input, public array $errors)
    {
    }

    /** Returns true only when no untrusted value remains outside the typed command. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
