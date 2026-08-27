<?php

declare(strict_types=1);

namespace app\application\Auth;

/** Represents either a fully validated setup command or safe field-level validation errors. */
final readonly class SetupValidationResult
{
    /**
     * @param array<string, list<string>> $errors Safe messages keyed by public form field.
     */
    public function __construct(
        public ?SetupInput $input,
        public array $errors,
    ) {
    }

    /** Returns true only when an immutable input can be passed to the setup transaction. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
