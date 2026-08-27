<?php

declare(strict_types=1);

namespace app\application\Library;

/** Represents a validated update DTO or a bounded field-error map suitable for the admin form. */
final readonly class LibraryUpdateValidationResult
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public ?LibraryUpdateInput $input, public array $errors)
    {
    }

    /** Returns true only when strict field allowlists and every value constraint passed. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
