<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Owns smart-playlist creation, owner rule editing, optimistic versions, preview, and safe projection.
 *
 * Rule JSON is canonicalized before persistence and never interpreted by SQLite. Header plus definition
 * are created or updated in one short `BEGIN IMMEDIATE` transaction; the header version is the only
 * optimistic lock, preventing metadata and rule tabs from overwriting each other. Dynamic song results
 * are deliberately not stored. Every read and preview is recalculated against current grants and
 * personal favorites/history, so revocation or changed personal data takes effect immediately.
 */
final readonly class SmartPlaylistService
{
    public function __construct(
        private SmartPlaylistValidator $validator = new SmartPlaylistValidator(),
        private SmartPlaylistEvaluator $evaluator = new SmartPlaylistEvaluator(),
        private PlaylistService $playlists = new PlaylistService(),
        private AuditLogger $auditLogger = new AuditLogger(),
    ) {
    }

    /**
     * Creates a smart header and canonical definition atomically, then returns its live detail.
     *
     * @param array{name: string, description: string|null, visibility: string} $metadata Canonical
     *     PlaylistValidator output.
     * @param array<string, mixed> $definitionPayload Untrusted rule payload; canonicalized here.
     * @return array<string, mixed> Newly committed detail with dynamic authorized songs.
     */
    public function create(array $actor, array $metadata, array $definitionPayload, string $requestId): array
    {
        $definition = $this->validator->definition($definitionPayload);
        $playlistId = (string) new Ulid();
        $ownerId = (string) $actor['id'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $json = $this->encodeRule($definition['rule']);
        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            Db::table('playlists')->insert([
                'id' => $playlistId, 'owner_user_id' => $ownerId, 'kind' => 'smart',
                'name' => $metadata['name'], 'description' => $metadata['description'],
                'visibility' => $metadata['visibility'], 'song_count' => 0, 'duration_ms' => 0,
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Db::table('smart_playlist_definitions')->insert([
                'playlist_id' => $playlistId, 'rule_json' => $json,
                'sort_field' => $definition['sortField'], 'sort_direction' => $definition['sortDirection'],
                'result_limit' => $definition['resultLimit'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->auditLogger->record($ownerId, 'playlist.smart.create', 'playlist', $playlistId,
                'success', $requestId, ['conditionCount' => $this->conditionCount($definition['rule'])]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return $this->playlists->detail($actor, $playlistId);
    }

    /** Returns an owner-only canonical definition and the shared playlist optimistic version. */
    public function definition(array $actor, string $playlistId): array
    {
        $row = $this->ownedDefinition($actor, $playlistId);
        $definition = $this->decodeDefinition($row);

        return [
            'playlistId' => $playlistId,
            'expectedVersion' => (int) $row->version,
            'rule' => $definition['rule'],
            'sortField' => $definition['sortField'],
            'sortDirection' => $definition['sortDirection'],
            'resultLimit' => $definition['resultLimit'],
        ];
    }

    /**
     * Previews an unpersisted canonical draft under live actor authorization.
     *
     * The draft digest is used only as a stable same-day random seed and is not logged or persisted.
     * A subsequent created playlist has its own seed; saved-rule preview should be used when exact
     * random order equivalence with playback matters.
     */
    public function previewDraft(array $actor, array $definitionPayload): array
    {
        $definition = $this->validator->definition($definitionPayload);
        $seed = 'draft:' . hash('sha256', $this->encodeRule($definition['rule']) . '|'
            . $definition['sortField'] . '|' . $definition['sortDirection'] . '|' . $definition['resultLimit']);

        return ['songs' => $this->evaluator->evaluate($actor, $definition, $seed)];
    }

    /** Previews a saved owner rule; this uses the same playlist/day seed as normal detail reads. */
    public function previewSaved(array $actor, string $playlistId, ?array $draftPayload = null): array
    {
        $row = $this->ownedDefinition($actor, $playlistId);
        $definition = $draftPayload === null
            ? $this->decodeDefinition($row)
            : $this->validator->definition($draftPayload);

        return ['songs' => $this->evaluator->evaluate($actor, $definition, $playlistId)];
    }

    /**
     * Replaces one owner rule under the header's shared optimistic lock and returns the live snapshot.
     *
     * Evaluation happens after commit so a large catalog read never extends the SQLite write lock.
     * A concurrent metadata/rule save causes PlaylistConflict and leaves both rows unchanged.
     */
    public function update(
        array $actor,
        string $playlistId,
        int $expectedVersion,
        array $definitionPayload,
        string $requestId,
    ): array {
        $this->assertPlaylistId($playlistId);
        $definition = $this->validator->definition($definitionPayload);
        $json = $this->encodeRule($definition['rule']);
        $ownerId = (string) $actor['id'];
        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $playlist */
            $playlist = Db::table('playlists')->where('id', $playlistId)
                ->where('owner_user_id', $ownerId)->where('kind', 'smart')->first(['id', 'version']);
            if (!$playlist instanceof stdClass) {
                throw new PlaylistNotFound('Smart playlist not found.');
            }
            if ((int) $playlist->version !== $expectedVersion) {
                throw new PlaylistConflict('智能播放列表规则已在其他页面更新，请重新加载。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('smart_playlist_definitions')->where('playlist_id', $playlistId)->update([
                'rule_json' => $json, 'sort_field' => $definition['sortField'],
                'sort_direction' => $definition['sortDirection'], 'result_limit' => $definition['resultLimit'],
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new PlaylistNotFound('Smart playlist definition not found.');
            }
            Db::table('playlists')->where('id', $playlistId)->where('version', $expectedVersion)->update([
                'version' => $expectedVersion + 1, 'updated_at' => $now,
            ]);
            $this->auditLogger->record($ownerId, 'playlist.smart.rules.update', 'playlist', $playlistId,
                'success', $requestId, ['conditionCount' => $this->conditionCount($definition['rule'])]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return ['playlist' => $this->playlists->detail($actor, $playlistId),
            'definition' => $this->definition($actor, $playlistId)];
    }

    /** Selects an owned smart header and child without exposing another user's private existence. */
    private function ownedDefinition(array $actor, string $playlistId): stdClass
    {
        $this->assertPlaylistId($playlistId);
        /** @var stdClass|null $row */
        $row = Db::table('playlists as playlists')
            ->join('smart_playlist_definitions as smart', 'smart.playlist_id', '=', 'playlists.id')
            ->where('playlists.id', $playlistId)->where('playlists.owner_user_id', (string) $actor['id'])
            ->where('playlists.kind', 'smart')->first([
                'playlists.version', 'smart.rule_json', 'smart.sort_field',
                'smart.sort_direction', 'smart.result_limit',
            ]);
        if (!$row instanceof stdClass) {
            throw new PlaylistNotFound('Smart playlist not found.');
        }

        return $row;
    }

    /** Decodes and revalidates stored JSON so corruption fails closed rather than becoming SQL input. */
    private function decodeDefinition(stdClass $row): array
    {
        try {
            $rule = json_decode((string) $row->rule_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SmartPlaylistInvalid('Stored smart playlist rule is invalid.', previous: $exception);
        }
        if (!is_array($rule)) {
            throw new SmartPlaylistInvalid('Stored smart playlist rule is invalid.');
        }

        return $this->validator->definition([
            'rule' => $rule, 'sortField' => (string) $row->sort_field,
            'sortDirection' => (string) $row->sort_direction, 'resultLimit' => (int) $row->result_limit,
        ]);
    }

    /** Encodes one canonical rule with deterministic flags and a migration-compatible size ceiling. */
    private function encodeRule(array $rule): string
    {
        $json = json_encode($rule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65_536) {
            throw new SmartPlaylistInvalid('Smart playlist rule is too large.');
        }

        return $json;
    }

    /** Counts conditions for privacy-reduced audit metadata without retaining rule values. */
    private function conditionCount(array $node): int
    {
        if (($node['type'] ?? null) === 'condition') {
            return 1;
        }
        $count = 0;
        foreach ($node['children'] ?? [] as $child) {
            $count += $this->conditionCount($child);
        }

        return $count;
    }

    /** Rejects malformed IDs before ownership queries to preserve one not-found boundary. */
    private function assertPlaylistId(string $playlistId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $playlistId) !== 1) {
            throw new PlaylistNotFound('Smart playlist not found.');
        }
    }
}
