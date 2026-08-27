<?php

declare(strict_types=1);

namespace app\application\Auth;

use support\Db;

/**
 * Resolves effective global capabilities from authoritative role assignments.
 *
 * This service handles only global capability vocabulary. Music-library scope is a separate
 * mandatory filter that will be intersected with these results when library grants are added;
 * callers must never interpret a global media capability as access to every library (USER-006).
 */
final class CapabilityResolver
{
    /**
     * Returns sorted, de-duplicated capabilities for the current request snapshot.
     *
     * Super administrators receive the complete database vocabulary rather than a duplicated PHP
     * list, so new capabilities are not accidentally omitted. Ordinary users receive role grants
     * plus explicitly bound user grants; disabled status is checked by SessionService. Direct grants
     * are intentionally unioned in memory so a role replacement cannot silently remove an unrelated
     * user-level capability in the same request snapshot.
     *
     * @return list<string>
     */
    public function resolve(string $userId, bool $isSuperAdmin): array
    {
        if ($isSuperAdmin) {
            /** @var list<string> $capabilities */
            return Db::table('capabilities')->orderBy('capability_key')->pluck('capability_key')
                ->map(static fn (mixed $value): string => (string) $value)->all();
        }

        $roleCapabilities = Db::table('user_roles')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->pluck('role_capabilities.capability_key')
            ->map(static fn (mixed $value): string => (string) $value)->all();
        // 迁移滚动期间旧测试/只读进程可能尚未看到新表；正式迁移完成后该分支永远命中。
        $directCapabilities = Db::connection()->getSchemaBuilder()->hasTable('user_capabilities')
            ? Db::table('user_capabilities')->where('user_id', $userId)
                ->pluck('capability_key')
                ->map(static fn (mixed $value): string => (string) $value)->all()
            : [];

        $capabilities = array_values(array_unique([...$roleCapabilities, ...$directCapabilities]));
        sort($capabilities);
        return $capabilities;
    }
}
