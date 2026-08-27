<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Canonicalizes bounded smart-playlist rule trees without accepting SQL or database identifiers.
 *
 * A definition has one root group, at most three group levels and twenty conditions. Every field owns
 * an explicit operator/value schema; unknown keys are discarded by rebuilding canonical arrays. Text
 * is bounded Unicode, dates use calendar ISO format, numeric ranges reflect media domain ceilings, and
 * library values must be opaque ULIDs. Validation is deterministic and has no database/filesystem side
 * effects, so preview and save consume byte-equivalent semantics.
 */
final class SmartPlaylistValidator
{
    private const SORT_FIELDS = [
        'title', 'album', 'artist', 'release_year', 'duration_ms', 'bitrate',
        'play_count', 'last_played_at', 'added_at', 'random',
    ];
    private int $conditionCount = 0;

    /**
     * @return array{rule: array<string, mixed>, sortField: string, sortDirection: string, resultLimit: int}
     */
    public function definition(array $payload): array
    {
        $this->conditionCount = 0;
        $rule = $this->group($payload['rule'] ?? null, 1);
        if ($this->conditionCount < 1 || $this->conditionCount > 20) {
            throw new SmartPlaylistInvalid('A smart playlist requires 1-20 conditions.');
        }
        $sortField = $payload['sortField'] ?? 'title';
        if (!is_string($sortField) || !in_array($sortField, self::SORT_FIELDS, true)) {
            throw new SmartPlaylistInvalid('Smart playlist sort field is invalid.');
        }
        $sortDirection = $payload['sortDirection'] ?? 'asc';
        if (!is_string($sortDirection) || !in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new SmartPlaylistInvalid('Smart playlist sort direction is invalid.');
        }
        $limit = $payload['resultLimit'] ?? 100;
        if (is_string($limit) && preg_match('/^[1-9][0-9]*$/', $limit) === 1) {
            $limit = (int) $limit;
        }
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new SmartPlaylistInvalid('Smart playlist result limit is invalid.');
        }

        return ['rule' => $rule, 'sortField' => $sortField,
            'sortDirection' => $sortField === 'random' ? 'asc' : $sortDirection, 'resultLimit' => $limit];
    }

    /** Rebuilds one all/any group and enforces depth/branch limits before descending. */
    private function group(mixed $value, int $depth): array
    {
        if (!is_array($value) || $depth > 3 || ($value['type'] ?? null) !== 'group') {
            throw new SmartPlaylistInvalid('Smart playlist group is invalid.');
        }
        $mode = $value['mode'] ?? null;
        $children = $value['children'] ?? null;
        if (!is_string($mode) || !in_array($mode, ['all', 'any'], true)
            || !is_array($children) || $children === [] || count($children) > 10) {
            throw new SmartPlaylistInvalid('Smart playlist group shape is invalid.');
        }
        $canonical = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                throw new SmartPlaylistInvalid('Smart playlist child is invalid.');
            }
            $canonical[] = ($child['type'] ?? null) === 'group'
                ? $this->group($child, $depth + 1)
                : $this->condition($child);
        }

        return ['type' => 'group', 'mode' => $mode, 'children' => $canonical];
    }

    /** Selects an exact field family and returns only its validated canonical condition. */
    private function condition(array $value): array
    {
        if (($value['type'] ?? null) !== 'condition' || !is_string($value['field'] ?? null)
            || !is_string($value['operator'] ?? null)) {
            throw new SmartPlaylistInvalid('Smart playlist condition is invalid.');
        }
        ++$this->conditionCount;
        $field = $value['field'];
        $operator = $value['operator'];
        $raw = $value['value'] ?? null;

        if (in_array($field, ['title', 'album', 'artist', 'genre', 'composer', 'codec'], true)) {
            $this->operator($operator, ['contains', 'not_contains', 'equals', 'not_equals']);
            if (!is_string($raw) || trim($raw) === '' || mb_strlen(trim($raw)) > 100) {
                throw new SmartPlaylistInvalid('Smart playlist text value is invalid.');
            }
            $canonical = trim($raw);
        } elseif (in_array($field, ['release_year', 'duration_ms', 'bitrate', 'play_count'], true)) {
            $this->operator($operator, ['equals', 'not_equals', 'greater_than', 'at_least', 'less_than', 'at_most', 'between']);
            [$minimum, $maximum] = match ($field) {
                'release_year' => [0, 3000], 'duration_ms' => [0, 604_800_000],
                'bitrate' => [0, 100_000_000], default => [0, 2_147_483_647],
            };
            $canonical = $operator === 'between'
                ? $this->range($raw, $minimum, $maximum)
                : $this->integer($raw, $minimum, $maximum);
        } elseif ($field === 'favorite') {
            $this->operator($operator, ['is_true', 'is_false']);
            $canonical = null;
        } elseif ($field === 'library') {
            $this->operator($operator, ['equals', 'not_equals']);
            if (!is_string($raw) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $raw) !== 1) {
                throw new SmartPlaylistInvalid('Smart playlist library ID is invalid.');
            }
            $canonical = $raw;
        } elseif (in_array($field, ['release_date', 'last_played_at', 'added_at'], true)) {
            if (in_array($operator, ['within_days', 'not_within_days'], true)) {
                if ($field === 'release_date') {
                    throw new SmartPlaylistInvalid('Release date does not support relative activity operators.');
                }
                $canonical = $this->integer($raw, 1, 3650);
            } else {
                $this->operator($operator, ['before', 'after', 'on']);
                if (!is_string($raw) || !$this->validDate($raw)) {
                    throw new SmartPlaylistInvalid('Smart playlist date is invalid.');
                }
                $canonical = $raw;
            }
        } else {
            throw new SmartPlaylistInvalid('Smart playlist field is invalid.');
        }

        return ['type' => 'condition', 'field' => $field, 'operator' => $operator, 'value' => $canonical];
    }

    /** Rejects operators not explicitly owned by the selected field family. */
    private function operator(string $operator, array $allowed): void
    {
        if (!in_array($operator, $allowed, true)) {
            throw new SmartPlaylistInvalid('Smart playlist operator is invalid.');
        }
    }

    /** Converts a canonical integer string or integer within the field-specific range. */
    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SmartPlaylistInvalid('Smart playlist numeric value is invalid.');
        }

        return $value;
    }

    /** @return array{int, int} */
    private function range(mixed $value, int $minimum, int $maximum): array
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new SmartPlaylistInvalid('Smart playlist numeric range is invalid.');
        }
        $left = $this->integer(array_values($value)[0], $minimum, $maximum);
        $right = $this->integer(array_values($value)[1], $minimum, $maximum);
        if ($left > $right) {
            throw new SmartPlaylistInvalid('Smart playlist numeric range is reversed.');
        }

        return [$left, $right];
    }

    /** Verifies that parsing and formatting preserve the exact Gregorian calendar date. */
    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
