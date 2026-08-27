<?php

declare(strict_types=1);

namespace app\application\Search;

/**
 * Validates search URL parameters before any catalog query or database wildcard construction.
 *
 * The query limit prevents expensive or abusive pattern scans. Type is allowlisted because it
 * controls query selection, while library IDs are syntax-checked without testing existence, so a
 * validation response cannot reveal another user's library. Pagination accepts numeric strings
 * from the HTTP query parser but rejects negative, fractional, or out-of-range values.
 */
final readonly class SearchValidator
{
    /** Uses the same portable normalizer as catalog writes; callers may inject it for contract tests. */
    public function __construct(private SearchTextNormalizer $normalizer = new SearchTextNormalizer())
    {
    }

    /** @param array<string, mixed> $parameters Untrusted HTTP query parameters. */
    public function validate(array $parameters): SearchValidationResult
    {
        $query = is_string($parameters['q'] ?? null) ? trim($parameters['q']) : '';
        $type = is_string($parameters['type'] ?? null) ? $parameters['type'] : 'all';
        $libraryId = is_string($parameters['libraryId'] ?? null) && $parameters['libraryId'] !== ''
            ? $parameters['libraryId']
            : null;
        $limit = $this->integer($parameters['limit'] ?? 20);
        $offset = $this->integer($parameters['offset'] ?? 0);
        $errors = [];

        $length = mb_strlen($query, 'UTF-8');
        if ($length < 1 || $length > 100) {
            $errors['q'][] = '搜索词需为 1-100 个字符。';
        }
        if (!in_array($type, ['all', 'songs', 'albums', 'artists'], true)) {
            $errors['type'][] = '搜索类型无效。';
        }
        if ($libraryId !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) {
            $errors['libraryId'][] = '音乐库标识格式无效。';
        }
        if ($limit === null || $limit < 1 || $limit > 50) {
            $errors['limit'][] = '每类结果数量需为 1-50。';
        }
        if ($offset === null || $offset < 0 || $offset > 10_000) {
            $errors['offset'][] = '结果偏移需为 0-10000。';
        }
        $normalized = $this->normalizer->normalize($query);
        if ($query !== '' && $normalized === '') {
            $errors['q'][] = '搜索词必须包含可检索字符。';
        }

        if ($errors !== [] || $limit === null || $offset === null) {
            return new SearchValidationResult(null, $errors);
        }

        return new SearchValidationResult(new SearchQueryInput(
            query: $query,
            normalizedQuery: $normalized,
            type: $type,
            libraryId: $libraryId,
            limit: $limit,
            offset: $offset,
        ), []);
    }

    /** Accepts only decimal integer forms; floats and exponent notation are not pagination. */
    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
