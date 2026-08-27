<?php

declare(strict_types=1);

namespace app\application\Metadata;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;
use app\infrastructure\Audit\AuditLogger;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 构建重复媒体候选投影并记录“保留全部文件”的人工核对决定（ADMIN-META-011）。
 *
 * 服务只读取扫描 Worker 已持久化的证据，不打开媒体文件、不生成指纹、不移动或删除目录项。四类证据
 * 分开呈现：同 inode 证明同一文件系统对象；同 SHA-256 证明字节相同；同 Chromaprint 摘要提示音频
 * 内容相同；元数据相似只按规范标题和主艺术家生成低强度候选。后一类会排除组内已经存在的强证据，
 * 防止把确证重复降级成模糊匹配。
 *
 * Controller 先校验全局 `edit_metadata`，本服务再把查询限定到 actor 当前 manage 音乐库。响应只包含
 * 相对逻辑位置和不可逆安全身份短码，不返回 resolved_path、原始 inode、完整哈希、指纹或用户 ID。
 * 保留建议是可解释排序且永不授权删除；管理员决定只隐藏已核对组并记录保留项，不修改歌曲或文件。
 * 不同编码和不同发行版始终显示风险标记。回收站是媒体删除后的独立模块；本重复检测响应和命令仍明确
 * 不提供文件删除、恢复或永久清理能力，避免把相似性证据误当成破坏性操作授权。
 */
