<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * Coordinates path-hidden library M3U sources with owner playlists (ND-013/LIST-004).
 *
 * API commands accept opaque source IDs and optimistic versions only. Source selection repeats active
 * library and current-grant authorization; file bytes then pass M3uSourceFileReader's root/stat checks
 * and M3uImportService's no-network parser/matcher. PlaylistService owns the atomic item/rule commit.
 * Automatic scan follow-up uses the same path and records conflict instead of overwriting a playlist
 * edited since the previous sync. No relative/resolved path enters response, audit, or exception text.
 */
final readonly class M3uSyncService
{
    public function __construct(
        private M3uSourceFileReader $reader = new M3uSourceFileReader(),
        private M3uImportService $importer = new M3uImportService(),
        private PlaylistService $playlists = new PlaylistService(),
        private AuditLogger $auditLogger = new AuditLogger(),
    ) {
    }

    /** Returns an owner-only rule snapshot and currently selectable, path-free source labels. */
    public function snapshot(array $actor, string $playlistId): array
    {
        $playlist = $this->ownerPlaylist($actor, $playlistId);
        $sources = $this->sourceQuery($actor)->orderBy('libraries.name')->orderBy('sources.display_name')
            ->orderBy('sources.id')->get([
                'sources.id', 'sources.display_name', 'sources.file_size', 'sources.modified_at',
                'libraries.id as library_id', 'libraries.name as library_name',
            ])->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'displayName' => (string) $row->display_name,
                'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
                'fileSize' => (int) $row->file_size,
                'modifiedAt' => (int) $row->modified_at,
            ])->all();
        /** @var stdClass|null $rule */
        $rule = Db::table('playlist_m3u_sync_rules as rules')
            ->leftJoin('library_m3u_sources as sources', 'sources.id', '=', 'rules.source_id')
            ->leftJoin('music_libraries as libraries', 'libraries.id', '=', 'sources.library_id')
            ->where('rules.playlist_id', $playlistId)->first([
                'rules.source_id', 'rules.enabled', 'rules.status', 'rules.last_playlist_version',
                'rules.version', 'rules.report_json', 'rules.error_code', 'rules.last_checked_at',
                'rules.last_synced_at', 'sources.display_name', 'sources.status as source_status',
                'libraries.id as library_id', 'libraries.name as library_name',
            ]);

        return [
            'rule' => $rule instanceof stdClass ? $this->ruleProjection($rule, (int) $playlist->version) : null,
            'sources' => $sources,
        ];
    }

    /** Binds or rebinds a source and immediately installs its current authorized order atomically. */
    public function bind(
        array $actor,
        string $playlistId,
        string $sourceId,
        int $expectedPlaylistVersion,
        ?int $expectedRuleVersion,
        string $requestId,
    ): array {
        $source = $this->authorizedSource($actor, $playlistId, $sourceId);
        [$file, $plan] = $this->readAndPlan($actor, $source);
        $result = $this->playlists->commitM3uSync(
            $actor, $playlistId, $sourceId, $plan->songIds, $plan->report, $file->digest,
            $expectedPlaylistVersion, $expectedRuleVersion, true, false, $requestId,
        );

        return ['playlist' => $result['playlist'], 'sync' => $this->snapshot($actor, $playlistId)];
    }

    /**
     * Checks one bound source now; `force` is reserved for explicit “use file” conflict resolution.
     */
    public function synchronize(
        array $actor,
        string $playlistId,
        int $expectedPlaylistVersion,
        int $expectedRuleVersion,
        bool $force,
        string $requestId,
    ): array {
        $rule = $this->ownedRule($actor, $playlistId);
        $source = $this->authorizedSource($actor, $playlistId, (string) $rule->source_id);
        [$file, $plan] = $this->readAndPlan($actor, $source);
        $result = $this->playlists->commitM3uSync(
            $actor, $playlistId, (string) $source->id, $plan->songIds, $plan->report, $file->digest,
            $expectedPlaylistVersion, $expectedRuleVersion, false, $force, $requestId,
        );

        return ['playlist' => $result['playlist'], 'sync' => $this->snapshot($actor, $playlistId),
            'applied' => $result['applied']];
    }

    /** Keeps manually edited items and accepts the current file digest as the next-change baseline. */
    public function keepPlaylist(
        array $actor,
        string $playlistId,
        int $expectedPlaylistVersion,
        int $expectedRuleVersion,
        string $requestId,
    ): array {
        $rule = $this->ownedRule($actor, $playlistId);
        $source = $this->authorizedSource($actor, $playlistId, (string) $rule->source_id);
        [$file, $plan] = $this->readAndPlan($actor, $source);
        $playlist = $this->playlists->acceptM3uBaseline(
            $actor, $playlistId, (string) $source->id, $file->digest, $plan->report,
            $expectedPlaylistVersion, $expectedRuleVersion, $requestId,
        );

        return ['playlist' => $playlist, 'sync' => $this->snapshot($actor, $playlistId)];
    }

    /** Removes only the version-checked binding; playlist content and source files are untouched. */
    public function unbind(array $actor, string $playlistId, int $expectedRuleVersion, string $requestId): array
    {
        $this->ownerPlaylist($actor, $playlistId);
        $ownerId = (string) $actor['id'];
        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $rule */
            $rule = Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)->first();
            if (!$rule instanceof stdClass) {
                throw new PlaylistNotFound('Playlist M3U rule not found.');
            }
            if ((int) $rule->version !== $expectedRuleVersion) {
                throw new PlaylistConflict('M3U 同步设置已更新，请重新加载。');
            }
            Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)
                ->where('version', $expectedRuleVersion)->delete();
            $this->auditLogger->record($ownerId, 'playlist.m3u.unbind', 'playlist', $playlistId,
                'success', $requestId, ['sourceId' => (string) $rule->source_id]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return $this->snapshot($actor, $playlistId);
    }

    /**
     * Synchronizes changed sources after a successful library scan without making that scan fail.
     *
     * Each rule is isolated: invalid bytes/identity/grants set that rule to `invalid`; stale concurrent
     * versions are left for the next scan or owner action. A source whose digest did not change performs
     * no playlist write. This method is called only by the scan Worker after complete reconciliation.
     */
    public function synchronizeLibrary(string $libraryId, string $requestId): void
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('playlist_m3u_sync_rules as rules')
            ->join('library_m3u_sources as sources', 'sources.id', '=', 'rules.source_id')
            ->join('playlists', 'playlists.id', '=', 'rules.playlist_id')
            ->join('users', 'users.id', '=', 'playlists.owner_user_id')
            ->where('sources.library_id', $libraryId)->where('sources.status', 'available')
            ->where('rules.enabled', 1)->where('users.status', 'active')
            ->get(['rules.playlist_id', 'rules.source_id', 'rules.source_digest', 'rules.status as rule_status', 'rules.version as rule_version',
                'playlists.version as playlist_version', 'users.id as owner_id', 'users.is_super_admin'])->all();
        foreach ($rows as $row) {
            $actor = ['id' => (string) $row->owner_id, 'isSuperAdmin' => (bool) $row->is_super_admin];
            try {
                $source = $this->authorizedSource($actor, (string) $row->playlist_id, (string) $row->source_id);
                $file = $this->reader->read((string) $row->source_id);
                if ((string) $row->rule_status === 'active' && is_string($row->source_digest)
                    && hash_equals((string) $row->source_digest, $file->digest)) {
                    continue;
                }
                $plan = $this->importer->planForLibrary($actor, $file->bytes, (string) $source->library_id);
                $this->playlists->commitM3uSync(
                    $actor, (string) $row->playlist_id, (string) $row->source_id, $plan->songIds,
                    $plan->report, $file->digest, (int) $row->playlist_version, (int) $row->rule_version,
                    false, false, $requestId,
                );
            } catch (PlaylistConflict) {
                // A concurrent owner command won after this worker snapshot. It is neither invalid
                // source data nor a reason to overwrite newer state; the next scan/manual check retries.
                continue;
            } catch (Throwable) {
                // Automatic sync must not turn a trustworthy media scan into failure. The conditional
                // status write is path-free and cannot overwrite a rule changed by its owner meanwhile.
                Db::table('playlist_m3u_sync_rules')->where('playlist_id', (string) $row->playlist_id)
                    ->where('version', (int) $row->rule_version)->update([
                        'status' => 'invalid', 'error_code' => 'SOURCE_INVALID',
                        'last_checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        'version' => (int) $row->rule_version + 1, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]);
            }
        }
    }

    /** @return array{M3uSourceFile, M3uImportPlan} */
    private function readAndPlan(array $actor, stdClass $source): array
    {
        $file = $this->reader->read((string) $source->id);
        $plan = $this->importer->planForLibrary($actor, $file->bytes, (string) $source->library_id);

        return [$file, $plan];
    }

    /** Returns an owned playlist header or the same non-enumerable not-found response. */
    private function ownerPlaylist(array $actor, string $playlistId): stdClass
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $playlistId) !== 1) {
            throw new PlaylistNotFound('Playlist not found.');
        }
        /** @var stdClass|null $row */
        $playlistQuery = Db::table('playlists')->where('id', $playlistId)
            ->where('owner_user_id', (string) $actor['id']);
        // M3U 同步是用户歌单能力；系统歌单的歌曲变更必须走后台系统歌单服务，
        // 不能因为系统歌单所有者是管理员就借用普通用户的同步入口。
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasColumn('playlists', 'scope')) $playlistQuery->where('scope', 'user');
        $row = $playlistQuery->first(['id', 'kind', 'version']);
        if (!$row instanceof stdClass) {
            throw new PlaylistNotFound('Playlist not found.');
        }
        if ((string) ($row->kind ?? 'manual') !== 'manual') {
            // M3U synchronization materializes a fixed occurrence order. A smart definition owns its
            // result set dynamically, so binding would create two conflicting sources of truth.
            throw new PlaylistInvalid('Smart playlists cannot bind an M3U source.');
        }

        return $row;
    }

    /** Returns the current owned rule; source authorization is deliberately checked separately. */
    private function ownedRule(array $actor, string $playlistId): stdClass
    {
        $this->ownerPlaylist($actor, $playlistId);
        /** @var stdClass|null $row */
        $row = Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)->first();
        if (!$row instanceof stdClass) {
            throw new PlaylistNotFound('Playlist M3U rule not found.');
        }

        return $row;
    }

    /** Selects one source only through the actor's current active-library scope. */
    private function authorizedSource(array $actor, string $playlistId, string $sourceId): stdClass
    {
        $this->ownerPlaylist($actor, $playlistId);
        /** @var stdClass|null $row */
        $row = $this->sourceQuery($actor)->where('sources.id', $sourceId)
            ->first(['sources.id', 'sources.library_id']);
        if (!$row instanceof stdClass) {
            throw new M3uSourceInvalid('source_unavailable');
        }

        return $row;
    }

    /** Builds the reusable source query with active status and live non-admin library grants. */
    private function sourceQuery(array $actor): \Illuminate\Database\Query\Builder
    {
        $query = Db::table('library_m3u_sources as sources')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sources.library_id')
            ->where('sources.status', 'available')->where('libraries.status', 'active');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as source_grants', function ($join) use ($actor): void {
                $join->on('source_grants.library_id', '=', 'sources.library_id')
                    ->where('source_grants.user_id', '=', (string) $actor['id']);
            });
        }

        return $query;
    }

    /** Converts stored report JSON and source metadata to a path-free browser contract. */
    private function ruleProjection(stdClass $row, int $playlistVersion): array
    {
        $report = null;
        if (is_string($row->report_json)) {
            $decoded = json_decode($row->report_json, true);
            $report = is_array($decoded) ? $decoded : null;
        }

        return [
            'source' => [
                'id' => (string) $row->source_id,
                'displayName' => $row->display_name === null ? '不可用源' : (string) $row->display_name,
                'library' => $row->library_id === null ? null : [
                    'id' => (string) $row->library_id, 'name' => (string) $row->library_name,
                ],
            ],
            'enabled' => (bool) $row->enabled,
            'status' => (string) $row->status,
            'locallyModified' => $playlistVersion !== (int) $row->last_playlist_version,
            'version' => (int) $row->version,
            'report' => $report,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'lastCheckedAt' => $row->last_checked_at === null ? null : (string) $row->last_checked_at,
            'lastSyncedAt' => $row->last_synced_at === null ? null : (string) $row->last_synced_at,
        ];
    }
}
