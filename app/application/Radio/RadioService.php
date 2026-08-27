<?php

declare(strict_types=1);

namespace app\application\Radio;

use app\application\Auth\AuthorizationDenied;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;

/**
 * Owns the shared internet-radio catalog and isolated per-user favorite state.
 *
 * Every read requires `play`. Listeners see enabled rows only; `manage_system` principals may inspect
 * disabled rows so they can repair or re-enable them. Every write rechecks the actor capability here,
 * even when a Controller already checked it. URLs are treated as inert data: this service never
 * resolves DNS, opens a socket, proxies audio, downloads artwork, or sends a submitted URL elsewhere.
 */
final class RadioService
{
    public function __construct(private readonly AuditLogger $auditLogger = new AuditLogger())
    {
    }

    /**
     * Returns one bounded station page with favorite state belonging only to the current actor.
     *
     * Search uses SQL INSTR instead of wildcard LIKE, so `%` and `_` remain literal user input. The
     * total is computed from the same visibility and favorite predicates before pagination.
     *
     * @return array{stations: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function page(
        array $actor,
        int $limit,
        int $offset,
        string $search = '',
        bool $favoritesOnly = false,
    ): array {
        $this->requireCapability($actor, 'play');
        $userId = (string) $actor['id'];
        $query = Db::table('internet_radio_stations as stations')
            ->leftJoin('user_internet_radio_preferences as preferences', function ($join) use ($userId): void {
                $join->on('preferences.station_id', '=', 'stations.id')
                    ->where('preferences.user_id', '=', $userId);
            });
        if (!$this->canManage($actor)) {
            $query->where('stations.enabled', 1);
        }
        if ($favoritesOnly) {
            $query->where('preferences.is_favorite', 1);
        }
        if ($search !== '') {
            // INSTR exists in both SQLite and MySQL and avoids exposing pattern syntax to callers.
            $query->whereRaw('INSTR(LOWER(stations.name), LOWER(?)) > 0', [$search]);
        }
        $total = (clone $query)->count('stations.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('stations.name')->orderBy('stations.id')->offset($offset)->limit($limit)->get([
            'stations.id', 'stations.name', 'stations.stream_url', 'stations.homepage_url',
            'stations.artwork_url', 'stations.enabled', 'stations.version', 'stations.created_at',
            'stations.updated_at', 'preferences.is_favorite',
        ])->all();

        return [
            'stations' => array_map(fn (stdClass $row): array => $this->map($row), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /** Returns one currently readable station without revealing a disabled row to a listener. */
    public function one(array $actor, string $stationId): array
    {
        $this->requireCapability($actor, 'play');
        $row = $this->readableRow($actor, $stationId);

        return $this->map($row);
    }

