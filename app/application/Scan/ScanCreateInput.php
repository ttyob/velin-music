<?php

declare(strict_types=1);

namespace app\application\Scan;

/**
 * Immutable command for one administrator-requested library scan.
 *
 * `incremental` currently performs a complete path discovery but retains the caller's intent so a
 * later filesystem-event/change-journal adapter can narrow work without changing the HTTP contract.
 * `full` always requests complete reconciliation. Neither value authorizes filesystem access by
 * itself; the service independently proves the actor's management scope.
 */
final readonly class ScanCreateInput
{
    public function __construct(public string $scanType)
    {
    }
}
