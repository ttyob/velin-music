<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Carries one immutable, authorization-scoped import result from matching into persistence.
 *
 * `songIds` retains M3U order and duplicates. `entries` retains every occurrence, including entries
 * that cannot play yet, while omitting the original locator. `report` contains only entry ordinals,
 * status, bounded candidate counts, and already-authorized path-free song projections; locators are
 * deliberately absent. The plan is valid only for the actor and catalog snapshot used to create it.
 * PlaylistService must reauthorize every matched ID before committing, closing the permission race.
 */
final readonly class M3uImportPlan
{
    /**
     * @param list<string> $songIds
     * @param list<array{position: int, status: string, songId?: string, candidateCount?: int, reasonCode?: string}> $entries
     * @param array{summary: array{total: int, matched: int, unmatched: int, ambiguous: int, unsupported: int}, entries: list<array<string, mixed>>} $report
     */
    public function __construct(public array $songIds, public array $report, public array $entries = [])
    {
    }
}
