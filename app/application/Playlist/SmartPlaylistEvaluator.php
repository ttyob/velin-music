<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Media\MediaQueryService;
use app\application\Search\SearchTextNormalizer;
use Illuminate\Database\Query\Builder;
use PDO;
use stdClass;
use support\Db;

/**
 * Evaluates one canonical smart-playlist definition inside the caller's live media authorization.
 *
 * The evaluator never accepts SQL, table names, columns, or sort fragments from a request. Input must
 * first pass SmartPlaylistValidator; every field/operator is translated through closed PHP maps and
 * all values remain bound parameters. The base scope repeats active-library, current grant, available
 * inventory, and ready-metadata checks, then joins only this user's preferences and play statistics.
 * Results contain stable song IDs only until MediaQueryService reauthorizes and produces path-free
 * projections. Reads have no persistence, audit, playback-count, filesystem, or network side effects.
 */
final readonly class SmartPlaylistEvaluator
{
    public function __construct(
        private MediaQueryService $media = new MediaQueryService(),
        private SearchTextNormalizer $normalizer = new SearchTextNormalizer(),
    ) {
    }

    /**
     * Returns the current dynamic result in exactly the SQL order used by saved playlists.
     *
     * @param array<string, mixed> $actor Authenticated principal; super administrators bypass grants
     *     only in the same way as normal media browsing.
     * @param array{rule: array<string, mixed>, sortField: string, sortDirection: string, resultLimit: int} $definition
     *     Canonical output from SmartPlaylistValidator, never an unvalidated request array.
     * @param string $seedIdentity Stable playlist ID for saved rules, or a bounded draft digest for an
     *     unsaved preview. UTC date is appended so random order is stable for the whole day.
     * @return list<array<string, mixed>> Authorized path-free song projections in evaluated order.
     */
    public function evaluate(array $actor, array $definition, string $seedIdentity): array
    {
        $query = $this->scopedQuery($actor);
        $this->applyGroup($query, $definition['rule'], 'and');
        $this->applySort(
            $query,
            $definition['sortField'],
            $definition['sortDirection'],
            $seedIdentity . '|' . gmdate('Y-m-d'),
        );

        /** @var list<stdClass> $rows */
        $rows = $query->limit($definition['resultLimit'])->get(['songs.id'])->all();
        $ids = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $songs = $this->media->songsByIds($actor, $ids);

        // Authorization can change between the ID query and projection query. Omitted IDs remain
        // omitted, while this ordered rebuild prevents MediaQueryService's keyed map from reordering.
        $result = [];
        foreach ($ids as $id) {
            if (isset($songs[$id])) {
                $result[] = $songs[$id];
            }
        }

        return $result;
    }

    /** Builds the mandatory live catalog and current-user personal-data scope. */
    private function scopedQuery(array $actor): Builder
    {
        $userId = (string) ($actor['id'] ?? '');
        $query = Db::table('media_songs as songs')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->leftJoin('user_song_preferences as smart_preferences', function ($join) use ($userId): void {
                $join->on('smart_preferences.song_id', '=', 'songs.id')
                    ->where('smart_preferences.user_id', '=', $userId);
            })
            ->leftJoin('user_song_play_stats as smart_stats', function ($join) use ($userId): void {
                $join->on('smart_stats.song_id', '=', 'songs.id')
                    ->where('smart_stats.user_id', '=', $userId);
            })
            ->where('libraries.status', 'active')
            ->where('files.status', 'available')
            ->where('files.metadata_status', 'ready');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as smart_grants', function ($join) use ($userId): void {
                $join->on('smart_grants.library_id', '=', 'songs.library_id')
                    ->where('smart_grants.user_id', '=', $userId);
            });
        }

        return $query;
    }

    /** Applies one parenthesized all/any group without permitting request-controlled SQL structure. */
    private function applyGroup(Builder $query, array $group, string $boolean): void
    {
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $query->{$method}(function (Builder $nested) use ($group): void {
            $childBoolean = $group['mode'] === 'any' ? 'or' : 'and';
            foreach ($group['children'] as $child) {
                if ($child['type'] === 'group') {
                    $this->applyGroup($nested, $child, $childBoolean);
                } else {
                    $this->applyCondition($nested, $child, $childBoolean);
                }
            }
        });
    }

    /** Routes a canonical condition to a closed field family; unknown fields fail closed. */
    private function applyCondition(Builder $query, array $condition, string $boolean): void
    {
        $field = $condition['field'];
        if (in_array($field, ['title', 'album', 'composer', 'codec'], true)) {
            $this->applyText($query, $condition, $boolean);
            return;
        }
        if (in_array($field, ['artist', 'genre'], true)) {
            $this->applyVocabulary($query, $condition, $boolean);
            return;
        }
        if (in_array($field, ['release_year', 'duration_ms', 'bitrate', 'play_count'], true)) {
            $this->applyNumeric($query, $condition, $boolean);
            return;
        }
        if ($field === 'favorite') {
            $this->whereRaw($query, 'COALESCE(smart_preferences.is_favorite, 0) = ?', [
                $condition['operator'] === 'is_true' ? 1 : 0,
            ], $boolean);
            return;
        }
        if ($field === 'library') {
            $operator = $condition['operator'] === 'equals' ? '=' : '<>';
            $this->whereRaw($query, "songs.library_id {$operator} ?", [$condition['value']], $boolean);
            return;
        }
        if (in_array($field, ['release_date', 'last_played_at', 'added_at'], true)) {
            $this->applyDate($query, $condition, $boolean);
            return;
        }

        throw new SmartPlaylistInvalid('Smart playlist field is invalid.');
    }

    /** Applies normalized title/album text or Unicode-folded nullable technical text. */
    private function applyText(Builder $query, array $condition, string $boolean): void
    {
        $field = $condition['field'];
        $column = match ($field) {
            'title' => 'songs.normalized_title',
            'album' => 'albums.normalized_title',
            'composer' => 'LOWER(COALESCE(songs.composer, \'\'))',
            'codec' => 'LOWER(COALESCE(songs.codec_name, \'\'))',
            default => throw new SmartPlaylistInvalid('Smart playlist text field is invalid.'),
        };
        $value = in_array($field, ['title', 'album'], true)
            ? $this->normalizer->normalize((string) $condition['value'])
            : mb_strtolower((string) $condition['value'], 'UTF-8');
        [$sql, $binding] = $this->textPredicate($column, $condition['operator'], $value);
        $this->whereRaw($query, $sql, [$binding], $boolean);
    }

    /** Applies artist/genre semantics as EXISTS or NOT EXISTS so multi-value rows never duplicate songs. */
    private function applyVocabulary(Builder $query, array $condition, string $boolean): void
    {
        $field = $condition['field'];
        $normalized = $this->normalizer->normalize((string) $condition['value']);
        $negative = in_array($condition['operator'], ['not_contains', 'not_equals'], true);
        $positiveOperator = $condition['operator'] === 'not_contains' ? 'contains'
            : ($condition['operator'] === 'not_equals' ? 'equals' : $condition['operator']);
        $method = ($negative ? 'whereNotExists' : 'whereExists');
        if ($boolean === 'or') {
            $method = $negative ? 'orWhereNotExists' : 'orWhereExists';
        }
        $query->{$method}(function (Builder $sub) use ($field, $normalized, $positiveOperator): void {
            if ($field === 'artist') {
                $sub->selectRaw('1')->from('media_song_artists as smart_song_artists')
                    ->join('media_artists as smart_artists', 'smart_artists.id', '=', 'smart_song_artists.artist_id')
                    ->whereColumn('smart_song_artists.song_id', 'songs.id');
                [$sql, $binding] = $this->textPredicate('smart_artists.normalized_name', $positiveOperator, $normalized);
            } else {
                $sub->selectRaw('1')->from('media_song_genres as smart_song_genres')
                    ->join('media_genres as smart_genres', 'smart_genres.id', '=', 'smart_song_genres.genre_id')
                    ->whereColumn('smart_song_genres.song_id', 'songs.id');
                [$sql, $binding] = $this->textPredicate('smart_genres.normalized_name', $positiveOperator, $normalized);
            }
            $sub->whereRaw($sql, [$binding]);
        });
    }

    /** Applies numeric comparisons with explicit zero semantics for absent personal values. */
    private function applyNumeric(Builder $query, array $condition, string $boolean): void
    {
        $column = match ($condition['field']) {
            'release_year' => 'songs.release_year',
            'duration_ms' => 'songs.duration_ms',
            'bitrate' => 'songs.bitrate',
            'play_count' => 'COALESCE(smart_stats.play_count, 0)',
            default => throw new SmartPlaylistInvalid('Smart playlist numeric field is invalid.'),
        };
        $operator = $condition['operator'];
        if ($operator === 'between') {
            $this->whereRaw($query, "{$column} BETWEEN ? AND ?", $condition['value'], $boolean);
            return;
        }
        $sqlOperator = match ($operator) {
            'equals' => '=', 'not_equals' => '<>', 'greater_than' => '>', 'at_least' => '>=',
            'less_than' => '<', 'at_most' => '<=',
            default => throw new SmartPlaylistInvalid('Smart playlist numeric operator is invalid.'),
        };
        $this->whereRaw($query, "{$column} {$sqlOperator} ?", [$condition['value']], $boolean);
    }

    /** Applies calendar and activity-date rules using UTC thresholds calculated outside SQL. */
    private function applyDate(Builder $query, array $condition, string $boolean): void
    {
        $column = match ($condition['field']) {
            'release_date' => 'songs.release_date',
            'last_played_at' => 'smart_stats.last_played_at',
            'added_at' => 'songs.created_at',
            default => throw new SmartPlaylistInvalid('Smart playlist date field is invalid.'),
        };
        $operator = $condition['operator'];
        if (in_array($operator, ['within_days', 'not_within_days'], true)) {
            $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - ((int) $condition['value'] * 86_400));
            $sql = $operator === 'within_days'
                ? "{$column} IS NOT NULL AND {$column} >= ?"
                : "({$column} IS NULL OR {$column} < ?)";
            $this->whereRaw($query, $sql, [$threshold], $boolean);
            return;
        }
        $sql = match ($operator) {
            'before' => "{$column} < ?",
            'after' => "{$column} > ?",
            'on' => "substr({$column}, 1, 10) = ?",
            default => throw new SmartPlaylistInvalid('Smart playlist date operator is invalid.'),
        };
        $this->whereRaw($query, $sql, [$condition['value']], $boolean);
    }

    /** Returns an escaped LIKE/equality predicate and its sole bound value. */
    private function textPredicate(string $column, string $operator, string $value): array
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        return match ($operator) {
            'contains' => ["{$column} LIKE ? ESCAPE '\\'", '%' . $escaped . '%'],
            'not_contains' => ["{$column} NOT LIKE ? ESCAPE '\\'", '%' . $escaped . '%'],
            'equals' => ["{$column} = ?", $value],
            'not_equals' => ["{$column} <> ?", $value],
            default => throw new SmartPlaylistInvalid('Smart playlist text operator is invalid.'),
        };
    }

    /** Adds a bound raw predicate with an independently controlled boolean connector. */
    private function whereRaw(Builder $query, string $sql, array $bindings, string $boolean): void
    {
        if ($boolean === 'or') {
            $query->orWhereRaw($sql, $bindings);
        } else {
            $query->whereRaw($sql, $bindings);
        }
    }

    /** Applies a fixed sort map and stable ID tie-breaker, including deterministic daily random order. */
    private function applySort(Builder $query, string $field, string $direction, string $seed): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        if ($field === 'random') {
            $pdo = Db::connection()->getPdo();
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                // Registration is per PDO connection. Re-registering is harmless and ensures a
                // reconnected Webman worker never executes a smart query without the function.
                $pdo->sqliteCreateFunction(
                    'velin_smart_rank',
                    static fn (string $stableSeed, string $songId): int => (int) sprintf(
                        '%u',
                        crc32($stableSeed . '|' . $songId),
                    ),
                    2,
                    PDO::SQLITE_DETERMINISTIC,
                );
                $query->orderByRaw('velin_smart_rank(?, songs.id) ASC', [$seed]);
            } elseif ($driver === 'mysql') {
                // Future MySQL migration uses a deterministic SHA2 expression with identical seed
                // semantics; this branch avoids carrying SQLite UDF registration into that rollout.
                $query->orderByRaw("SHA2(CONCAT(?, '|', songs.id), 256) ASC", [$seed]);
            } else {
                throw new SmartPlaylistInvalid('Smart playlist random sort is unavailable.');
            }
            $query->orderBy('songs.id');
            return;
        }

        $column = match ($field) {
            'title' => 'songs.normalized_title',
            'album' => 'albums.normalized_title',
            'artist' => Db::raw('(SELECT MIN(smart_sort_artists.normalized_name) FROM media_song_artists smart_sort_links JOIN media_artists smart_sort_artists ON smart_sort_artists.id = smart_sort_links.artist_id WHERE smart_sort_links.song_id = songs.id)'),
            'release_year' => 'songs.release_year',
            'duration_ms' => 'songs.duration_ms',
            'bitrate' => 'songs.bitrate',
            'play_count' => Db::raw('COALESCE(smart_stats.play_count, 0)'),
            'last_played_at' => 'smart_stats.last_played_at',
            'added_at' => 'songs.created_at',
            default => throw new SmartPlaylistInvalid('Smart playlist sort field is invalid.'),
        };
        $query->orderBy($column, $direction)->orderBy('songs.id');
    }
}
