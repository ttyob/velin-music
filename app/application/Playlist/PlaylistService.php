<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\application\Media\MediaQueryService;
use app\application\ResourcePlugin\Contract\PluginDomainEvent;
use app\application\ResourcePlugin\PluginEventPublisher;
use app\infrastructure\Audit\AuditLogger;
use PDO;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 管理普通/智能歌单头的可见性、所有者写入、有序歌曲投影和共享乐观锁版本。
 *
 * 私有歌单只允许所有者读取，server 歌单允许已登录用户读取但仍只允许所有者编辑。每次读取都按当前
 * 音乐库授权重新过滤歌曲；封面只返回受控读取 URL，图片端点会再次校验同一可见性。完整歌曲替换在
 * 短事务中原子提交，允许同一歌曲重复出现且只保存稳定 ID，不保存路径。该服务不处理图片字节，封面
 * 解码与 BLOB 生命周期由 PlaylistCoverService 负责，但两者共享 playlists.version 并发边界。
 */
final class PlaylistService
{
    public function __construct(
        private readonly MediaQueryService $media = new MediaQueryService(),
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly SmartPlaylistEvaluator $smartEvaluator = new SmartPlaylistEvaluator(),
        private readonly PluginEventPublisher $pluginEvents = new PluginEventPublisher(),
    ) {
    }

    /**
     * 分页返回公开可读歌单头，系统歌单固定排在用户歌单之前，歌曲数量和时长只统计当前账号仍有权播放的项目。
     *
     * actor 必须来自认证层，不接受 userId 覆盖。查询只联结封面摘要而不读取 BLOB；coverUrl 不是授权
     * 凭据。任一智能规则损坏会失败关闭当前读取，不会退回旧统计或扩大媒体权限。
     *
     * @param array<string, mixed> $actor 已认证账号及其实时权限上下文。
     * @param 'all'|'user' $scope `all` 为公开 API 默认行为，`user` 仅供已经单独读取系统歌单的页面避免重复。
     * @throws PlaylistInvalid scope 不是公开支持的值。
     * @return array{playlists: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function page(array $actor, int $limit, int $offset, string $scope = 'all'): array
    {
        if (!in_array($scope, ['all', 'user'], true)) {
            throw new PlaylistInvalid('Playlist scope is invalid.');
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $userId = (string) $actor['id'];
        $hasSystemColumns = $this->hasSystemColumns();
        $query = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id');
        if ($hasSystemColumns && $scope === 'all') {
            // 系统歌单必须同时满足 system/server，避免错误 scope 或私有系统记录被公开；
            // 用户歌单仍只允许本人或 server 可见。两个分支放在同一个括号内，确保
            // owner/server 条件不会因 SQL OR 优先级扩大到失效 scope 的记录。
            $query->where(function ($visible) use ($userId): void {
                $visible->where(function ($system): void {
                    $system->where('playlists.scope', 'system')
                        ->where('playlists.visibility', 'server');
                })->orWhere(function ($user) use ($userId): void {
                    $user->where('playlists.scope', 'user')->where(function ($visibility) use ($userId): void {
                        $visibility->where('playlists.owner_user_id', $userId)
                            ->orWhere('playlists.visibility', 'server');
                    });
                });
            });
        } else {
            if ($hasSystemColumns) $query->where('playlists.scope', 'user');
            $query->where(function ($visibility) use ($userId): void {
                $visibility->where('playlists.owner_user_id', $userId)
                    ->orWhere('playlists.visibility', 'server');
            });
        }
        $total = (clone $query)->count('playlists.id');
        /** @var list<stdClass> $rows */
        $columns = [
                'playlists.id', 'playlists.owner_user_id', 'playlists.name', 'playlists.description',
                'playlists.kind', 'playlists.visibility', 'playlists.version',
                'playlists.created_at', 'playlists.updated_at',
                'covers.content_sha256 as cover_content_sha256',
                'owners.username as owner_username', 'owners.display_name as owner_display_name',
            ];
        if ($hasSystemColumns) $columns = array_merge($columns, ['playlists.scope', 'playlists.source']);
        if ($hasSystemColumns && $scope === 'all') {
            $query->orderByRaw("CASE WHEN playlists.scope = 'system' THEN 0 ELSE 1 END");
        }
        $rows = $query->orderByDesc('playlists.updated_at')->orderBy('playlists.name')->orderBy('playlists.id')
            ->offset($offset)->limit($limit)->get($columns)->all();
        $playlistIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $visible = $this->visibleItemsForRows($actor, $rows);

