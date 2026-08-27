<?php

declare(strict_types=1);

namespace app\domain\System;

/**
 * Supplies infrastructure health details without exposing vendor-specific APIs to the
 * application layer.
 *
 * Implementations must return non-secret diagnostic fields suitable for an unauthenticated
 * readiness endpoint. They may throw when the dependency is unavailable; HealthService
 * converts that failure into a stable degraded result and never returns exception text.
 */
interface HealthProbe
{
    /**
     * Reads a point-in-time dependency health snapshot.
     *
     * @return array<string, bool|int|string|null> A JSON-safe, non-sensitive snapshot.
     */
    public function inspect(): array;
}