final readonly class DuplicateMediaService
{
    private const KINDS = ['inode', 'byte_hash', 'fingerprint', 'metadata'];

    public function __construct(private AuditLogger $audit = new AuditLogger()) {}

    /**
     * 返回一个证据类型的分组、覆盖率和库筛选项。
     *
     * 分页单位是“候选组”而不是歌曲。limit 上限 50，q 上限 100 字符；无 manage 库或不可管理的
     * libraryId 返回空结果而不泄露该库是否存在。SQLite 的分组只使用普通列和 HAVING，未来 MySQL 可
     * 保持等价 SQL；元数据分组中的规范标题由扫描写入，不依赖数据库排序规则。
     *
     * @return array<string,mixed>
     */
    public function groups(
        array $actor,
        string $kind,
        ?string $libraryId,
        ?string $query,
        int $limit,
        int $offset,
    ): array {
        if (!in_array($kind, self::KINDS, true)) throw new DuplicateMediaInvalid('重复证据类型无效。');
        $libraries = $this->managedLibraries($actor);
        $libraryIds = array_keys($libraries);
        $limit = max(1, min(50, $limit));
        $offset = max(0, min(1_000_000, $offset));
        $query = is_string($query) && trim($query) !== '' ? mb_substr(trim($query), 0, 100) : null;
        if ($libraryId !== null && ($libraryId === '' || !isset($libraries[$libraryId]))) {
            return $this->empty($kind, $limit, $offset, array_values($libraries));
        }
        if ($libraryIds === []) return $this->empty($kind, $limit, $offset, []);
        $scopeIds = $libraryId === null ? $libraryIds : [$libraryId];

        $keys = $this->unreviewedGroupKeys($kind, $scopeIds, $query);
        $total = count($keys);
        $pageKeys = array_slice($keys, $offset, $limit);
        $groups = [];
        foreach ($pageKeys as $key) {
            $members = $this->members($kind, $key, $scopeIds);
            if (count($members) < 2) continue;
            $groups[] = $this->mapGroup($kind, $key, $members, $scopeIds);
        }

        return [
            'kind' => $kind, 'groups' => $groups, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
            'libraries' => array_values($libraries), 'counts' => $this->kindCounts($scopeIds, $query),
            'coverage' => $this->coverage($scopeIds),
            'policy' => ['readOnly' => false, 'automaticDeletion' => false,
                'deletionAvailable' => false, 'reviewAction' => 'keep_all',
                'differentEditionsRequireReview' => true],
        ];
    }

    /**
     * 把仍然成立的候选组标记为已核对并保留全部文件。
     *
     * 请求必须携带当前页面看到的完整成员集合和保留项。服务端从实时证据重新构造同一 groupId，校验
     * 管理范围及成员完全一致后才 upsert。此命令不修改歌曲、个人引用或文件；证据或成员发生变化时
     * 返回冲突，管理员必须刷新重新判断。相同决定幂等更新时间并保留审计。
     *
     * @param list<string> $songIds
     */
    public function keepAll(array $actor, string $kind, string $groupId, array $songIds, string $keepSongId,
        string $requestId): array
    {
        if (!in_array($kind, self::KINDS, true) || preg_match('/^[a-f0-9]{24}$/', $groupId) !== 1
            || count($songIds) < 2 || count($songIds) > 100 || !in_array($keepSongId, $songIds, true)) {
            throw new DuplicateMediaInvalid('重复处理参数无效。');
        }
        foreach ($songIds as $songId) {
            if (!is_string($songId) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $songId) !== 1) {
                throw new DuplicateMediaInvalid('歌曲标识无效。');
            }
        }
        $libraries = array_keys($this->managedLibraries($actor));
        $matchedMembers = null;
        foreach ($this->groupKeys($kind, $libraries, null) as $key) {
            if (!hash_equals($groupId, $this->groupId($kind, $key, $libraries))) continue;
            $matchedMembers = $this->members($kind, $key, $libraries);
            break;
        }
        if ($matchedMembers === null) throw new DuplicateMediaInvalid('重复组已经变化。');
        $actualIds = array_map(static fn (array $member): string => (string) $member['songId'], $matchedMembers);
        $requestedIds = array_values(array_unique($songIds));
        sort($actualIds);
        sort($requestedIds);
        if ($actualIds !== $requestedIds || !in_array($keepSongId, $actualIds, true)) {
            throw new DuplicateMediaInvalid('重复组成员已经变化。');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($actor, $kind, $groupId, $keepSongId, $actualIds, $requestId, $now): void {
            $existing = Db::table('duplicate_media_decisions')->where('group_id', $groupId)->exists();
            $values = ['evidence_kind' => $kind, 'action' => 'keep_all', 'keep_song_id' => $keepSongId,
                'member_count' => count($actualIds), 'decided_by' => (string) $actor['id'], 'updated_at' => $now];
            if ($existing) Db::table('duplicate_media_decisions')->where('group_id', $groupId)->update($values);
            else Db::table('duplicate_media_decisions')->insert(['group_id' => $groupId, 'created_at' => $now] + $values);
            $this->audit->record((string) $actor['id'], 'duplicate_media.keep_all', 'duplicate_media_group',
                $groupId, 'success', $requestId, ['kind' => $kind, 'keepSongId' => $keepSongId,
                    'memberCount' => count($actualIds)]);
        });
        return ['groupId' => $groupId, 'action' => 'keep_all', 'keepSongId' => $keepSongId, 'reviewedAt' => $now];
    }

    /**
     * 将同库、同专辑且具有强证据的一组歌曲逻辑合并到保留歌曲。
     *
     * 请求携带页面看到的完整成员集合和固定确认文本。提交在 `BEGIN IMMEDIATE` 中重新计算 groupId、成员、
     * 音乐库、专辑和 inode/SHA-256 证据；任何变化都整体冲突。当前个人状态会收敛到目标，歌单与队列只
     * 替换 ID 并保留位置和重复出现；历史播放会话、歌词、标签快照、刮削记录、库存和文件完全不改写。
     * 来源歌曲通过带证据条件的重定向从目录隐藏，旧 ID 仍可解析到目标；文件变化会让隐藏自动失效。
     *
     * @param list<string> $songIds 当前候选组的完整歌曲 ID。
     * @return array{operation:array<string,mixed>,hiddenSongCount:int}
     */
    public function merge(
        array $actor,
        string $kind,
        string $groupId,
        string $libraryId,
        array $songIds,
        string $targetSongId,
        string $confirmation,
        string $requestId,
    ): array {
        if (!in_array($kind, ['inode', 'byte_hash'], true)
            || preg_match('/^[a-f0-9]{24}$/', $groupId) !== 1
            || $confirmation !== 'MERGE DUPLICATES') {
            throw new DuplicateMediaInvalid('重复合并参数无效。');
        }
        $managed = $this->managedLibraries($actor);
        if (!isset($managed[$libraryId])) throw new DuplicateMediaConflict('重复组已经变化，请刷新后重试。');
        [$key, $members, $actualIds] = $this->verifiedStrongGroup(
            $kind, $groupId, $libraryId, $songIds, $targetSongId,
        );
        $albumIds = array_values(array_unique(array_map(
            static fn (array $member): string => (string) $member['album']['id'],
            $members,
        )));
        if (count($albumIds) !== 1) {
            throw new DuplicateMediaConflict('不同专辑或发行版不能直接合并。');
        }
        $sourceIds = array_values(array_filter($actualIds, static fn (string $id): bool => $id !== $targetSongId));
        $operationId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $snapshot = $this->captureSongMergeSnapshot($actualIds, $targetSongId, $kind, $key);
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($snapshotJson) > 16_777_216) throw new DuplicateMediaConflict('重复组合并影响过大。');

        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            // 写锁内再次复验，避免预检查后扫描、权限或另一个管理员改变成员和证据。
            $managedNow = $this->managedLibraries($actor);
            if (!isset($managedNow[$libraryId])) throw new DuplicateMediaConflict('音乐库管理权限已经变化。');
            [$lockedKey, $lockedMembers, $lockedIds] = $this->verifiedStrongGroup(
                $kind, $groupId, $libraryId, $actualIds, $targetSongId,
            );
            if ($lockedKey !== $key || $lockedIds !== $actualIds
                || count(array_unique(array_map(
                    static fn (array $member): string => (string) $member['album']['id'],
                    $lockedMembers,
                ))) !== 1) {
                throw new DuplicateMediaConflict('重复组已经变化，请刷新后重试。');
            }
            $this->assertSongsAreNotActivelyRedirected($actualIds);
            $lockedSnapshot = $this->captureSongMergeSnapshot($actualIds, $targetSongId, $kind, $key);
            if (!hash_equals(hash('sha256', $snapshotJson), hash('sha256', json_encode(
                $lockedSnapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )))) {
                throw new DuplicateMediaConflict('个人状态已经变化，请刷新后重试。');
            }

            $this->mergeCurrentSongState($sourceIds, $targetSongId, $now);
            // 操作行必须先存在，重定向的外键才能成立。后置摘要在重定向写入后计算，因此先放入固定
            // 占位摘要并在同一事务内回填；请求进程异常或回填失败会整体回滚，不会暴露半完成操作。
            Db::table('song_duplicate_merge_operations')->insert([
                'id' => $operationId, 'group_id' => $groupId, 'evidence_kind' => $kind,
                'library_id' => $libraryId, 'target_song_id' => $targetSongId,
                'member_set_sha256' => hash('sha256', implode("\0", $actualIds)),
                'snapshot_json' => $snapshotJson, 'postcondition_sha256' => str_repeat('0', 64),
                'status' => 'applied', 'version' => 1, 'actor_user_id' => (string) ($actor['id'] ?? ''),
                'created_at' => $now, 'rolled_back_at' => null, 'updated_at' => $now,
            ]);
            foreach ($sourceIds as $sourceId) {
                Db::table('song_duplicate_redirects')->insert([
                    'source_song_id' => $sourceId, 'target_song_id' => $targetSongId,
                    'operation_id' => $operationId, 'evidence_kind' => $kind,
                    'evidence_key_a' => (string) $key['a'],
                    'evidence_key_b' => $key['b'] === null ? null : (string) $key['b'],
                    'status' => 'active', 'created_at' => $now, 'reverted_at' => null,
                ]);
            }
            $postcondition = $this->songMergeStateHash($actualIds, $operationId);
            Db::table('song_duplicate_merge_operations')->where('id', $operationId)
                ->update(['postcondition_sha256' => $postcondition]);
            $this->audit->record((string) ($actor['id'] ?? ''), 'duplicate_media.merge',
                'song_duplicate_merge', $operationId, 'success', $requestId, [
                    'kind' => $kind, 'targetSongId' => $targetSongId, 'memberCount' => count($actualIds),
                ]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }

        return ['operation' => [
            'id' => $operationId, 'status' => 'applied', 'version' => 1,
            'targetSongId' => $targetSongId, 'memberCount' => count($actualIds),
            'createdAt' => $now, 'canRollback' => true,
        ], 'hiddenSongCount' => count($sourceIds)];
    }

    /**
     * 在执行后状态未发生任何变化时撤销一次歌曲逻辑合并。
     *
     * 回滚重新验证音乐库 manage 权限、操作版本和后置摘要；合并后若用户改过收藏、评分、书签、歌单或
     * 队列，必须冲突而不能覆盖。成功恢复快照并把重定向标记 reverted，仍不打开或修改媒体文件。
     */
    public function rollback(
        array $actor,
        string $operationId,
        int $expectedVersion,
        string $confirmation,
        string $requestId,
    ): array {
        if (!Ulid::isValid($operationId) || $expectedVersion < 1 || $confirmation !== 'ROLLBACK DUPLICATES') {
            throw new DuplicateMediaInvalid('重复合并回滚参数无效。');
        }
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $operation */
            $operation = Db::table('song_duplicate_merge_operations')->where('id', $operationId)->first();
            if (!$operation instanceof stdClass || !isset($this->managedLibraries($actor)[(string) $operation->library_id])) {
                throw new DuplicateMediaConflict('重复合并操作不存在或无权访问。');
            }
            if ((string) $operation->status !== 'applied' || (int) $operation->version !== $expectedVersion) {
                throw new DuplicateMediaConflict('重复合并操作已经变化。');
            }
            $snapshot = json_decode((string) $operation->snapshot_json, true, 32, JSON_THROW_ON_ERROR);
            $songIds = is_array($snapshot['songIds'] ?? null) ? array_values($snapshot['songIds']) : [];
            if ($songIds === [] || !hash_equals(
                (string) $operation->postcondition_sha256,
                $this->songMergeStateHash($songIds, $operationId),
            )) {
                throw new DuplicateMediaConflict('合并后的个人状态已经变化，不能自动撤销。');
            }
            $this->restoreSongMergeSnapshot($snapshot);
            $now = gmdate('Y-m-d\TH:i:s\Z');
            Db::table('song_duplicate_redirects')->where('operation_id', $operationId)->where('status', 'active')
                ->update(['status' => 'reverted', 'reverted_at' => $now]);
            $changed = Db::table('song_duplicate_merge_operations')->where('id', $operationId)
                ->where('status', 'applied')->where('version', $expectedVersion)->update([
                    'status' => 'rolled_back', 'version' => $expectedVersion + 1,
                    'rolled_back_at' => $now, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new DuplicateMediaConflict('重复合并操作已经变化。');
            $this->audit->record((string) ($actor['id'] ?? ''), 'duplicate_media.merge.rollback',
                'song_duplicate_merge', $operationId, 'success', $requestId, ['memberCount' => count($songIds)]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }

        return ['id' => $operationId, 'status' => 'rolled_back', 'version' => $expectedVersion + 1,
            'targetSongId' => (string) $operation->target_song_id, 'memberCount' => count($songIds),
            'createdAt' => (string) $operation->created_at, 'canRollback' => false, 'rolledBackAt' => $now];
    }

    /** @return array{0:array{a:string,b:?string,count:int},1:list<array<string,mixed>>,2:list<string>} */
    private function verifiedStrongGroup(
        string $kind,
        string $groupId,
        string $libraryId,
        array $songIds,
        string $targetSongId,
    ): array {
        if (count($songIds) < 2 || count($songIds) > 100 || !in_array($targetSongId, $songIds, true)) {
            throw new DuplicateMediaInvalid('重复合并成员无效。');
        }
        $requested = [];
        foreach ($songIds as $songId) {
            if (!is_string($songId) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $songId) !== 1) {
                throw new DuplicateMediaInvalid('歌曲标识无效。');
            }
            $requested[$songId] = true;
        }
        if (count($requested) !== count($songIds)) throw new DuplicateMediaInvalid('重复合并成员无效。');
        $matchedKey = null;
        foreach ($this->groupKeys($kind, [$libraryId], null) as $key) {
            if (hash_equals($groupId, $this->groupId($kind, $key, [$libraryId]))) {
                $matchedKey = $key;
                break;
            }
        }
        if (!is_array($matchedKey)) throw new DuplicateMediaConflict('重复组已经变化，请刷新后重试。');
        $members = $this->members($kind, $matchedKey, [$libraryId]);
        $actual = array_map(static fn (array $member): string => (string) $member['songId'], $members);
        sort($actual);
        $requestedIds = array_keys($requested);
        sort($requestedIds);
        if ($actual !== $requestedIds || !in_array($targetSongId, $actual, true)) {
            throw new DuplicateMediaConflict('重复组成员已经变化，请刷新后重试。');
        }

        return [$matchedKey, $members, $actual];
    }

    /** 捕获合并会改变的当前个人状态；历史事实和媒体派生资源故意不在快照内。 */
    private function captureSongMergeSnapshot(array $songIds, string $targetSongId, string $kind, array $key): array
    {
        $playlistIds = $this->tableExists('playlist_items')
            ? $this->ids(Db::table('playlist_items')->whereIn('song_id', $songIds), 'playlist_id') : [];
        $queueIds = $this->tableExists('play_queue_items')
            ? $this->ids(Db::table('play_queue_items')->whereIn('song_id', $songIds), 'queue_id') : [];

        return [
            'schemaVersion' => 1, 'songIds' => $songIds, 'targetSongId' => $targetSongId,
            'evidenceKind' => $kind, 'evidenceKey' => $key,
            'preferences' => $this->songRows('user_song_preferences', $songIds),
            'playStats' => $this->songRows('user_song_play_stats', $songIds),
            'bookmarks' => $this->songRows('user_song_bookmarks', $songIds),
            'playlistItems' => $this->songRows('playlist_items', $songIds),
            'queueItems' => $this->songRows('play_queue_items', $songIds),
            'playlists' => $playlistIds === [] || !$this->tableExists('playlists') ? []
                : $this->rows(Db::table('playlists')->whereIn('id', $playlistIds)),
            'queues' => $queueIds === [] || !$this->tableExists('play_queues') ? []
                : $this->rows(Db::table('play_queues')->whereIn('id', $queueIds)),
        ];
    }

    /**
     * 将可合并的当前个人状态收敛到目标歌曲。
     *
     * 收藏取并集并保留最早收藏时间；评分选择 rated_at 最新的一行；播放次数求和且最近活动行提供进度；
     * 书签选择 updated_at 最新的一行。所有来源行先在同一事务内删除再写目标，任何约束失败都会整体回滚。
     */
    private function mergeCurrentSongState(array $sourceIds, string $targetSongId, string $now): void
    {
        $songIds = [...$sourceIds, $targetSongId];
        if ($this->tableExists('user_song_preferences')) {
            $rows = Db::table('user_song_preferences')->whereIn('song_id', $songIds)->get()->all();
            $grouped = [];
            foreach ($rows as $row) $grouped[(string) $row->user_id][] = $row;
            Db::table('user_song_preferences')->whereIn('song_id', $songIds)->delete();
            foreach ($grouped as $userId => $items) {
                $favoriteRows = array_values(array_filter($items, static fn (stdClass $row): bool => (int) $row->is_favorite === 1));
                usort($items, static fn (stdClass $a, stdClass $b): int => strcmp(
                    (string) ($b->rated_at ?? ''),
                    (string) ($a->rated_at ?? ''),
                ));
                $ratingRow = null;
                foreach ($items as $item) if (($item->rating ?? null) !== null) { $ratingRow = $item; break; }
                $favorite = $favoriteRows !== [];
                if (!$favorite && !$ratingRow instanceof stdClass) continue;
                $values = ['user_id' => $userId, 'song_id' => $targetSongId,
                    'is_favorite' => $favorite ? 1 : 0];
                if ($this->columnExists('user_song_preferences', 'favorited_at')) {
                    $values['favorited_at'] = $favorite ? min(array_map(
                        static fn (stdClass $row): string => (string) $row->favorited_at,
                        $favoriteRows,
                    )) : null;
                }
                if ($this->columnExists('user_song_preferences', 'updated_at')) $values['updated_at'] = $now;
                if ($this->columnExists('user_song_preferences', 'rating')) {
                    $values['rating'] = $ratingRow?->rating;
                    $values['rated_at'] = $ratingRow?->rated_at;
                }
                Db::table('user_song_preferences')->insert($values);
            }
        }
        if ($this->tableExists('user_song_play_stats')) {
            $rows = Db::table('user_song_play_stats')->whereIn('song_id', $songIds)->get()->all();
            $grouped = [];
            foreach ($rows as $row) $grouped[(string) $row->user_id][] = $row;
            Db::table('user_song_play_stats')->whereIn('song_id', $songIds)->delete();
            foreach ($grouped as $userId => $items) {
                usort($items, static fn (stdClass $a, stdClass $b): int => strcmp(
                    (string) ($b->last_activity_at ?? $b->updated_at ?? ''),
                    (string) ($a->last_activity_at ?? $a->updated_at ?? ''),
                ));
                $latest = $items[0];
                $values = ['user_id' => $userId, 'song_id' => $targetSongId,
                    'play_count' => array_sum(array_map(static fn (stdClass $row): int => (int) $row->play_count, $items))];
                foreach (['last_played_at', 'last_activity_at', 'last_position_ms'] as $column) {
                    if ($this->columnExists('user_song_play_stats', $column)) $values[$column] = $latest->{$column};
                }
                if ($this->columnExists('user_song_play_stats', 'updated_at')) $values['updated_at'] = $now;
                Db::table('user_song_play_stats')->insert($values);
            }
        }
        if ($this->tableExists('user_song_bookmarks')) {
            $rows = Db::table('user_song_bookmarks')->whereIn('song_id', $songIds)->get()->all();
            $grouped = [];
            foreach ($rows as $row) $grouped[(string) $row->user_id][] = $row;
            Db::table('user_song_bookmarks')->whereIn('song_id', $songIds)->delete();
            foreach ($grouped as $items) {
                usort($items, static fn (stdClass $a, stdClass $b): int => strcmp(
                    (string) ($b->updated_at ?? ''),
                    (string) ($a->updated_at ?? ''),
                ));
                $values = (array) $items[0];
                $values['song_id'] = $targetSongId;
                Db::table('user_song_bookmarks')->insert($values);
            }
        }
        $this->replacePositionalSongIds('playlist_items', 'playlist_id', 'playlists', $sourceIds, $targetSongId, $now);
        $this->replacePositionalSongIds('play_queue_items', 'queue_id', 'play_queues', $sourceIds, $targetSongId, $now);
    }

    /** 替换位置型引用并递增父对象版本；重复目标仍保留每一个原始位置。 */
    private function replacePositionalSongIds(
        string $itemTable,
        string $parentColumn,
        string $parentTable,
        array $sourceIds,
        string $targetSongId,
        string $now,
    ): void {
        if ($sourceIds === [] || !$this->tableExists($itemTable)) return;
        $parents = $this->ids(Db::table($itemTable)->whereIn('song_id', $sourceIds), $parentColumn);
        Db::table($itemTable)->whereIn('song_id', $sourceIds)->update(['song_id' => $targetSongId]);
        if ($parents !== [] && $this->tableExists($parentTable) && $this->columnExists($parentTable, 'version')) {
            $values = ['version' => Db::raw('version + 1')];
            if ($this->columnExists($parentTable, 'updated_at')) $values['updated_at'] = $now;
            Db::table($parentTable)->whereIn('id', $parents)->update($values);
        }
    }

    /** 对合并影响范围构造稳定后置摘要，后续任一当前状态变化都会阻止自动回滚。 */
    private function songMergeStateHash(array $songIds, string $operationId): string
    {
        $redirects = $this->tableExists('song_duplicate_redirects')
            ? $this->rows(Db::table('song_duplicate_redirects')->where('operation_id', $operationId)) : [];
        $playlistIds = $this->tableExists('playlist_items')
            ? $this->ids(Db::table('playlist_items')->whereIn('song_id', $songIds), 'playlist_id') : [];
        $queueIds = $this->tableExists('play_queue_items')
            ? $this->ids(Db::table('play_queue_items')->whereIn('song_id', $songIds), 'queue_id') : [];
        $state = [
            'preferences' => $this->songRows('user_song_preferences', $songIds),
            'playStats' => $this->songRows('user_song_play_stats', $songIds),
            'bookmarks' => $this->songRows('user_song_bookmarks', $songIds),
            'playlistItems' => $this->songRows('playlist_items', $songIds),
            'queueItems' => $this->songRows('play_queue_items', $songIds),
            'playlists' => $playlistIds === [] || !$this->tableExists('playlists') ? []
                : $this->rows(Db::table('playlists')->whereIn('id', $playlistIds)),
            'queues' => $queueIds === [] || !$this->tableExists('play_queues') ? []
                : $this->rows(Db::table('play_queues')->whereIn('id', $queueIds)),
            'redirects' => $redirects,
        ];
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** 删除合并后的当前状态并原样恢复快照；调用前必须已经通过后置摘要检查。 */
    private function restoreSongMergeSnapshot(array $snapshot): void
    {
        $songIds = array_values($snapshot['songIds']);
        foreach ([
            'user_song_preferences' => 'preferences',
            'user_song_play_stats' => 'playStats',
            'user_song_bookmarks' => 'bookmarks',
            'playlist_items' => 'playlistItems',
            'play_queue_items' => 'queueItems',
        ] as $table => $key) {
            if (!$this->tableExists($table)) continue;
            Db::table($table)->whereIn('song_id', $songIds)->delete();
            $rows = is_array($snapshot[$key] ?? null) ? $snapshot[$key] : [];
            if ($rows !== []) Db::table($table)->insert($rows);
        }
        foreach (['playlists' => 'playlists', 'play_queues' => 'queues'] as $table => $key) {
            if (!$this->tableExists($table)) continue;
            foreach (is_array($snapshot[$key] ?? null) ? $snapshot[$key] : [] as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') {
                    unset($row['id']);
                    Db::table($table)->where('id', $id)->update($row);
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function songRows(string $table, array $songIds): array
    {
        return !$this->tableExists($table) ? [] : $this->rows(Db::table($table)->whereIn('song_id', $songIds));
    }

    /** @return list<array<string,mixed>> */
    private function rows(Builder $query): array
    {
        $rows = array_map(static function (stdClass $row): array {
            $values = (array) $row;
            ksort($values);
            return $values;
        }, $query->get()->all());
        usort($rows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        return $rows;
    }

    /**
     * 拒绝重叠和链式逻辑合并。
     *
     * 即使某条活动关系的文件证据暂时失效，也不能复用其中任一歌曲；后续重扫可能让旧关系重新生效，
     * 届时链条会让授权、隐藏和回滚语义不再唯一。管理员必须先撤销旧操作，再以新候选重新合并。
     */
    private function assertSongsAreNotActivelyRedirected(array $songIds): void
    {
        if (!$this->tableExists('song_duplicate_redirects')) return;
        $involved = Db::table('song_duplicate_redirects')->where('status', 'active')
            ->where(function (Builder $redirects) use ($songIds): void {
                $redirects->whereIn('source_song_id', $songIds)->orWhereIn('target_song_id', $songIds);
            })->exists();
        if ($involved) throw new DuplicateMediaConflict('歌曲已经参与其他活动合并，请先撤销旧操作。');
    }

    /** @return list<string> */
    private function ids(Builder $query, string $column): array
    {
        $ids = array_map('strval', $query->distinct()->pluck($column)->all());
        sort($ids);
        return $ids;
    }

    private function tableExists(string $table): bool
    {
        return Db::connection()->getSchemaBuilder()->hasTable($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return Db::connection()->getSchemaBuilder()->hasColumn($table, $column);
    }

    /** @return list<array<string,mixed>> */
    private function groupKeys(string $kind, array $libraryIds, ?string $query): array
    {
        $base = $this->base($libraryIds, $query);
        if ($kind === 'inode') {
            $rows = $base->select(['files.device_id as key_a', 'files.inode as key_b'])
                ->selectRaw('COUNT(DISTINCT songs.id) AS member_count')->groupBy('files.device_id', 'files.inode')
                ->havingRaw('COUNT(DISTINCT songs.id) > 1')->orderByDesc('member_count')->get()->all();
        } elseif ($kind === 'byte_hash') {
            $rows = $base->where('files.byte_hash_status', 'ready')->whereNotNull('files.byte_sha256')
                ->select(['files.byte_sha256 as key_a'])->selectRaw('COUNT(DISTINCT songs.id) AS member_count')
                ->groupBy('files.byte_sha256')->havingRaw('COUNT(DISTINCT songs.id) > 1')
                ->orderByDesc('member_count')->get()->all();
        } elseif ($kind === 'fingerprint') {
            $rows = $base->where('files.acoustic_fingerprint_status', 'ready')
                ->whereNotNull('files.acoustic_fingerprint_sha256')
                ->select(['files.acoustic_fingerprint_sha256 as key_a'])
                ->selectRaw('COUNT(DISTINCT songs.id) AS member_count')
                ->groupBy('files.acoustic_fingerprint_sha256')->havingRaw('COUNT(DISTINCT songs.id) > 1')
                ->orderByDesc('member_count')->get()->all();
        } else {
            $rows = $base->join('media_song_artists as primary_credit', function ($join): void {
                $join->on('primary_credit.song_id', '=', 'songs.id')->where('primary_credit.role', '=', 'primary')
                    ->where('primary_credit.position', '=', 0);
            })->where('songs.normalized_title', '!=', '')
                ->select(['songs.normalized_title as key_a', 'primary_credit.artist_id as key_b'])
                ->selectRaw('COUNT(DISTINCT songs.id) AS member_count')
                ->groupBy('songs.normalized_title', 'primary_credit.artist_id')
                ->havingRaw('COUNT(DISTINCT songs.id) > 1')
                // “仅元数据相似”必须排除任何强证据重复。SQLite 用带分隔符的整数文本组合统计
                // device/inode 对；未来 MySQL 仓储改为 COUNT(DISTINCT device_id, inode)，其余 CASE
                // 聚合保持等价。该方言差异集中在本查询边界，不泄漏到 Controller 或前端。
                ->havingRaw("COUNT(*) = COUNT(DISTINCT CAST(files.device_id AS TEXT) || ':' || CAST(files.inode AS TEXT))")
                ->havingRaw("COUNT(CASE WHEN files.byte_hash_status = 'ready' THEN 1 END) = COUNT(DISTINCT CASE WHEN files.byte_hash_status = 'ready' THEN files.byte_sha256 END)")
                ->havingRaw("COUNT(CASE WHEN files.acoustic_fingerprint_status = 'ready' THEN 1 END) = COUNT(DISTINCT CASE WHEN files.acoustic_fingerprint_status = 'ready' THEN files.acoustic_fingerprint_sha256 END)")
                ->orderByDesc('member_count')->get()->all();
        }
        return array_map(static fn (stdClass $row): array => [
            'a' => (string) $row->key_a, 'b' => isset($row->key_b) ? (string) $row->key_b : null,
            'count' => (int) $row->member_count,
        ], $rows);
    }

    /** 从当前库范围排除已经核对的稳定组；列表总数和四类计数必须共享同一规则。 */
    private function unreviewedGroupKeys(string $kind, array $libraryIds, ?string $query): array
    {
        $keys = $this->groupKeys($kind, $libraryIds, $query);
        if (!Db::connection()->getSchemaBuilder()->hasTable('duplicate_media_decisions')) return $keys;
        $reviewed = Db::table('duplicate_media_decisions')->where('evidence_kind', $kind)
            ->pluck('group_id')->map('strval')->all();
        $reviewed = array_fill_keys($reviewed, true);
        return array_values(array_filter($keys,
            fn (array $key): bool => !isset($reviewed[$this->groupId($kind, $key, $libraryIds)])));
    }

    /**
     * 读取一个分组的歌曲比较字段和个人引用聚合。
     *
     * 个人引用只返回计数：收藏账号数、累计播放、可见历史会话、播放列表项、书签和歌曲直连分享。
     * 这些计数用于保留建议，不改变任何个人关系；完整哈希和 inode 留在数据库内。
     *
     * @return list<array<string,mixed>>
     */
    private function members(string $kind, array $key, array $libraryIds): array
    {
        $query = $this->base($libraryIds, null);
        $this->applyKey($query, $kind, $key);
        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('songs.title')->orderBy('songs.id')->get([
            'songs.id', 'songs.title', 'songs.track_number', 'songs.disc_number', 'songs.release_year',
            'songs.duration_ms', 'songs.codec_name', 'songs.container_name', 'songs.bitrate', 'songs.bit_depth',
            'songs.sample_rate', 'songs.channels', 'songs.isrc', 'songs.musicbrainz_track_id',
            'albums.id as album_id', 'albums.title as album_title', 'albums.release_year as album_release_year',
            'libraries.id as library_id', 'libraries.name as library_name', 'files.relative_path',
            'files.extension', 'files.device_id', 'files.inode', 'files.file_size', 'files.modified_at',
            'files.byte_hash_status', 'files.byte_sha256', 'files.acoustic_fingerprint_status',
            'files.acoustic_fingerprint_sha256', 'files.acoustic_duration_seconds',
        ])->all();
        $songIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $artists = $this->artistNames($songIds);
        $references = $this->personalReferences($songIds);

        return array_map(function (stdClass $row) use ($artists, $references): array {
            $id = (string) $row->id;
            $refs = $references[$id] ?? ['favorites' => 0, 'plays' => 0, 'history' => 0,
                'playlists' => 0, 'bookmarks' => 0];
            return [
                'songId' => $id, 'title' => (string) $row->title,
                'artists' => $artists[$id] ?? [],
                'album' => ['id' => (string) $row->album_id, 'title' => (string) $row->album_title,
                    'releaseYear' => $row->album_release_year === null ? null : (int) $row->album_release_year],
                'library' => ['id' => (string) $row->library_id, 'name' => (string) $row->library_name],
                'file' => [
                    'relativePath' => (string) $row->relative_path, 'extension' => (string) $row->extension,
                    'identity' => substr(hash('sha256', implode(':', [(int) $row->device_id,
                        (int) $row->inode, (int) $row->file_size, (int) $row->modified_at])), 0, 16),
                    'byteSize' => (int) $row->file_size, 'modifiedAt' => (int) $row->modified_at,
                ],
                'audio' => ['durationMs' => (int) $row->duration_ms,
                    'codec' => $row->codec_name === null ? null : (string) $row->codec_name,
                    'container' => $row->container_name === null ? null : (string) $row->container_name,
                    'bitrate' => $row->bitrate === null ? null : (int) $row->bitrate,
                    'bitDepth' => $row->bit_depth === null ? null : (int) $row->bit_depth,
                    'sampleRate' => $row->sample_rate === null ? null : (int) $row->sample_rate,
                    'channels' => $row->channels === null ? null : (int) $row->channels],
                'edition' => ['trackNumber' => $row->track_number === null ? null : (int) $row->track_number,
                    'discNumber' => $row->disc_number === null ? null : (int) $row->disc_number,
                    'releaseYear' => $row->release_year === null ? null : (int) $row->release_year,
                    'isrc' => $row->isrc === null ? null : (string) $row->isrc,
                    'musicbrainzTrackId' => $row->musicbrainz_track_id === null ? null : (string) $row->musicbrainz_track_id],
                'evidence' => ['byteHash' => (string) $row->byte_hash_status,
                    'fingerprint' => (string) $row->acoustic_fingerprint_status,
                    'acousticDurationSeconds' => $row->acoustic_duration_seconds === null
                        ? null : (int) $row->acoustic_duration_seconds],
                'personalReferences' => $refs,
            ];
        }, $rows);
    }

    /** @param list<array<string,mixed>> $members */
    private function mapGroup(string $kind, array $key, array $members, array $libraryIds): array
    {
        $scored = [];
        foreach ($members as $member) $scored[(string) $member['songId']] = $this->retentionScore($member);
        arsort($scored, SORT_NUMERIC);
        $recommendedId = (string) array_key_first($scored);
        $formats = array_unique(array_map(static fn (array $item): string => implode(':', [
            $item['audio']['codec'] ?? '', $item['audio']['container'] ?? '', $item['audio']['bitDepth'] ?? '',
            $item['audio']['sampleRate'] ?? '',
        ]), $members));
        $editions = array_unique(array_map(static fn (array $item): string => implode(':', [
            $item['album']['id'], $item['edition']['releaseYear'] ?? '', $item['edition']['isrc'] ?? '',
            $item['edition']['musicbrainzTrackId'] ?? '',
        ]), $members));
        $recommended = null;
        foreach ($members as $member) if ($member['songId'] === $recommendedId) $recommended = $member;
        $memberLibraryIds = array_values(array_unique(array_map(
            static fn (array $member): string => (string) $member['library']['id'],
            $members,
        )));
        return [
            'id' => $this->groupId($kind, $key, $libraryIds),
            // 列表 groupId 绑定当前筛选范围，供 keep-all 精确核对；合并只能作用于单库，因此另给一个
            // 绑定实际成员库的命令 ID。跨库或弱证据组返回 null，客户端不能自行重算证据摘要。
            'mergeGroupId' => in_array($kind, ['inode', 'byte_hash'], true) && count($memberLibraryIds) === 1
                ? $this->groupId($kind, $key, $memberLibraryIds) : null,
            'kind' => $kind, 'memberCount' => count($members), 'members' => $members,
            'risk' => ['differentEncoding' => count($formats) > 1, 'differentEdition' => count($editions) > 1,
                'automaticDeletionAllowed' => false],
            'recommendation' => ['songId' => $recommendedId,
                'reasons' => $recommended === null ? [] : $this->retentionReasons($recommended, $members),
                'advisoryOnly' => true],
        ];
    }

    /** @return array<string,list<string>> */
    private function artistNames(array $songIds): array
    {
        if ($songIds === []) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('media_song_artists as credits')->join('media_artists as artists', 'artists.id', '=', 'credits.artist_id')
            ->whereIn('credits.song_id', $songIds)->orderBy('credits.song_id')->orderBy('credits.position')
            ->get(['credits.song_id', 'artists.name'])->all();
        $result = [];
        foreach ($rows as $row) $result[(string) $row->song_id][] = (string) $row->name;
        return $result;
    }

    /** @return array<string,array<string,int>> */
    private function personalReferences(array $songIds): array
    {
        $result = [];
        foreach ($songIds as $id) $result[$id] = ['favorites' => 0, 'plays' => 0, 'history' => 0,
            'playlists' => 0, 'bookmarks' => 0];
        if ($songIds === []) return $result;
        $sources = [
            'favorites' => Db::table('user_song_preferences')->whereIn('song_id', $songIds)->where('is_favorite', 1)
                ->select(['song_id'])->selectRaw('COUNT(*) AS aggregate')->groupBy('song_id'),
            'plays' => Db::table('user_song_play_stats')->whereIn('song_id', $songIds)
                ->select(['song_id'])->selectRaw('COALESCE(SUM(play_count), 0) AS aggregate')->groupBy('song_id'),
            'history' => Db::table('playback_sessions')->whereIn('song_id', $songIds)->whereNull('cleared_at')
                ->select(['song_id'])->selectRaw('COUNT(*) AS aggregate')->groupBy('song_id'),
            'playlists' => Db::table('playlist_items')->whereIn('song_id', $songIds)
                ->select(['song_id'])->selectRaw('COUNT(*) AS aggregate')->groupBy('song_id'),
            'bookmarks' => Db::table('user_song_bookmarks')->whereIn('song_id', $songIds)
                ->select(['song_id'])->selectRaw('COUNT(*) AS aggregate')->groupBy('song_id'),
        ];
        foreach ($sources as $name => $source) {
            /** @var list<stdClass> $rows */
            $rows = $source->get()->all();
            foreach ($rows as $row) $result[(string) $row->song_id][$name] = (int) $row->aggregate;
        }
        return $result;
    }

    /** 构造所有类型共享的可用、已解析歌曲范围。 */
    private function base(array $libraryIds, ?string $query): Builder
    {
        $base = Db::table('media_songs as songs')->join('library_file_inventory as files', 'files.id', '=', 'songs.inventory_file_id')
            ->join('media_albums as albums', 'albums.id', '=', 'songs.album_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'songs.library_id')
            ->whereIn('songs.library_id', $libraryIds)->where('libraries.status', 'active')
            ->where('files.status', 'available')->where('files.metadata_status', 'ready');
        if ($query !== null) {
            $needle = '%' . $this->escapeLike($query) . '%';
            $base->where(function (Builder $search) use ($needle): void {
                $search->whereRaw("songs.title LIKE ? ESCAPE '\\'", [$needle])
                    ->orWhereRaw("albums.title LIKE ? ESCAPE '\\'", [$needle])
                    ->orWhereExists(function (Builder $artist) use ($needle): void {
                        $artist->selectRaw('1')->from('media_song_artists as search_credits')
                            ->join('media_artists as search_artists', 'search_artists.id', '=', 'search_credits.artist_id')
                            ->whereColumn('search_credits.song_id', 'songs.id')
                            ->whereRaw("search_artists.name LIKE ? ESCAPE '\\'", [$needle]);
                    });
            });
        }
        return $base;
    }

    /** 把固定类型的分组键映射回参数化查询，不把列名交给请求。 */
    private function applyKey(Builder $query, string $kind, array $key): void
    {
        if ($kind === 'inode') $query->where('files.device_id', (int) $key['a'])->where('files.inode', (int) $key['b']);
        elseif ($kind === 'byte_hash') $query->where('files.byte_hash_status', 'ready')->where('files.byte_sha256', $key['a']);
        elseif ($kind === 'fingerprint') $query->where('files.acoustic_fingerprint_status', 'ready')
            ->where('files.acoustic_fingerprint_sha256', $key['a']);
        else $query->join('media_song_artists as primary_credit', function ($join): void {
            $join->on('primary_credit.song_id', '=', 'songs.id')->where('primary_credit.role', '=', 'primary')
                ->where('primary_credit.position', '=', 0);
        })->where('songs.normalized_title', $key['a'])->where('primary_credit.artist_id', $key['b']);
    }

    /** @return array<string,int> */
    private function kindCounts(array $libraryIds, ?string $query): array
    {
        $result = [];
        foreach (self::KINDS as $kind) {
            $keys = $this->unreviewedGroupKeys($kind, $libraryIds, $query);
            $result[$kind] = count($keys);
        }
        return $result;
    }

    /** @return array<string,int> */
    private function coverage(array $libraryIds): array
    {
        $base = Db::table('library_file_inventory as files')->join('media_songs as songs', 'songs.inventory_file_id', '=', 'files.id')
            ->whereIn('songs.library_id', $libraryIds)->where('files.status', 'available')->where('files.metadata_status', 'ready');
        $total = (clone $base)->count('files.id');
        return ['total' => $total,
            'byteReady' => (clone $base)->where('files.byte_hash_status', 'ready')->count('files.id'),
            'byteFailed' => (clone $base)->where('files.byte_hash_status', 'failed')->count('files.id'),
            'acousticReady' => (clone $base)->where('files.acoustic_fingerprint_status', 'ready')->count('files.id'),
            'acousticFailed' => (clone $base)->where('files.acoustic_fingerprint_status', 'failed')->count('files.id')];
    }

    /** 质量与个人引用只影响建议排序；任何分数都不能触发删除。 */
    private function retentionScore(array $member): int
    {
        $lossless = in_array(strtolower((string) ($member['audio']['codec'] ?? '')), ['flac', 'alac', 'wavpack', 'ape', 'pcm_s16le', 'pcm_s24le', 'pcm_s32le'], true);
        $refs = array_sum($member['personalReferences']);
        return ($lossless ? 10_000 : 0) + min(5_000, $refs * 100)
            + min(2_000, (int) (($member['audio']['bitDepth'] ?? 0) * 50))
            + min(2_000, (int) (($member['audio']['sampleRate'] ?? 0) / 100))
            + min(1_000, (int) (($member['audio']['bitrate'] ?? 0) / 10_000));
    }

    /** @param list<array<string,mixed>> $members @return list<string> */
    private function retentionReasons(array $recommended, array $members): array
    {
        $reasons = [];
        $codec = strtolower((string) ($recommended['audio']['codec'] ?? ''));
        if (in_array($codec, ['flac', 'alac', 'wavpack', 'ape', 'pcm_s16le', 'pcm_s24le', 'pcm_s32le'], true)) $reasons[] = 'lossless';
        if (array_sum($recommended['personalReferences']) > 0) $reasons[] = 'personal_references';
        $maxSample = max(array_map(static fn (array $item): int => (int) ($item['audio']['sampleRate'] ?? 0), $members));
        if ((int) ($recommended['audio']['sampleRate'] ?? 0) === $maxSample && $maxSample > 0) $reasons[] = 'higher_sample_rate';
        $maxDepth = max(array_map(static fn (array $item): int => (int) ($item['audio']['bitDepth'] ?? 0), $members));
        if ((int) ($recommended['audio']['bitDepth'] ?? 0) === $maxDepth && $maxDepth > 0) $reasons[] = 'higher_bit_depth';
        if ($reasons === []) $reasons[] = 'stable_tiebreak';
        return $reasons;
    }

    /** @return array<string,array{id:string,name:string}> */
    private function managedLibraries(array $actor): array
    {
        $result = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                && is_string($library['id'] ?? null) && is_string($library['name'] ?? null)) {
                $result[$library['id']] = ['id' => $library['id'], 'name' => $library['name']];
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function empty(string $kind, int $limit, int $offset, array $libraries): array
    {
        return ['kind' => $kind, 'groups' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset,
            'libraries' => $libraries, 'counts' => array_fill_keys(self::KINDS, 0),
            'coverage' => ['total' => 0, 'byteReady' => 0, 'byteFailed' => 0, 'acousticReady' => 0, 'acousticFailed' => 0],
            'policy' => ['readOnly' => false, 'automaticDeletion' => false,
                'deletionAvailable' => false, 'differentEditionsRequireReview' => true]];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** 组摘要绑定证据与当前库范围；证据或管理范围变化会让旧决定失效。 */
    private function groupId(string $kind, array $key, array $libraryIds): string
    {
        sort($libraryIds);
        return substr(hash('sha256', $kind . "\0" . $key['a'] . "\0" . ($key['b'] ?? '')
            . "\0" . implode("\0", $libraryIds)), 0, 24);
    }
}
