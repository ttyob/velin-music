<?php

declare(strict_types=1);

namespace app\application\Search;

/**
 * Produces portable search keys without relying on SQLite or MySQL collation behavior.
 *
 * Velin's PHP runtime guarantees mbstring but not intl, so normalization deliberately uses the
 * deterministic subset available in both bare-metal and container deployments: full-width ASCII
 * and spaces become half-width, case is folded with Unicode-aware lowercase, and all whitespace is
 * collapsed. It does not transliterate Chinese text or invent pinyin aliases; those are separate
 * indexed fields if introduced later. The same algorithm is frozen into the migration that
 * backfills existing media rows so scans and upgraded databases produce identical keys.
 */
final class SearchTextNormalizer
{
    /** Returns a stable UTF-8 key suitable for equality, prefix, and escaped LIKE matching. */
    public function normalize(string $value): string
    {
        $value = mb_convert_kana($value, 'as', 'UTF-8');
        $value = mb_strtolower($value, 'UTF-8');

        return preg_replace('/[\p{Z}\s]+/u', ' ', trim($value)) ?? trim($value);
    }
}
