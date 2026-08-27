<?php

declare(strict_types=1);

namespace app\application\Library;

use stdClass;
use support\Db;

/**
 * Resolves active library scope independently from global role capabilities (AUTH-002).
 *
 * Every media query and cache key must later consume this scope; global `play` or `manage_library`
 * never implies access to all libraries. Super administrators are the sole exception and receive
 * explicit `manage` projections for every active library.
 */
final class LibraryAccessResolver
{
    /** @return list<array{id: string, name: string, accessLevel: string}> */
    public function resolve(string $userId, bool $isSuperAdmin): array
    {
        $query = Db::table('music_libraries as libraries')
            ->where('libraries.status', 'active')
            ->orderBy('libraries.name');
        if ($isSuperAdmin) {
            $rows = $query->get(['libraries.id', 'libraries.name']);

            return $rows->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'accessLevel' => 'manage',
            ])->all();
        }

        $rows = $query
            ->join('library_user_grants as grants', 'grants.library_id', '=', 'libraries.id')
            ->where('grants.user_id', $userId)
            ->get(['libraries.id', 'libraries.name', 'grants.access_level']);

        return $rows->map(static fn (stdClass $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'accessLevel' => (string) $row->access_level,
        ])->all();
    }
}
