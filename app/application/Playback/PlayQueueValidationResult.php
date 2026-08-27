<?php

declare(strict_types=1);

namespace app\application\Playback;

/** Holds either one complete queue command or bounded field errors, never a partial command. */
final readonly class PlayQueueValidationResult
{
    /** @param array<string, list<string>> $errors Safe localized errors without submitted values. */
    public function __construct(public ?PlayQueueInput $input, public array $errors)
    {
    }

    /** Returns true only when the complete immutable command can cross into the service layer. */
    public function isValid(): bool
    {
        return $this->input !== null && $this->errors === [];
    }
}
