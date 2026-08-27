<?php

declare(strict_types=1);

namespace app\application\Scan;

/** Holds bounded validation errors without touching library state or the filesystem. */
final readonly class ScanValidationResult
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public ?ScanCreateInput $input, public array $errors)
    {
    }

    /** Returns true only when a normalized command is available. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
