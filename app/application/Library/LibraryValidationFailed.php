<?php

declare(strict_types=1);

namespace app\application\Library;

use RuntimeException;

/**
 * Carries field-safe validation errors from a versioned library update command.
 *
 * Filesystem paths are never attached to the exception message or logs. The controller may expose
 * the bounded field map only to an authenticated library manager; unexpected infrastructure errors
 * must use the generic management failure response instead.
 */
final class LibraryValidationFailed extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('请检查表单中的错误。');
    }
}