        return [
            'playlists' => array_map(fn (stdClass $row): array => $this->summary($row, $userId, $visible[(string) $row->id] ?? []), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * 分页返回只读系统歌单。系统歌单和用户歌单使用同一实时媒体授权投影；该独立端点供需要
     * 单独渲染系统区域的客户端使用，公开 `/playlists` 则会把系统歌单置顶合并返回。
     * 上游刷新失败不会在此读取路径删除已落地项目，失去库权限的歌曲仅从当前响应省略。
     *
     * @return array{playlists:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function systemPage(array $actor, int $limit, int $offset): array
    {
        if (!$this->hasSystemColumns()) return ['playlists' => [], 'total' => 0, 'limit' => max(1, min(100, $limit)), 'offset' => max(0, $offset)];
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->where('playlists.scope', 'system')->where('playlists.visibility', 'server');
        $total = (clone $query)->count('playlists.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('playlists.updated_at')->orderBy('playlists.name')->orderBy('playlists.id')
            ->offset($offset)->limit($limit)->get([
                'playlists.id', 'playlists.owner_user_id', 'playlists.name', 'playlists.description',
                'playlists.kind', 'playlists.visibility', 'playlists.scope', 'playlists.source', 'playlists.version',
                'playlists.created_at', 'playlists.updated_at', 'covers.content_sha256 as cover_content_sha256',
                'owners.username as owner_username', 'owners.display_name as owner_display_name',
            ])->all();
        $visible = $this->visibleItemsForRows($actor, $rows);
        return [
            'playlists' => array_map(fn (stdClass $row): array => $this->summary($row, (string) $actor['id'], $visible[(string) $row->id] ?? []), $rows),
            'total' => $total, 'limit' => $limit, 'offset' => $offset,
        ];
    }

    /**
     * 返回一个可读歌单及当前授权歌曲的稳定顺序，并携带可为空的受控封面 URL。
     *
     * 私有失权和不存在统一抛出 PlaylistNotFound；歌曲失权只从投影省略而不删除稳定引用。该读取无数据库
     * 副作用，封面摘要格式损坏时只省略 URL，真正图片读取仍会复验 BLOB 的完整 SHA-256 后失败关闭。
     */
    public function detail(array $actor, string $playlistId): array
    {
        $row = $this->readableRow($actor, $playlistId);
        $items = $this->visibleItemsForRows($actor, [$row])[$playlistId] ?? [];
        $summary = $this->summary($row, (string) $actor['id'], $items);

        return $summary + [
            'songs' => array_map(
                static fn (array $item, int $index): array => ['position' => $index, 'song' => $item['song']],
                $items,
                array_keys($items),
            ),
            // 导入歌单额外返回所有原始条目的状态；普通歌单没有导入条目时返回空数组，保持协议稳定。
            'importEntries' => $this->importEntries($actor, $playlistId),
        ];
    }

    /**
     * 返回管理后台查看的用户或系统歌单详情，并继续应用管理员当前音乐库授权。
     *
     * 该入口只应由已经校验 `manage_system` 的后台服务调用；它绕过用户歌单的 owner/server 可见性判断，
     * 但不会绕过歌曲和音乐库授权，因而管理员仍只能看到当前有权管理的媒体。读取无写副作用，歌单被
     * 删除、媒体失权或导入条目损坏时沿用普通详情的失败关闭与脱敏投影。
     */
    public function detailForAdmin(array $actor, string $playlistId): array
    {
        $row = $this->adminReadableRow($playlistId);
        $items = $this->visibleItemsForRows($actor, [$row])[$playlistId] ?? [];
        $summary = $this->summary($row, (string) $actor['id'], $items);
        // 私有用户歌单的公共封面端点仍只接受所有者；后台详情不返回无法由当前管理员读取的封面 URL。
        if (($summary['scope'] ?? null) === 'user') $summary['coverUrl'] = null;

        return $summary + [
            'songs' => array_map(
                static fn (array $item, int $index): array => ['position' => $index, 'song' => $item['song']],
                $items,
                array_keys($items),
            ),
            'importEntries' => $this->importEntries($actor, $playlistId),
        ];
    }

    /** Creates an empty owner-only mutable playlist; repeating without idempotency creates a new list. */
    public function create(array $actor, array $metadata): array
    {
        return $this->createWithItems($actor, $metadata, []);
    }

    /**
     * Atomically creates an owner playlist and its complete authorized song order.
     *
     * Song authorization and duration calculation happen before the short write transaction. The
     * transaction inserts header and children together, so a failed item insert cannot leave an
     * unexpected empty playlist behind. Duplicate song occurrences are retained, and repeating the
     * call intentionally creates a new playlist because this API has no idempotency key.
     *
     * @param array<string, mixed> $actor Authenticated owner whose current grants authorize songs.
     * @param array{name: string, description: string|null, visibility: string} $metadata Validated metadata.
     * @param list<string> $songIds Validated stable song IDs, including intentional duplicates.
     * @return array<string, mixed> Newly committed playlist detail with visible song projections.
     * @throws PlaylistItemNotFound At least one unique song is unavailable or unauthorized.
     */
    public function createWithItems(array $actor, array $metadata, array $songIds): array
    {
        [$songs, $durationMs] = $this->prepareSongOrder($actor, $songIds);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $playlistId = (string) new Ulid();
        Db::transaction(function () use ($actor, $durationMs, $metadata, $now, $playlistId, $songIds, $songs): void {
            Db::table('playlists')->insert([
                'id' => $playlistId,
                'owner_user_id' => (string) $actor['id'],
                'kind' => 'manual',
                'name' => $metadata['name'],
                'description' => $metadata['description'],
                'visibility' => $metadata['visibility'],
                'song_count' => count($songIds),
                'duration_ms' => $durationMs,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertItems($playlistId, (string) $actor['id'], $songIds, $songs, $now);
        });
        $this->publishPlaylistChanged($actor, $playlistId, 'created', ['songCount' => count($songIds), 'version' => 1]);

        return $this->detail($actor, $playlistId);
    }

    /**
     * Atomically creates an imported list once and replays its original privacy-reduced report.
     *
     * Song authorization happens before acquiring SQLite's write reservation and every unique ID is
     * checked again through the same live scope used by normal playlist writes. Under BEGIN IMMEDIATE,
     * an expired owner retry row is cleaned up, then a matching owner/key/request returns the existing
     * playlist without a second audit or version change. Reusing the key for different normalized
     * input raises PlaylistConflict. Header, ordered items, retry report, and redacted audit commit or
     * roll back together; uploaded bytes, paths, labels, titles, and raw idempotency keys are not stored.
     *
     * @param array<string, mixed> $actor Authenticated owner whose current grants authorize songs.
     * @param array{name: string, description: string|null, visibility: string} $metadata Validated metadata.
     * @param list<string> $songIds Matched occurrence order including intentional duplicates.
     * @param list<array<string, mixed>> $importEntries Every imported occurrence, including unmatched ones.
     * @param array<string, mixed> $report Path-free report containing only authorized projections.
     * `$scope=system` 只允许已经由管理端完成 capability 校验的调用者使用；本服务仍强制公开可见并在同一
     * 创建事务写入来源字段，避免先创建个人歌单再转换时出现短暂越界。默认值保持普通用户导入兼容。
     *
     * @return array{playlist: array<string, mixed>, report: array<string, mixed>, replayed: bool}
     */
    public function createImported(
        array $actor,
        array $metadata,
        array $songIds,
        array $importEntries,
        array $report,
        string $keyDigest,
        string $requestDigest,
        string $requestId,
        string $scope = 'user',
    ): array {
        if (!in_array($scope, ['user', 'system'], true)) {
            throw new PlaylistImportInvalid('invalid_playlist_scope');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $keyDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $requestDigest) !== 1) {
            throw new PlaylistImportInvalid('invalid_idempotency_digest');
        }
        [$songs, $durationMs] = $this->prepareSongOrder($actor, $songIds);
        $reportJson = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (strlen($reportJson) > 1_048_576) {
            throw new PlaylistImportInvalid('report_too_large');
        }
        $ownerId = (string) $actor['id'];
        $playlistId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $storedReport = $report;
        $replayed = false;
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            // Cleanup is restricted to the current owner and cannot delete a playlist, audit event,
            // another user's retry key, or an active idempotency decision.
            Db::table('playlist_import_idempotency')->where('owner_user_id', $ownerId)
                ->where('expires_at', '<=', $now)->delete();
            /** @var stdClass|null $existing */
            $existing = Db::table('playlist_import_idempotency')->where('owner_user_id', $ownerId)
                ->where('key_digest', $keyDigest)->first();
            if ($existing instanceof stdClass) {
                if (!hash_equals((string) $existing->request_digest, $requestDigest)) {
                    throw new PlaylistConflict('该幂等键已用于其他播放列表导入，请重新选择文件。');
                }
                $playlistId = (string) $existing->playlist_id;
                $decoded = json_decode((string) $existing->report_json, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new PlaylistImportInvalid('stored_report_invalid');
                }
                $storedReport = $decoded;
                $replayed = true;
            } else {
                $playlist = [
                    'id' => $playlistId,
                    'owner_user_id' => $ownerId,
                    'kind' => 'manual',
                    'name' => $metadata['name'],
                    'description' => $metadata['description'],
                    'visibility' => $scope === 'system' ? 'server' : $metadata['visibility'],
                    'song_count' => count($songIds),
                    'duration_ms' => $durationMs,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($this->hasSystemColumns()) {
                    $playlist += ['scope' => $scope, 'source' => 'manual', 'source_key' => null];
                }
                Db::table('playlists')->insert($playlist);
                $this->insertItems($playlistId, $ownerId, $songIds, $songs, $now);
                $this->insertImportEntries($playlistId, $importEntries, $songs, $now);
                Db::table('playlist_import_idempotency')->insert([
                    'owner_user_id' => $ownerId,
                    'key_digest' => $keyDigest,
                    'request_digest' => $requestDigest,
                    'playlist_id' => $playlistId,
                    'report_json' => $reportJson,
                    'created_at' => $now,
                    'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86_400),
                ]);
                $this->auditLogger->record(
                    $ownerId,
                    'playlist.import',
                    'playlist',
                    $playlistId,
                    'success',
                    $requestId,
                    [
                        'entryCount' => (int) ($report['summary']['total'] ?? 0),
                        'matchedCount' => count($songIds),
                        'ambiguousCount' => (int) ($report['summary']['ambiguous'] ?? 0),
                        'unmatchedCount' => (int) ($report['summary']['unmatched'] ?? 0),
                        'scope' => $scope,
                    ],
                );
            }
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        if (!$replayed) {
            $this->publishPlaylistChanged($actor, $playlistId, 'created', [
                'songCount' => count($songIds),
                'version' => 1,
                'imported' => true,
            ]);
        }
        return [
            'playlist' => $this->detail($actor, $playlistId),
            'report' => $storedReport,
            'replayed' => $replayed,
        ];
    }

    /** Updates owner-controlled metadata under a short version-checked transaction. */
    public function update(array $actor, string $playlistId, array $command): array
    {
        $this->mutateHeader($actor, $playlistId, (int) $command['expectedVersion'], function (stdClass $row) use ($command): void {
            Db::table('playlists')->where('id', (string) $row->id)->where('version', (int) $row->version)->update([
                'name' => $command['name'],
                'description' => $command['description'],
                'visibility' => $command['visibility'],
                'version' => (int) $row->version + 1,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        });
        $this->publishPlaylistChanged($actor, $playlistId, 'updated', [
            'version' => (int) $command['expectedVersion'] + 1,
        ]);

        return $this->detail($actor, $playlistId);
    }

    /**
     * Replaces the complete ordered song list after proving every unique song is currently visible.
     *
     * Authorization runs before BEGIN IMMEDIATE to keep the write lock SQL-only. Inside the lock,
     * owner and version are rechecked, old items are replaced, denormalized totals are updated, and
     * all changes commit together. A failure rolls back the original list and version unchanged.
     */
    public function replaceItems(array $actor, string $playlistId, array $command): array
    {
        /** @var list<string> $songIds */
        $songIds = $command['songIds'];
        [$songs, $durationMs] = $this->prepareSongOrder($actor, $songIds);
        $this->mutateHeader($actor, $playlistId, (int) $command['expectedVersion'], function (stdClass $row) use ($actor, $durationMs, $songIds, $songs): void {
            $this->assertManualPlaylist($row);
            $playlistId = (string) $row->id;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
            $this->insertItems($playlistId, (string) $actor['id'], $songIds, $songs, $now);
            Db::table('playlists')->where('id', $playlistId)->where('version', (int) $row->version)->update([
                'song_count' => count($songIds),
                'duration_ms' => $durationMs,
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);
        });
        $this->publishPlaylistChanged($actor, $playlistId, 'items_replaced', [
            'songCount' => count($songIds),
            'version' => (int) $command['expectedVersion'] + 1,
        ]);

        return $this->detail($actor, $playlistId);
    }

    /**
     * 在用户歌单末尾登记一条可以暂时没有本地资源的歌曲。
     *
     * 调用者必须是歌单所有者且拥有 `create_playlist`；服务只写脱敏歌名、艺人、专辑和 unmatched 状态，
     * 不接受路径、URL 或伪造媒体 ID。位置、所有权和 expectedVersion 在同一个 `BEGIN IMMEDIATE` 事务中
     * 复验；成功只递增歌单版本，song_count/duration_ms 保持真实可播放媒体统计。重复登记是允许的，关闭
     * 自动补全不会删除该记录，开启时由自动补全 Worker 继续处理。
     *
     * @param array{expectedVersion:int,title:string,artist:string,album:string|null} $command
     * @return array<string,mixed> 更新后的完整歌单详情。
     */
    public function addManualEntry(array $actor, string $playlistId, array $command): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('playlist_import_entries')) {
            throw new PlaylistInvalid('playlist entries are unavailable.');
        }
        $this->mutateHeader($actor, $playlistId, (int) $command['expectedVersion'], function (stdClass $row) use ($command): void {
            $this->assertManualPlaylist($row);
            $playlistId = (string) $row->id;
            $entries = Db::table('playlist_import_entries')->where('playlist_id', $playlistId);
            if ((int) $entries->count() >= 1000) throw new PlaylistInvalid('playlist entries exceed 1000 items.');
            $now = gmdate('Y-m-d\TH:i:s\Z');
            // 普通手工歌单历史上只有 playlist_items；首次登记缺失资源时先把已有歌曲
            // 建立为 matched 条目，才能让客户端按一个统一的位置序列混排歌曲和缺失项。
            // 该回填与新条目处于同一版本锁事务内，失败会整体回滚，不会留下半套条目。
            if ((int) $entries->count() === 0) {
                $existingItems = Db::table('playlist_items')->where('playlist_id', $playlistId)
                    ->orderBy('position')->get(['position', 'song_id'])->all();
                if (count($existingItems) > 0) {
                    $matched = [];
                    foreach ($existingItems as $item) {
                        $matched[] = [
                            'playlist_id' => $playlistId,
                            'position' => (int) $item->position,
                            'status' => 'matched',
                            'song_id' => (string) $item->song_id,
                            'candidate_count' => null,
                            'reason_code' => null,
                            'created_at' => $now,
                        ];
                    }
                    Db::table('playlist_import_entries')->insert($matched);
                }
            }
            $position = ((int) (Db::table('playlist_import_entries')->where('playlist_id', $playlistId)->max('position') ?? -1)) + 1;
            if ($position >= 1000) throw new PlaylistInvalid('playlist entries exceed 1000 items.');
            $entry = [
                'playlist_id' => $playlistId,
                'position' => $position,
                'status' => 'unmatched',
                'song_id' => null,
                'candidate_count' => null,
                'reason_code' => 'manual_missing',
                'created_at' => $now,
            ];
            $schema = Db::connection()->getSchemaBuilder();
            if ($schema->hasColumn('playlist_import_entries', 'source_title')) {
                $entry += [
                    'source_title' => $command['title'],
                    'source_artists_json' => json_encode([$command['artist']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'source_album' => $command['album'],
                ];
            }
            Db::table('playlist_import_entries')->insert($entry);
            Db::table('playlists')->where('id', $playlistId)->where('version', (int) $row->version)->update([
                'version' => (int) $row->version + 1,
                'updated_at' => $now,
            ]);
        });
        $this->publishPlaylistChanged($actor, $playlistId, 'entry_added', [
            'version' => (int) $command['expectedVersion'] + 1,
        ]);

        return $this->detail($actor, $playlistId);
    }

    /**
     * Atomically replaces owner metadata and the complete visible song order in one version step.
     *
     * This is the shared transaction boundary for protocol adapters whose single command can change
     * both header and items. Authorization of the target song set occurs before acquiring SQLite's
     * write reservation; owner and expectedVersion are then rechecked under `BEGIN IMMEDIATE`.
     * Failure rolls back metadata, items, denormalized totals, and version together.
     *
     * @param array<string, mixed> $actor Authenticated owner whose current grants authorize songs.
     * @param array{expectedVersion: int, name: string, description: string|null, visibility: string, songIds: list<string>} $command Validated complete target state.
     * @return array<string, mixed> Committed playlist detail.
     */
    public function replaceAll(array $actor, string $playlistId, array $command): array
    {
        $songIds = $command['songIds'];
        [$songs, $durationMs] = $this->prepareSongOrder($actor, $songIds);
        $this->mutateHeader(
            $actor,
            $playlistId,
            $command['expectedVersion'],
            function (stdClass $row) use ($actor, $command, $durationMs, $songIds, $songs): void {
                $this->assertManualPlaylist($row);
                $playlistId = (string) $row->id;
                $now = gmdate('Y-m-d\TH:i:s\Z');
                Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
                $this->insertItems($playlistId, (string) $actor['id'], $songIds, $songs, $now);
                Db::table('playlists')->where('id', $playlistId)->where('version', (int) $row->version)->update([
                    'name' => $command['name'],
                    'description' => $command['description'],
                    'visibility' => $command['visibility'],
                    'song_count' => count($songIds),
                    'duration_ms' => $durationMs,
                    'version' => (int) $row->version + 1,
                    'updated_at' => $now,
                ]);
            },
        );
        $this->publishPlaylistChanged($actor, $playlistId, 'replaced', [
            'songCount' => count($songIds),
            'version' => (int) $command['expectedVersion'] + 1,
        ]);

        return $this->detail($actor, $playlistId);
    }

    /**
     * Atomically binds/synchronizes one revalidated M3U source and the complete playlist order.
     *
     * Planning and song authorization happen before SQLite's write reservation. Under BEGIN IMMEDIATE,
     * ownership, caller-observed playlist/rule versions, source binding, and the previous synchronized
     * playlist version are rechecked. A normal source update replaces items only when the playlist has
     * not been edited since the previous sync; otherwise only the rule enters `conflict`. `force` is an
     * explicit conflict resolution and still requires the caller's current versions. Header totals,
     * items, rule digest/report/status/version, and redacted audit commit or roll back together.
     *
     * @param list<string> $songIds Authorized source-library order, including intentional duplicates.
     * @param array<string, mixed> $report Path-free parser report; locator text must be absent.
     * @return array{playlist: array<string, mixed>, status: string, applied: bool}
     */
    public function commitM3uSync(
        array $actor,
        string $playlistId,
        string $sourceId,
        array $songIds,
        array $report,
        string $sourceDigest,
        int $expectedPlaylistVersion,
        ?int $expectedRuleVersion,
        bool $binding,
        bool $force,
        string $requestId,
    ): array {
        $this->assertPlaylistId($playlistId);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $sourceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sourceDigest) !== 1) {
            throw new PlaylistInvalid('M3U source identity is invalid.');
        }
        [$songs, $durationMs] = $this->prepareSongOrder($actor, $songIds);
        $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($reportJson) > 1_048_576) {
            throw new PlaylistImportInvalid('report_too_large');
        }
        $ownerId = (string) $actor['id'];
        $status = 'active';
        $applied = false;
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            /** @var stdClass|null $playlist */
            $playlistQuery = Db::table('playlists')->where('id', $playlistId)
                ->where('owner_user_id', $ownerId);
            // M3U 是用户侧的可写同步规则；系统歌单即使历史上错误绑定了规则，也不能
            // 因为拥有者恰好是管理员而被普通歌单命令改写。后台系统歌单必须走独立管理入口。
            if ($this->hasSystemColumns()) $playlistQuery->where('scope', 'user');
            $playlist = $playlistQuery->first(['id', 'kind', 'version']);
            if (!$playlist instanceof stdClass) {
                throw new PlaylistNotFound('Playlist not found.');
            }
            $this->assertManualPlaylist($playlist);
            if ((int) $playlist->version !== $expectedPlaylistVersion) {
                throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
            }
            $this->assertM3uSourceGrant($actor, $sourceId);
            /** @var stdClass|null $rule */
            $rule = Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)->first();
            if ($binding) {
                if (($rule === null) !== ($expectedRuleVersion === null)
                    || ($rule instanceof stdClass && (int) $rule->version !== $expectedRuleVersion)) {
                    throw new PlaylistConflict('M3U 同步设置已更新，请重新加载。');
                }
            } elseif (!$rule instanceof stdClass
                || (string) $rule->source_id !== $sourceId
                || (int) $rule->version !== $expectedRuleVersion) {
                throw new PlaylistConflict('M3U 同步设置已更新，请重新加载。');
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $localEdit = $rule instanceof stdClass
                && (int) $playlist->version !== (int) $rule->last_playlist_version;
            if (!$binding && !$force && is_string($rule->source_digest)
                && hash_equals((string) $rule->source_digest, $sourceDigest)) {
                // A local edit by itself is not a conflict. Keeping last_playlist_version unchanged
                // makes a later real source change detect the two-sided divergence as intended.
                Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)
                    ->where('version', (int) $rule->version)->update([
                        'status' => 'active', 'error_code' => null, 'last_checked_at' => $now, 'updated_at' => $now,
                    ]);
            } elseif (!$binding && !$force && $localEdit) {
                $status = 'conflict';
                Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)
                    ->where('version', (int) $rule->version)->update([
                        'status' => 'conflict',
                        'report_json' => $reportJson,
                        'error_code' => 'PLAYLIST_MODIFIED',
                        'last_checked_at' => $now,
                        'version' => (int) $rule->version + 1,
                        'updated_at' => $now,
                    ]);
            } else {
                Db::table('playlist_items')->where('playlist_id', $playlistId)->delete();
                $this->insertItems($playlistId, $ownerId, $songIds, $songs, $now);
                $newPlaylistVersion = (int) $playlist->version + 1;
                Db::table('playlists')->where('id', $playlistId)->where('version', (int) $playlist->version)->update([
                    'song_count' => count($songIds), 'duration_ms' => $durationMs,
                    'version' => $newPlaylistVersion, 'updated_at' => $now,
                ]);
                $ruleValues = [
                    'source_id' => $sourceId, 'enabled' => 1, 'status' => 'active',
                    'source_digest' => $sourceDigest, 'last_playlist_version' => $newPlaylistVersion,
                    'report_json' => $reportJson, 'error_code' => null, 'last_checked_at' => $now,
                    'last_synced_at' => $now, 'updated_at' => $now,
                ];
                if ($rule instanceof stdClass) {
                    $ruleValues['version'] = (int) $rule->version + 1;
                    Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)
                        ->where('version', (int) $rule->version)->update($ruleValues);
                } else {
                    Db::table('playlist_m3u_sync_rules')->insert(['playlist_id' => $playlistId]
                        + $ruleValues + ['version' => 1, 'created_at' => $now]);
                }
                $applied = true;
                $this->auditLogger->record($ownerId, $binding ? 'playlist.m3u.bind' : 'playlist.m3u.sync',
                    'playlist', $playlistId, 'success', $requestId, [
                        'sourceId' => $sourceId,
                        'matchedCount' => count($songIds),
                        'forced' => $force,
                    ]);
            }
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return ['playlist' => $this->detail($actor, $playlistId), 'status' => $status, 'applied' => $applied];
    }

    /**
     * Resolves a conflict by retaining the current playlist and accepting current source bytes as baseline.
     *
     * No item row changes. Both optimistic versions and ownership are checked under BEGIN IMMEDIATE;
     * the rule then records the caller-observed playlist version and source digest so only a later file
     * change can trigger synchronization. Report and redacted audit are committed in the same transaction.
     */
    public function acceptM3uBaseline(
        array $actor,
        string $playlistId,
        string $sourceId,
        string $sourceDigest,
        array $report,
        int $expectedPlaylistVersion,
        int $expectedRuleVersion,
        string $requestId,
    ): array {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $sourceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $sourceDigest) !== 1) {
            throw new PlaylistInvalid('M3U source identity is invalid.');
        }
        $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($reportJson) > 1_048_576) {
            throw new PlaylistImportInvalid('report_too_large');
        }
        $ownerId = (string) $actor['id'];
        $this->mutateM3uRule($actor, $ownerId, $playlistId, $sourceId, $expectedPlaylistVersion, $expectedRuleVersion,
            function (stdClass $playlist, stdClass $rule) use ($ownerId, $playlistId, $reportJson, $requestId, $sourceDigest): void {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)
                    ->where('version', (int) $rule->version)->update([
                        'source_digest' => $sourceDigest, 'last_playlist_version' => (int) $playlist->version,
                        'status' => 'active', 'report_json' => $reportJson, 'error_code' => null,
                        'last_checked_at' => $now, 'last_synced_at' => $now,
                        'version' => (int) $rule->version + 1, 'updated_at' => $now,
                    ]);
                $this->auditLogger->record($ownerId, 'playlist.m3u.keep_playlist', 'playlist', $playlistId,
                    'success', $requestId, ['sourceId' => (string) $rule->source_id]);
            });

