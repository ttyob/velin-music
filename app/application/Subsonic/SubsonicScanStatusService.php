<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Scan\ScanCreateInput;
use app\application\Scan\ScanJobConflict;
use app\application\Scan\ScanJobNotFound;
use app\application\Scan\ScanJobService;
use stdClass;
use support\Db;

/**
 * Builds the caller-scoped read-only Subsonic scan status projection.
 *
 * Global job tables are never exposed directly. The query is restricted to active libraries in the
 * authenticator's current library snapshot, so ordinary users cannot infer another library's job,
 * progress, or scan time. Reading status does not queue, cancel, wake, or otherwise mutate a scan.
 */
final readonly class SubsonicScanStatusService
{
    public function __construct(private ScanJobService $jobs = new ScanJobService())
    {
    }

    /** Returns standard scanning/count plus Navidrome-compatible lastScan/folderCount fields. */
    public function get(array $actor): array
    {
        $libraryIds = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)) {
                $libraryIds[] = $library['id'];
            }
        }
        $libraryIds = array_values(array_unique($libraryIds));
        if ($libraryIds === []) {
            return ['scanStatus' => [
                'scanning' => false,
                'count' => 0,
                'folderCount' => 0,
            ]];
        }

        $active = Db::table('library_scan_jobs')
            ->whereIn('library_id', $libraryIds)
            ->whereIn('status', ['queued', 'running', 'cancel_requested']);
        $result = [
            'scanning' => (clone $active)->exists(),
            'count' => (int) ((clone $active)->sum('processed_entries') ?? 0),
            'folderCount' => count($libraryIds),
        ];
        /** @var stdClass|null $last */
        $last = Db::table('music_libraries')->whereIn('id', $libraryIds)
            ->where('status', 'active')->whereNotNull('last_scanned_at')
            ->orderByDesc('last_scanned_at')->first(['last_scanned_at']);
        if ($last instanceof stdClass) {
            $result['lastScan'] = (string) $last->last_scanned_at;
        }

        return ['scanStatus' => $result];
    }

    /**
     * Atomically queues an incremental or full scan for every library managed by the caller.
     *
     * Subsonic exposes no library selector, so view-only libraries are excluded rather than treated
     * as implicit management targets. The shared ScanJobService proves all targets and conflicts in
     * one transaction; one conflict fails the complete command. This HTTP adapter never traverses
     * files itself. `fullScan` defaults false and accepts only canonical protocol booleans.
     *
     * @param array<string, mixed> $parameters Merged Subsonic query/form parameters.
     */
    public function start(array $actor, array $parameters, string $requestId): array
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('manage_library', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Starting a scan requires library management.');
        }
        $libraryIds = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                && is_string($library['id'] ?? null)) {
                $libraryIds[] = $library['id'];
            }
        }
        if ($libraryIds === []) {
            throw new SubsonicAuthorizationDenied('No manageable music folder is available.');
        }
        $full = $this->boolean($parameters['fullScan'] ?? null, false);
        try {
            $this->jobs->createJobs(
                $libraryIds,
                new ScanCreateInput($full ? 'full' : 'incremental'),
                $actor,
                $requestId,
            );
        } catch (ScanJobConflict $exception) {
            throw new SubsonicRequestInvalid('A music folder already has an active scan.', previous: $exception);
        } catch (ScanJobNotFound $exception) {
            throw new SubsonicAuthorizationDenied('A music folder is not manageable.', previous: $exception);
        }

        return [];
    }

    /** Parses an optional Subsonic boolean without accepting PHP truthiness or ambiguous arrays. */
    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true' => true,
                'false' => false,
                default => throw new SubsonicRequestInvalid('fullScan is invalid.'),
            };
        }

        throw new SubsonicRequestInvalid('fullScan is invalid.');
    }
}
