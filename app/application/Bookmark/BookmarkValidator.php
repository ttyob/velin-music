<?php

declare(strict_types=1);

namespace app\application\Bookmark;

/**
 * Validates Web and Subsonic bookmark scalars without retaining invalid personal comments.
 *
 * IDs remain opaque canonical ULIDs, positions are non-negative milliseconds bounded to 30 days,
 * and comments are trimmed to null with a 1,000-character limit. Web versions are mandatory and
 * may be zero only for creation; legacy adapters explicitly request a null expected version.
 */
final class BookmarkValidator
{
    /** Builds a validated command from already-decoded request fields. */
    public function input(
        mixed $songId,
        mixed $positionMs,
        mixed $comment,
        mixed $expectedVersion,
        bool $legacy = false,
    ): BookmarkInput {
        return new BookmarkInput(
            $this->songId($songId),
            $this->integer($positionMs, 0, 2_592_000_000, 'position'),
            $this->comment($comment),
            $legacy ? null : $this->integer($expectedVersion, 0, PHP_INT_MAX, 'expectedVersion'),
        );
    }

    /** Validates one path/protocol song ID without numeric coercion. */
    public function songId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new BookmarkInvalid('Bookmark song ID is invalid.');
        }

        return $value;
    }

    /** Parses an expected version required by Web delete commands. */
    public function version(mixed $value): int
    {
        return $this->integer($value, 1, PHP_INT_MAX, 'expectedVersion');
    }

    /** Parses bounded offset pagination used by the Web list only. */
    public function page(mixed $value, int $default, int $minimum, int $maximum): int
    {
        return $value === null || $value === ''
            ? $default
            : $this->integer($value, $minimum, $maximum, 'pagination');
    }

    /** Trims an optional comment while preserving internal line breaks as user content. */
    private function comment(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new BookmarkInvalid('Bookmark comment is invalid.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > 1000) {
            throw new BookmarkInvalid('Bookmark comment is too long.');
        }

        return $value;
    }

    /** Accepts canonical non-negative decimal values only and enforces an inclusive range. */
    private function integer(mixed $value, int $minimum, int $maximum, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new BookmarkInvalid($field . ' is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new BookmarkInvalid($field . ' is outside the supported range.');
        }

        return $integer;
    }
}
