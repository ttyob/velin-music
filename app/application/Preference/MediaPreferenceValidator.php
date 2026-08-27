<?php

declare(strict_types=1);

namespace app\application\Preference;

/**
 * Validates the small public command vocabulary for personal media preferences.
 *
 * Only plural resource names used by the API are accepted, preventing request values from ever
 * selecting SQL structure. Validation has no database or logging side effects.
 */
final class MediaPreferenceValidator
{
    /** Returns a supported resource type or throws a field-safe validation exception. */
    public function type(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['songs', 'albums', 'artists'], true)) {
            throw new MediaPreferenceInvalid('Media type is invalid.');
        }

        return $value;
    }

    /** Returns an opaque ULID without checking whether the underlying media object exists. */
    public function mediaId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new MediaPreferenceInvalid('Media ID is invalid.');
        }

        return $value;
    }

}