    /**
     * Creates a server-wide station after management authorization and URL validation.
     *
     * Repeating the command creates another station because the Web API currently has no idempotency
     * key. The insert is the only side effect; it never verifies stream availability over the network.
     *
     * @param array{name: string, streamUrl: string, homepageUrl: string|null, artworkUrl: string|null, enabled: bool, expectedVersion: int|null} $command
     */
    public function create(array $actor, array $command, string $requestId): array
    {
        $this->requireCapability($actor, 'manage_system');
        $stationId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $command, $now, $requestId, $stationId): void {
            Db::table('internet_radio_stations')->insert([
                'id' => $stationId,
                'name' => $command['name'],
                'stream_url' => $command['streamUrl'],
                'homepage_url' => $command['homepageUrl'],
                'artwork_url' => $command['artworkUrl'],
                'enabled' => $command['enabled'] ? 1 : 0,
                'version' => 1,
                'created_by' => (string) $actor['id'],
                'updated_by' => (string) $actor['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            // The station and redacted audit event commit together. A logging failure rolls back the
            // catalog insert, preventing an untraceable administrative mutation.
            $this->auditLogger->record(
                (string) $actor['id'],
                'radio.create',
                'internet_radio_station',
                $stationId,
                'success',
                $requestId,
                ['enabled' => $command['enabled'], 'version' => 1],
            );
        });

        return $this->managedOne($actor, $stationId);
    }

    /**
     * Updates station metadata using either Web optimistic locking or Subsonic last-command-wins.
     *
     * Web commands include expectedVersion and update only that row version. The final affected-row
     * check is authoritative under concurrent SQLite/MySQL writers. Legacy commands atomically use
     * `version + 1`; whichever SQL statement commits last owns the final complete metadata set.
     * No external request runs inside or outside the mutation.
     *
     * @param array{name: string, streamUrl: string, homepageUrl: string|null, artworkUrl: string|null, enabled: bool, expectedVersion: int|null} $command
     */
    public function update(array $actor, string $stationId, array $command, string $requestId): array
    {
        $this->requireCapability($actor, 'manage_system');
        Db::transaction(function () use ($actor, $command, $requestId, $stationId): void {
            $query = Db::table('internet_radio_stations')->where('id', $stationId);
            if ($command['expectedVersion'] !== null) {
                $query->where('version', $command['expectedVersion']);
            }
            $affected = $query->update([
                'name' => $command['name'],
                'stream_url' => $command['streamUrl'],
                'homepage_url' => $command['homepageUrl'],
                'artwork_url' => $command['artworkUrl'],
                'enabled' => $command['enabled'] ? 1 : 0,
                'updated_by' => (string) $actor['id'],
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'version' => Db::raw('version + 1'),
            ]);
            if ($affected !== 1) {
                // The final affected-row result is authoritative. Throwing inside this transaction
                // rolls back any partial work and deliberately emits no success audit record.
                if (Db::table('internet_radio_stations')->where('id', $stationId)->exists()) {
                    throw new RadioConflict('电台已被其他页面或客户端修改，请加载最新版本。');
                }
                throw new RadioNotFound('Radio station was not found.');
            }
            $version = (int) Db::table('internet_radio_stations')->where('id', $stationId)->value('version');
            $this->auditLogger->record(
                (string) $actor['id'],
                'radio.update',
                'internet_radio_station',
                $stationId,
                'success',
                $requestId,
                ['enabled' => $command['enabled'], 'version' => $version],
            );
        });

        return $this->managedOne($actor, $stationId);
    }

    /**
     * Deletes a station definition and cascades its favorites, never media files or stream targets.
     *
     * Web deletion requires an exact version; protocol deletion passes null and is idempotent for an
     * absent canonical ID. Foreign-key cascade is the atomic cleanup boundary for all users' favorite
     * rows. The command does not contact the station URL or delete remote artwork.
     */
    public function delete(array $actor, string $stationId, ?int $expectedVersion, string $requestId): void
    {
        $this->requireCapability($actor, 'manage_system');
        Db::transaction(function () use ($actor, $expectedVersion, $requestId, $stationId): void {
            $query = Db::table('internet_radio_stations')->where('id', $stationId);
            if ($expectedVersion !== null) {
                $query->where('version', $expectedVersion);
            }
            $affected = $query->delete();
            if ($affected === 0 && $expectedVersion !== null) {
                if (Db::table('internet_radio_stations')->where('id', $stationId)->exists()) {
                    throw new RadioConflict('电台已被其他页面或客户端修改，请加载最新版本。');
                }
                throw new RadioNotFound('Radio station was not found.');
            }
            // Protocol deletes are idempotent. The audit schema has one stable success vocabulary;
            // `deleted` distinguishes a real removal from a successful no-op without claiming that
            // an absent station or any remote resource was touched.
            $this->auditLogger->record(
                (string) $actor['id'],
                'radio.delete',
                'internet_radio_station',
                $stationId,
                'success',
                $requestId,
                ['expectedVersion' => $expectedVersion, 'deleted' => $affected === 1],
            );
        });
    }

    /**
     * Sets or clears only the authenticated user's favorite flag for a readable station.
     *
     * The composite key prevents cross-user overwrite. Upsert is supported by both initial SQLite
     * and planned MySQL adapters and makes concurrent identical favorite commands idempotent. Clearing
     * deletes the preference row, leaving the shared station untouched.
     */
    public function favorite(array $actor, string $stationId, bool $favorite): array
    {
        $this->requireCapability($actor, 'play');
        $this->readableRow($actor, $stationId);
        $userId = (string) $actor['id'];
        if (!$favorite) {
            Db::table('user_internet_radio_preferences')
                ->where('user_id', $userId)->where('station_id', $stationId)->delete();

            return $this->one($actor, $stationId);
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('user_internet_radio_preferences')->upsert([[
            'user_id' => $userId,
            'station_id' => $stationId,
            'is_favorite' => 1,
            'favorited_at' => $now,
            'updated_at' => $now,
        ]], ['user_id', 'station_id'], ['is_favorite', 'favorited_at', 'updated_at']);

        return $this->one($actor, $stationId);
    }

    /** Reads a management-visible row and attaches only the manager's own favorite state. */
    private function managedOne(array $actor, string $stationId): array
    {
        $row = $this->readableRow($actor, $stationId);

        return $this->map($row);
    }

    /** Returns a live visibility-filtered row with current-user preference, or one merged not-found. */
    private function readableRow(array $actor, string $stationId): stdClass
    {
        $userId = (string) $actor['id'];
        $query = Db::table('internet_radio_stations as stations')
            ->leftJoin('user_internet_radio_preferences as preferences', function ($join) use ($userId): void {
                $join->on('preferences.station_id', '=', 'stations.id')
                    ->where('preferences.user_id', '=', $userId);
            })
            ->where('stations.id', $stationId);
        if (!$this->canManage($actor)) {
            $query->where('stations.enabled', 1);
        }
        $row = $query->first([
            'stations.id', 'stations.name', 'stations.stream_url', 'stations.homepage_url',
            'stations.artwork_url', 'stations.enabled', 'stations.version', 'stations.created_at',
            'stations.updated_at', 'preferences.is_favorite',
        ]);
        if (!$row instanceof stdClass) {
            throw new RadioNotFound('Radio station was not found or is not readable.');
        }

        return $row;
    }

    /** @return array<string, mixed> Converts inert storage values into a path-free API projection. */
    private function map(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'streamUrl' => (string) $row->stream_url,
            'homepageUrl' => $row->homepage_url !== null ? (string) $row->homepage_url : null,
            'artworkUrl' => $row->artwork_url !== null ? (string) $row->artwork_url : null,
            'enabled' => (int) $row->enabled === 1,
            'isFavorite' => (int) ($row->is_favorite ?? 0) === 1,
            'version' => (int) $row->version,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /** Returns whether this actor may inspect and mutate disabled catalog rows. */
    private function canManage(array $actor): bool
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];

        return in_array('manage_system', $capabilities, true);
    }

    /** Enforces one global capability at the domain boundary without trusting UI visibility. */
    private function requireCapability(array $actor, string $capability): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array($capability, $capabilities, true)) {
            throw new AuthorizationDenied('Internet-radio operation is not authorized.');
        }
    }
}
