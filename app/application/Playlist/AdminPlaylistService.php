<?php

declare(strict_types=1);

namespace app\application\Playlist;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理后台歌单目录与系统歌单生命周期。
 *
 * 用户 Tab 只读取全站歌单头、所有者和汇总计数，不读取私有歌曲、导入条目或封面字节；系统 Tab 才返回
 * 可编辑字段和脱敏同步状态。调用前必须由 Controller 校验 `manage_system`；自动补全开关可作用于用户或
 * 系统歌单，但其他编辑和删除操作仍固定系统 scope，防止误把普通用户歌单改成系统资源。删除只级联歌单项目、封面、导入摘要和同步规则，
 * 永远不删除媒体文件或用户。
 */
final readonly class AdminPlaylistService
{
    public function __construct(private AuditLogger $audit = new AuditLogger())
    {
    }

    /**
     * 分页返回用户或系统歌单头。
     *
     * search 只匹配歌单名、用户名和显示名，最多 100 字；用户列表的 coverUrl 固定为空，避免管理目录
     * 成为读取私人图片的旁路。结果不按当前管理员的音乐库授权展开歌曲，因此 songCount/durationMs 是
     * 保存时汇总，只用于运维识别，不承诺当前可播放数量。
     *
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function page(string $scope, string $search, int $limit, int $offset): array
    {
        if (!in_array($scope, ['user', 'system'], true)) throw new PlaylistInvalid('scope is invalid.');
        $search = trim($search);
        if (mb_strlen($search) > 100) throw new PlaylistInvalid('search is too long.');
        $limit = max(1, min(100, $limit));
        $offset = max(0, min(10_000, $offset));
        $query = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->leftJoin('system_playlist_sync_rules as sync', 'sync.playlist_id', '=', 'playlists.id')
            ->where('playlists.scope', $scope);
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($filter) use ($escaped): void {
                $filter->where('playlists.name', 'like', '%' . $escaped . '%')
                    ->orWhere('owners.username', 'like', '%' . $escaped . '%')
                    ->orWhere('owners.display_name', 'like', '%' . $escaped . '%');
            });
        }
        $total = (clone $query)->count('playlists.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('playlists.updated_at')->orderBy('playlists.id')->offset($offset)->limit($limit)
            ->get($this->columns())->all();

        $missingCounts = $this->missingCounts(array_map(static fn (stdClass $row): string => (string) $row->id, $rows));
        return [
            'items' => array_map(fn (stdClass $row): array => $this->map(
                $row,
                $scope,
                $missingCounts[(string) $row->id] ?? 0,
            ), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * 在共享歌单版本锁下修改系统歌单名称和说明。
     *
     * 可见性始终固定为 server，不能从后台制造私有系统歌单。同步进程只替换项目和汇总，不修改名称，
     * 因此两者串行提交后都保留对方结果；expectedVersion 过期时整个事务回滚并返回冲突。
     */
    public function updateSystem(string $playlistId, array $payload, string $actorId, string $requestId): array
    {
        $this->assertId($playlistId);
        $command = (new PlaylistValidator())->update($payload + ['visibility' => 'server']);
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $row */
            $row = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')->first(['id', 'version']);
            if (!$row instanceof stdClass) throw new PlaylistNotFound('System playlist not found.');
            if ((int) $row->version !== $command['expectedVersion']) {
                throw new PlaylistConflict('系统歌单已发生变化，请重新加载。');
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('playlists')->where('id', $playlistId)->where('version', $command['expectedVersion'])
                ->update([
                    'name' => $command['name'], 'description' => $command['description'], 'visibility' => 'server',
                    'version' => $command['expectedVersion'] + 1, 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new PlaylistConflict('系统歌单已发生变化，请重新加载。');
            $this->audit->record($actorId, 'system_playlist.update', 'playlist', $playlistId, 'success', $requestId,
                ['version' => $command['expectedVersion'] + 1]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }

        return $this->findSystem($playlistId);
    }

    /**
     * 按版本删除一个系统歌单及其业务子记录。
     *
     * 外键级联只作用于列表项、封面、导入报告和同步规则；歌曲、媒体文件、音乐库和用户均不变化。
     * 事务内重新确认 scope，任何用户歌单 ID 都按不存在处理。重复删除不会伪装成功。
     */
    public function deleteSystem(string $playlistId, int $expectedVersion, string $actorId, string $requestId): void
    {
        $this->assertId($playlistId);
        if ($expectedVersion < 1) throw new PlaylistInvalid('expectedVersion is invalid.');
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $row */
            $row = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')->first(['version']);
            if (!$row instanceof stdClass) throw new PlaylistNotFound('System playlist not found.');
            if ((int) $row->version !== $expectedVersion) throw new PlaylistConflict('系统歌单已发生变化，请重新加载。');
            $changed = Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')
                ->where('version', $expectedVersion)->delete();
            if ($changed !== 1) throw new PlaylistConflict('系统歌单已发生变化，请重新加载。');
            $this->audit->record($actorId, 'system_playlist.delete', 'playlist', $playlistId, 'success', $requestId,
                ['version' => $expectedVersion]);
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }
    }

    /** 返回单条系统歌单管理投影，供写操作使用最新版本替换页面快照。 */
    public function findSystem(string $playlistId): array
    {
        return $this->findProjected($playlistId, 'system');
    }

    /**
     * 返回一条后台可管理歌单的摘要投影，供自动补全开关写操作使用。
     *
     * 本方法只查询 user/system 两种后台目录 scope，不展开用户歌单内容；用户歌单的详情仍必须走
     * 管理专用详情方法以维持隐私和实时媒体授权边界。调用者已经通过 Controller 获得 `manage_system`，
     * 本方法只返回脱敏计数、版本和自动补全状态，不产生写副作用。
     */
    public function findAdminPlaylist(string $playlistId): array
    {
        return $this->findProjected($playlistId, null);
    }

    /** @param 'system'|null $scope null 表示后台目录允许的 user/system 两种 scope。 */
    private function findProjected(string $playlistId, ?string $scope): array
    {
        $this->assertId($playlistId);
        /** @var stdClass|null $row */
        $query = Db::table('playlists as playlists')
            ->join('users as owners', 'owners.id', '=', 'playlists.owner_user_id')
            ->leftJoin('playlist_covers as covers', 'covers.playlist_id', '=', 'playlists.id')
            ->leftJoin('system_playlist_sync_rules as sync', 'sync.playlist_id', '=', 'playlists.id')
            ->where('playlists.id', $playlistId);
        if ($scope === null) $query->whereIn('playlists.scope', ['user', 'system']);
        else $query->where('playlists.scope', $scope);
        /** @var stdClass|null $row */
        $row = $query->first($this->columns());
        if (!$row instanceof stdClass) throw new PlaylistNotFound($scope === 'system' ? 'System playlist not found.' : 'Playlist not found.');
        $missingCounts = $this->missingCounts([$playlistId]);
        return $this->map($row, (string) $row->scope, $missingCounts[$playlistId] ?? 0);
    }

    /**
     * 返回系统歌单的可播放歌曲与全部导入条目，供后台只读弹窗检查同步结果。
     *
     * 调用前 Controller 必须验证 `manage_system`；本方法仍先以 `scope=system` 锁定对象边界，再委托普通
     * 歌单读取层应用实时音乐库授权和媒体可用性。用户歌单即使是 server 可见也统一按不存在处理，避免
     * 管理目录绕过“用户 Tab 只读摘要”的隐私约束。方法无写副作用，读取期间对象删除或失权会失败关闭。
     *
     * @param array<string,mixed> $actor 已认证且拥有系统管理能力的调用者。
     * @return array<string,mixed> 不含路径、外部链接和第三方原始响应的歌单详情。
     */
    public function detailSystem(array $actor, string $playlistId): array
    {
        $this->assertId($playlistId);
        if (!Db::table('playlists')->where('id', $playlistId)->where('scope', 'system')->exists()) {
            throw new PlaylistNotFound('System playlist not found.');
        }
        $detail = (new PlaylistService())->detail($actor, $playlistId);
        if (($detail['scope'] ?? null) !== 'system') throw new PlaylistNotFound('System playlist not found.');
        return $detail;
    }

    /**
     * 返回后台列表中用户或系统歌单的完整只读内容。
     *
     * Controller 已经固定校验 `manage_system`；PlaylistService 只绕过私有可见性判断，仍按管理员实时的
     * 音乐库授权过滤歌曲和导入条目。该方法不改变用户歌单的 owner 编辑权限，也不允许任何普通用户路由
     * 复用后台旁路。
     *
     * @param array<string,mixed> $actor 已认证且拥有系统管理能力的调用者。
     * @return array<string,mixed> 不含路径、外部链接和第三方原始响应的歌单详情。
     */
    public function detailAdmin(array $actor, string $playlistId): array
    {
        $this->assertId($playlistId);
        return (new PlaylistService())->detailForAdmin($actor, $playlistId);
    }

    /** 统一列表和写后读取的列集合，避免封面或同步状态投影随入口漂移。 */
    private function columns(): array
    {
        return [
            'playlists.id', 'playlists.name', 'playlists.description', 'playlists.kind', 'playlists.scope',
            'playlists.source', 'playlists.source_key', 'playlists.song_count', 'playlists.duration_ms',
            'playlists.version', 'playlists.created_at', 'playlists.updated_at',
            'playlists.auto_completion_enabled', 'playlists.auto_completion_updated_at',
            'owners.id as owner_id', 'owners.username as owner_username', 'owners.display_name as owner_display_name',
            'covers.content_sha256 as cover_digest', 'sync.provider as sync_provider', 'sync.preset as sync_preset',
            'sync.enabled as sync_enabled', 'sync.interval_seconds as sync_interval_seconds',
            'sync.last_attempt_at', 'sync.last_success_at', 'sync.last_error_code', 'sync.next_sync_at',
            'sync.version as sync_version',
        ];
    }

    /** 将数据库行映射为不含歌曲和秘密的后台摘要。 */
    private function map(stdClass $row, string $scope, int $missingCount): array
    {
        $digest = is_string($row->cover_digest ?? null) ? (string) $row->cover_digest : '';
        $coverUrl = $scope === 'system' && preg_match('/^[a-f0-9]{64}$/', $digest) === 1
            ? '/api/v1/playlists/' . rawurlencode((string) $row->id) . '/cover?v=' . substr($digest, 0, 16)
            : null;
        $sync = $row->sync_provider === null ? null : [
            'provider' => (string) $row->sync_provider,
            'preset' => (string) $row->sync_preset,
            'enabled' => (int) $row->sync_enabled === 1,
            'intervalSeconds' => (int) $row->sync_interval_seconds,
            'lastAttemptAt' => $row->last_attempt_at === null ? null : (string) $row->last_attempt_at,
            'lastSuccessAt' => $row->last_success_at === null ? null : (string) $row->last_success_at,
            'lastErrorCode' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            'nextSyncAt' => $row->next_sync_at === null ? null : (string) $row->next_sync_at,
            'version' => (int) $row->sync_version,
        ];
        return [
            'id' => (string) $row->id, 'name' => (string) $row->name,
            'description' => $row->description === null ? null : (string) $row->description,
            'kind' => (string) $row->kind, 'scope' => (string) $row->scope, 'source' => (string) $row->source,
            'sourceKey' => $row->source_key === null ? null : (string) $row->source_key,
            'songCount' => (int) $row->song_count, 'missingCount' => $missingCount,
            'durationMs' => (int) $row->duration_ms,
            'version' => (int) $row->version, 'coverUrl' => $coverUrl,
            'owner' => ['id' => (string) $row->owner_id, 'username' => (string) $row->owner_username,
                'displayName' => (string) $row->owner_display_name],
            'sync' => $sync, 'autoCompletion' => $this->autoCompletion((string) $row->id, $row),
            'createdAt' => (string) $row->created_at, 'updatedAt' => (string) $row->updated_at,
        ];
    }

    /** 在用户和系统歌单摘要中投影自动补全状态；租约、插件 key 和错误正文永远不进入后台列表。 */
    private function autoCompletion(string $playlistId, stdClass $row): ?array
    {
        if (!property_exists($row, 'auto_completion_enabled')) return null;
        if (!Db::connection()->getSchemaBuilder()->hasTable('playlist_auto_completion_jobs')) {
            return ['enabled' => (int) $row->auto_completion_enabled === 1, 'updatedAt' => null,
                'counts' => ['queued' => 0, 'searching' => 0, 'downloading' => 0, 'importing' => 0,
                    'succeeded' => 0, 'failed' => 0, 'skipped' => 0], 'total' => 0, 'maxAttempts' => 3];
        }
        $counts = Db::table('playlist_auto_completion_jobs')->where('playlist_id', $playlistId)
            ->select(['status', Db::raw('COUNT(*) AS amount')])->groupBy('status')->get()->all();
        $result = ['queued' => 0, 'searching' => 0, 'downloading' => 0, 'importing' => 0,
            'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($counts as $count) if (array_key_exists((string) $count->status, $result)) $result[(string) $count->status] = (int) $count->amount;
        return ['enabled' => (int) $row->auto_completion_enabled === 1,
            'updatedAt' => property_exists($row, 'auto_completion_updated_at') && $row->auto_completion_updated_at !== null
                ? (string) $row->auto_completion_updated_at : null,
            'counts' => $result, 'total' => array_sum($result), 'maxAttempts' => 3];
    }

    /**
     * 批量统计后台歌单中明确标记为本地资源缺失的远端条目。
     *
     * 查询只接收已由后台歌单头查询选出的 ID，不读取来源标题、歌曲或封面，因此不会扩大用户歌单数据
     * 范围。滚动升级或精简测试库尚无导入条目表时返回零计数；该兼容分支只影响摘要展示，不改变同步。
     *
     * @param list<string> $playlistIds
     * @return array<string,int>
     */
    private function missingCounts(array $playlistIds): array
    {
        if ($playlistIds === [] || !Db::connection()->getSchemaBuilder()->hasTable('playlist_import_entries')) return [];
        /** @var list<stdClass> $rows */
        $rows = Db::table('playlist_import_entries')->whereIn('playlist_id', $playlistIds)
            ->where('status', 'unmatched')->where('reason_code', 'resource_missing')
            ->groupBy('playlist_id')->get(['playlist_id', Db::raw('COUNT(*) AS missing_count')])->all();
        $counts = [];
        foreach ($rows as $row) $counts[(string) $row->playlist_id] = (int) $row->missing_count;
        return $counts;
    }

    /** 仅接受规范 ULID，非法与不存在使用同一领域错误。 */
    private function assertId(string $playlistId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $playlistId) !== 1) {
            throw new PlaylistNotFound('System playlist not found.');
        }
    }
}