        return $this->detail($actor, $playlistId);
    }

    /** Runs a source-rule-only mutation under the same ownership and dual-version lock contract. */
    private function mutateM3uRule(
        array $actor,
        string $ownerId,
        string $playlistId,
        string $sourceId,
        int $expectedPlaylistVersion,
        int $expectedRuleVersion,
        callable $mutation,
    ): void {
        $this->assertPlaylistId($playlistId);
        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $playlist */
            $playlistQuery = Db::table('playlists')->where('id', $playlistId)->where('owner_user_id', $ownerId);
            if ($this->hasSystemColumns()) $playlistQuery->where('scope', 'user');
            $playlist = $playlistQuery->first(['id', 'kind', 'version']);
            /** @var stdClass|null $rule */
            $rule = Db::table('playlist_m3u_sync_rules')->where('playlist_id', $playlistId)->first();
            if (!$playlist instanceof stdClass || !$rule instanceof stdClass || (string) $rule->source_id !== $sourceId) {
                throw new PlaylistNotFound('Playlist M3U rule not found.');
            }
            $this->assertManualPlaylist($playlist);
            $this->assertM3uSourceGrant($actor, $sourceId);
            if ((int) $playlist->version !== $expectedPlaylistVersion || (int) $rule->version !== $expectedRuleVersion) {
                throw new PlaylistConflict('播放列表或 M3U 同步设置已更新，请重新加载。');
            }
            $mutation($playlist, $rule);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }
    }

    /**
     * Repeats source-library authorization inside the final write reservation.
     *
     * The reader already checked authorization before filesystem access. This SQL-only check closes
     * the revocation race between reading/planning and commit; non-admin absence is indistinguishable
     * from an unavailable source. It never selects source paths or returns library metadata.
     */
    private function assertM3uSourceGrant(array $actor, string $sourceId): void
    {
        $query = Db::table('library_m3u_sources as sync_sources')
            ->join('music_libraries as sync_libraries', 'sync_libraries.id', '=', 'sync_sources.library_id')
            ->where('sync_sources.id', $sourceId)->where('sync_sources.status', 'available')
            ->where('sync_libraries.status', 'active');
        if (!($actor['isSuperAdmin'] ?? false)) {
            $query->join('library_user_grants as sync_grants', function ($join) use ($actor): void {
                $join->on('sync_grants.library_id', '=', 'sync_sources.library_id')
                    ->where('sync_grants.user_id', '=', (string) $actor['id']);
            });
        }
        if (!$query->exists()) {
            throw new M3uSourceInvalid('source_unavailable');
        }
    }

    /** Deletes an owner playlist only when expectedVersion still matches; cascade removes items. */
    public function delete(array $actor, string $playlistId, int $expectedVersion): void
    {
        $this->mutateHeader($actor, $playlistId, $expectedVersion, static function (stdClass $row): void {
            Db::table('playlists')->where('id', (string) $row->id)->where('version', (int) $row->version)->delete();
        });
        $this->publishPlaylistChanged($actor, $playlistId, 'deleted', ['version' => $expectedVersion]);
    }

    /**
     * 在歌单 SQLite 提交之后发送有限期扩展通知。
     *
     * payload 只能包含版本、数量和动作等可重建摘要；歌曲顺序、标题、导入原文和可见性不进入 Redis。
     * Redis 故障只丢失通知，不能回滚歌单或把已提交版本再次执行。
     *
     * @param array<string,mixed> $payload
     */
    private function publishPlaylistChanged(array $actor, string $playlistId, string $action, array $payload = []): void
    {
        $this->pluginEvents->publish(
            PluginDomainEvent::PLAYLIST_CHANGED,
            'playlist',
            $playlistId,
            'user',
            (string) $actor['id'],
            ['action' => $action] + $payload,
        );
    }

    /**
     * Runs one owner mutation under SQLite's early write reservation and rolls back on any failure.
     *
     * @param callable(stdClass): void $mutation SQL-only mutation that must consume the locked row.
     */
    private function mutateHeader(
        array $actor,
        string $playlistId,
        int $expectedVersion,
        callable $mutation,
    ): void {
        $this->assertPlaylistId($playlistId);
        $pdo = Db::connection()->getPdo();
        $transactionOpen = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            /** @var stdClass|null $row */
            $mutationColumns = ['id', 'kind', 'version'];
            if ($this->hasSystemColumns()) $mutationColumns[] = 'scope';
            $row = Db::table('playlists')->where('id', $playlistId)
                ->where('owner_user_id', (string) $actor['id'])->first($mutationColumns);
            if (!$row instanceof stdClass) {
                throw new PlaylistNotFound('Playlist not found.');
            }
            if ((string) ($row->scope ?? 'user') !== 'user') {
                throw new PlaylistInvalid('系统推荐歌单为只读资源。');
            }
            if ((int) $row->version !== $expectedVersion) {
                throw new PlaylistConflict('播放列表已在其他页面更新，请重新加载。');
            }
            $mutation($row);
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }
    }

    /** Returns one header inside owner-or-server visibility without revealing private existence. */
    private function readableRow(array $actor, string $playlistId): stdClass
    {
        $this->assertPlaylistId($playlistId);
        $userId = (string) $actor['id'];
        $columns = [
            'playlists.id', 'playlists.owner_user_id', 'playlists.name', 'playlists.description',
            'playlists.kind', 'playlists.visibility', 'playlists.version', 'playlists.created_at', 'playlists.updated_at',
            'covers.content_sha256 as cover_content_sha256', 'owners.username as owner_username', 'owners.display_name as owner_display_name',
        ];
        if ($this->hasSystemColumns()) $columns = array_merge($columns, ['playlists.scope', 'playlists.source']);
        /** @var stdClass|null $row */
        $row = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->where('playlists.id', $playlistId)
            ->where(function ($visibility) use ($userId): void {
                $visibility->where('playlists.owner_user_id', $userId)
                    ->orWhere('playlists.visibility', 'server');
            })->first($columns);
            if (!$row instanceof stdClass) {
                throw new PlaylistNotFound('Playlist not found.');
            }
        return $row;
    }

    /** 管理后台详情只校验对象存在和 user/system scope，不把该绕过扩散到普通用户读取入口。 */
    private function adminReadableRow(string $playlistId): stdClass
    {
        $this->assertPlaylistId($playlistId);
        $columns = [
            'playlists.id', 'playlists.owner_user_id', 'playlists.name', 'playlists.description',
            'playlists.kind', 'playlists.visibility', 'playlists.version', 'playlists.created_at', 'playlists.updated_at',
            'covers.content_sha256 as cover_content_sha256', 'owners.username as owner_username', 'owners.display_name as owner_display_name',
        ];
        if ($this->hasSystemColumns()) $columns = array_merge($columns, ['playlists.scope', 'playlists.source']);
        /** @var stdClass|null $row */
        $query = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->where('playlists.id', $playlistId);
        if ($this->hasSystemColumns()) $query->whereIn('playlists.scope', ['user', 'system']);
        /** @var stdClass|null $row */
        $row = $query->first($columns);
        if (!$row instanceof stdClass) throw new PlaylistNotFound('Playlist not found.');
        return $row;
    }

    /**
     * Resolves manual item rows and live smart results for already readable headers.
     *
     * Manual lists retain stored occurrence order. Smart definitions are loaded only for headers in
     * this page, revalidated through SmartPlaylistValidator, and evaluated separately using the list ID
     * as the daily random seed. A missing/corrupt child fails the read rather than falling back to stale
     * denormalized totals or exposing all songs. Every returned item is path-free and actor-authorized.
     *
     * @param list<stdClass> $rows Headers selected by owner/server visibility queries.
     * @return array<string, list<array{song: array<string, mixed>}>>
     */
    private function visibleItemsForRows(array $actor, array $rows): array
    {
        $manualIds = [];
        $smartIds = [];
        foreach ($rows as $row) {
            if ((string) ($row->kind ?? 'manual') === 'smart') {
                $smartIds[] = (string) $row->id;
            } else {
                $manualIds[] = (string) $row->id;
            }
        }
        $result = $this->visibleItems($actor, $manualIds);
        if ($smartIds === []) {
            return $result;
        }

        /** @var list<stdClass> $definitions */
        $definitions = Db::table('smart_playlist_definitions')->whereIn('playlist_id', $smartIds)
            ->get(['playlist_id', 'rule_json', 'sort_field', 'sort_direction', 'result_limit'])->all();
        $byPlaylist = [];
        foreach ($definitions as $definition) {
            $byPlaylist[(string) $definition->playlist_id] = $definition;
        }
        $validator = new SmartPlaylistValidator();
        foreach ($smartIds as $smartId) {
            $stored = $byPlaylist[$smartId] ?? null;
            if (!$stored instanceof stdClass) {
                throw new SmartPlaylistInvalid('Stored smart playlist definition is missing.');
            }
            try {
                $rule = json_decode((string) $stored->rule_json, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new SmartPlaylistInvalid('Stored smart playlist rule is invalid.', previous: $exception);
            }
            if (!is_array($rule)) {
                throw new SmartPlaylistInvalid('Stored smart playlist rule is invalid.');
            }
            $canonical = $validator->definition([
                'rule' => $rule,
                'sortField' => (string) $stored->sort_field,
                'sortDirection' => (string) $stored->sort_direction,
                'resultLimit' => (int) $stored->result_limit,
            ]);
            $songs = $this->smartEvaluator->evaluate($actor, $canonical, $smartId);
            $result[$smartId] = array_map(
                static fn (array $song): array => ['song' => $song],
                $songs,
            );
        }

        return $result;
    }

    /**
     * Resolves ordered items for several playlist headers in one media authorization batch.
     *
     * @param list<string> $playlistIds IDs selected by a readable header query.
     * @return array<string, list<array{song: array<string, mixed>}>>
     */
    private function visibleItems(array $actor, array $playlistIds): array
    {
        if ($playlistIds === []) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $rows = Db::table('playlist_items')->whereIn('playlist_id', $playlistIds)
            ->orderBy('playlist_id')->orderBy('position')->get(['playlist_id', 'song_id'])->all();
        $songIds = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->song_id, $rows)));
        $songs = $this->authorizedSongsByIds($actor, $songIds);
        $result = [];
        foreach ($rows as $row) {
            $songId = (string) $row->song_id;
            if (isset($songs[$songId])) {
                $result[(string) $row->playlist_id][] = ['song' => $songs[$songId]];
            }
        }

        return $result;
    }

    /**
     * 返回导入文档的完整有序状态，并只为当前仍获授权的 matched 条目附加歌曲投影。
     *
     * 该查询不会把路径或外部定位符重新暴露给客户端；条目表只保存脱敏描述、状态和稳定歌曲 ID。
     * song_id 被删除或当前账号失权时，条目仍保留，调用方可以看到缺失状态而不会误以为导入项被删除。
     * 旧测试或尚未完成迁移的实例没有该表时返回空数组，普通手工歌单的读取契约不受影响。
     *
     * @return list<array{position: int, status: string, sourceTitle?: string, sourceArtists?: list<string>, sourceAlbum?: string, song?: array<string, mixed>, candidateCount?: int, reasonCode?: string}>
     */
    private function importEntries(array $actor, string $playlistId): array
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('playlist_import_entries')) {
            return [];
        }
        /** @var list<stdClass> $rows */
        $schema = Db::connection()->getSchemaBuilder();
        $hasSourceFields = $schema->hasColumn('playlist_import_entries', 'source_title');
        $columns = ['position', 'status', 'song_id', 'candidate_count', 'reason_code'];
        if ($hasSourceFields) {
            $columns = [...$columns, 'source_title', 'source_artists_json', 'source_album'];
        }
        $rows = Db::table('playlist_import_entries')->where('playlist_id', $playlistId)
            ->orderBy('position')->get($columns)->all();
        $songIds = [];
        foreach ($rows as $row) {
            if (is_string($row->song_id ?? null) && $row->song_id !== '') {
                $songIds[] = (string) $row->song_id;
            }
        }
        $songs = $this->authorizedSongsByIds($actor, $songIds);
        $result = [];
        foreach ($rows as $row) {
            $songId = is_string($row->song_id ?? null) ? (string) $row->song_id : '';
            $status = (string) $row->status;
            // 媒体被删除或当前账号失权后，matched 引用仍保留，但在读取层降级为未匹配，
            // 让用户知道条目仍在导入记录中而不是被静默删除；不返回失权歌曲投影。
            if ($status === 'matched' && ($songId === '' || !isset($songs[$songId]))) {
                $status = 'unmatched';
            }
            $entry = [
                'position' => (int) $row->position,
                'status' => $status,
            ];
            if ($hasSourceFields && is_string($row->source_title ?? null) && $row->source_title !== '') {
                $entry['sourceTitle'] = (string) $row->source_title;
            }
            if ($hasSourceFields && is_string($row->source_artists_json ?? null)) {
                $artists = json_decode($row->source_artists_json, true);
                if (is_array($artists) && array_is_list($artists)) {
                    $entry['sourceArtists'] = array_values(array_filter($artists, 'is_string'));
                }
            }
            if ($hasSourceFields && is_string($row->source_album ?? null) && $row->source_album !== '') {
                $entry['sourceAlbum'] = (string) $row->source_album;
            }
            if ($row->candidate_count !== null) {
                $entry['candidateCount'] = (int) $row->candidate_count;
            }
            if ($row->reason_code !== null) {
                $entry['reasonCode'] = (string) $row->reason_code;
            }
            if ($songId !== '' && isset($songs[$songId])) {
                $entry['song'] = $songs[$songId];
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Resolves up to 1000 playlist IDs through MediaQueryService's live authorization scope.
     *
     * MediaQueryService deliberately limits each database batch to 500 IDs to keep catalog SQL
     * bounded. Ordinary playlists allow 1000 occurrences and may contain 1000 unique songs, so
     * this adapter chunks only server-validated IDs and merges the keyed projections. Missing or
     * revoked IDs remain absent; callers either reject the write or omit them from a read.
     *
     * @param array<string, mixed> $actor Authenticated principal whose current grants apply.
     * @param list<string> $songIds Canonical stable song IDs, already bounded by playlist storage.
     * @return array<string, array<string, mixed>> Authorized songs keyed by stable media ID.
     */
    private function authorizedSongsByIds(array $actor, array $songIds): array
    {
        $songs = [];
        foreach (array_chunk(array_values(array_unique($songIds)), 500) as $batch) {
            $songs += $this->media->songsByIds($actor, $batch);
        }

        return $songs;
    }

    /**
     * Proves every unique target song and calculates duration across duplicate occurrences.
     *
     * An empty list is valid. At most 1000 entries are accepted defensively even though public
     * validators enforce the same bound; malformed or unauthorized IDs share one not-found failure
     * so protocol adapters cannot enumerate catalog objects through playlist writes.
     *
     * @param list<string> $songIds Complete desired occurrence order.
     * @return array{array<string, array<string, mixed>>, int} Authorized map and total milliseconds.
     */
    private function prepareSongOrder(array $actor, array &$songIds): array
    {
        if (count($songIds) > 1000) {
            throw new PlaylistItemNotFound('One or more playlist songs are unavailable.');
        }
        foreach ($songIds as $songId) {
            if (!is_string($songId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
                throw new PlaylistItemNotFound('One or more playlist songs are unavailable.');
            }
        }
        $uniqueSongIds = array_values(array_unique($songIds));
        $songs = $this->authorizedSongsByIds($actor, $uniqueSongIds);
        if (count($songs) !== count($uniqueSongIds)) {
            throw new PlaylistItemNotFound('One or more playlist songs are unavailable.');
        }
        // 兼容查询按请求旧 ID 返回投影，但投影自身 ID 是保留歌曲。规范化每个出现位置而不去重，避免
        // Subsonic/Web 旧快照在逻辑合并后把来源 ID 再写回歌单；后续汇总和插入只使用目标 ID。
        $songIds = array_map(
            static fn (string $songId): string => (string) $songs[$songId]['id'],
            $songIds,
        );
        $canonicalSongs = [];
        foreach ($songs as $song) $canonicalSongs[(string) $song['id']] = $song;
        $songs = $canonicalSongs;
        $durationMs = array_sum(array_map(
            static fn (string $songId): int => (int) $songs[$songId]['durationMs'],
            $songIds,
        ));

        return [$songs, $durationMs];
    }

    /**
     * Inserts a validated complete order inside the caller-owned transaction.
     *
     * The authorized map is intentionally consumed as a proof even though only IDs are persisted;
     * every ID must still be present before any row is constructed. Callers delete old rows first
     * when replacing. Any database failure bubbles up so the surrounding transaction can roll back.
     *
     * @param list<string> $songIds Validated occurrence order.
     * @param array<string, array<string, mixed>> $songs Authorization proof keyed by song ID.
     */
    private function insertItems(
        string $playlistId,
        string $actorId,
        array $songIds,
        array $songs,
        string $now,
    ): void {
        if ($songIds === []) {
            return;
        }
        $items = [];
        foreach ($songIds as $position => $songId) {
            if (!isset($songs[$songId])) {
                throw new PlaylistItemNotFound('One or more playlist songs are unavailable.');
            }
            $items[] = [
                'playlist_id' => $playlistId,
                'position' => $position,
                'song_id' => $songId,
                'added_by_user_id' => $actorId,
                'added_at' => $now,
            ];
        }
        Db::table('playlist_items')->insert($items);
    }

    /**
     * 在创建导入歌单的同一事务中保存所有条目状态。
     *
     * 只有 matched 条目允许带有已授权 songId；其余状态绝不写入歌曲引用。来源标题、艺人和专辑只
     * 作为有界显示证据，不能包含路径、链接或平台 ID。位置必须从零连续递增，任何校验或数据库错误
     * 都向上抛出，由 createImported 的事务整体回滚，避免出现只有歌单头或只有部分状态的结果。
     *
     * @param list<array<string, mixed>> $entries Path-free imported occurrences.
     * @param array<string, array<string, mixed>> $songs Current authorization proof.
     */
    private function insertImportEntries(string $playlistId, array $entries, array $songs, string $now): void
    {
        if ($entries === []) {
            return;
        }
        if (count($entries) > 1000) {
            throw new PlaylistImportInvalid('too_many_entries');
        }
        $hasSourceFields = Db::connection()->getSchemaBuilder()
            ->hasColumn('playlist_import_entries', 'source_title');
        $rows = [];
        foreach ($entries as $expectedPosition => $entry) {
            $position = $entry['position'] ?? null;
            $status = $entry['status'] ?? null;
            if (!is_int($position) || $position !== $expectedPosition
                || !is_string($status)
                || !in_array($status, ['matched', 'unmatched', 'ambiguous', 'unsupported'], true)) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $songId = $entry['songId'] ?? null;
            if ($status === 'matched') {
                if (!is_string($songId) || !isset($songs[$songId])) {
                    throw new PlaylistImportInvalid('invalid_import_entries');
                }
            } elseif ($songId !== null) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $candidateCount = $entry['candidateCount'] ?? null;
            if ($candidateCount !== null && (!is_int($candidateCount) || $candidateCount < 0 || $candidateCount > 100)) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $reasonCode = $entry['reasonCode'] ?? null;
            if ($reasonCode !== null && (!is_string($reasonCode) || preg_match('/^[a-z0-9_]{1,64}$/', $reasonCode) !== 1)) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $sourceTitle = $this->importDisplayText($entry['sourceTitle'] ?? null, 500);
            $sourceAlbum = $this->importDisplayText($entry['sourceAlbum'] ?? null, 500);
            $sourceArtists = $entry['sourceArtists'] ?? [];
            if (!is_array($sourceArtists) || !array_is_list($sourceArtists) || count($sourceArtists) > 20) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $sourceArtists = array_map(fn (mixed $artist): string => $this->importDisplayText($artist, 300, false), $sourceArtists);
            $sourceArtistsJson = $sourceArtists === [] ? null : json_encode(
                $sourceArtists,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (is_string($sourceArtistsJson) && strlen($sourceArtistsJson) > 4000) {
                throw new PlaylistImportInvalid('invalid_import_entries');
            }
            $row = [
                'playlist_id' => $playlistId,
                'position' => $position,
                'status' => $status,
                'song_id' => $songId,
                'candidate_count' => $candidateCount,
                'reason_code' => $reasonCode,
                'created_at' => $now,
            ];
            if ($hasSourceFields) {
                $row += [
                    'source_title' => $sourceTitle,
                    'source_artists_json' => $sourceArtistsJson,
                    'source_album' => $sourceAlbum,
                ];
            }
            $rows[] = $row;
        }
        Db::table('playlist_import_entries')->insert($rows);
    }

    /** 校验导入显示文本；允许 NULL 的字段不会被空字符串伪造成可用元数据。 */
    private function importDisplayText(mixed $value, int $maximum, bool $nullable = true): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new PlaylistImportInvalid('invalid_import_entries');
        }
        return trim($value);
    }

    /** @param list<array{song: array<string, mixed>}> $items */
    private function summary(stdClass $row, string $userId, array $items): array
    {
        return [
            'id' => (string) $row->id,
            'kind' => (string) ($row->kind ?? 'manual'),
            'name' => (string) $row->name,
            'description' => $row->description === null ? null : (string) $row->description,
            'coverUrl' => $this->coverUrl($row),
            'visibility' => (string) $row->visibility,
            'scope' => (string) ($row->scope ?? 'user'),
            'source' => (string) ($row->source ?? 'manual'),
            'owner' => [
                'id' => (string) $row->owner_user_id,
                'username' => (string) $row->owner_username,
                'displayName' => (string) $row->owner_display_name,
            ],
            'canEdit' => (string) ($row->scope ?? 'user') === 'user' && (string) $row->owner_user_id === $userId,
            'songCount' => count($items),
            'durationMs' => array_sum(array_map(static fn (array $item): int => (int) $item['song']['durationMs'], $items)),
            'version' => (int) $row->version,
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
        ];
    }

    /**
     * 为已选中的封面生成同源缓存键 URL；缺失或损坏的摘要不会诱发图片请求。
     *
     * URL 只含歌单 ULID 和摘要前缀，不含所有者或存储位置。图片端点仍会逐次执行歌单可见性校验，
     * 因而该字段不是授权凭据；摘要前缀仅用于封面替换后使浏览器绕过旧缓存。
     */
    private function coverUrl(stdClass $row): ?string
    {
        $digest = $row->cover_content_sha256 ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            return null;
        }

        return '/api/v1/playlists/' . rawurlencode((string) $row->id) . '/cover?v=' . substr($digest, 0, 16);
    }

    /** 精简测试表或尚未迁移的只读实例安全回退历史 user/manual 契约。 */
    private function hasSystemColumns(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        return $schema->hasColumn('playlists', 'scope') && $schema->hasColumn('playlists', 'source');
    }

    /** Accepts only canonical opaque playlist ULIDs before any existence query. */
    private function assertPlaylistId(string $playlistId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $playlistId) !== 1) {
            throw new PlaylistNotFound('Playlist not found.');
        }
    }

    /**
     * Rejects any item-materializing operation on a smart header inside the final write reservation.
     *
     * Controllers and protocol adapters may preflight for clearer UI, but this transaction-time check
     * is authoritative. It prevents concurrent rule/type changes or legacy Subsonic/M3U commands from
     * creating ignored playlist_items beneath a dynamic definition. No existing rows are changed.
     */
    private function assertManualPlaylist(stdClass $row): void
    {
        if ((string) ($row->kind ?? 'manual') !== 'manual') {
            throw new PlaylistInvalid('Smart playlists do not accept materialized item commands.');
        }
    }
}
