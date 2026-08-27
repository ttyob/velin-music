<?php

declare(strict_types=1);

namespace app\application\Library;

/** Represents a validated library command or safe field-level HTTP errors. */
final readonly class LibraryValidationResult
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public ?LibraryCreateInput $input, public array $errors)
    {
    }

    /** Returns true only when every request field has crossed its allowlist boundary. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
