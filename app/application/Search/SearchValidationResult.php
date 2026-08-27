<?php

declare(strict_types=1);

namespace app\application\Search;

/** Represents a validated search command or safe field errors suitable for HTTP 422. */
final readonly class SearchValidationResult
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public ?SearchQueryInput $input, public array $errors)
    {
    }

    /** Returns true only when every untrusted query parameter has entered a bounded field. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
