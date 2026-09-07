<?php

declare(strict_types=1);

namespace app\application\Auth;

final readonly class SetupLibraryValidationResult
{
    /** @param array<string,list<string>> $errors */
    public function __construct(public ?SetupLibraryInput $input, public array $errors)
    {
    }

    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
