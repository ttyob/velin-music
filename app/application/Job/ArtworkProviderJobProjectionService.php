<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 把远程封面搜索和导入任务投影为统一任务中心安全契约。
 *
 * 适配器只读取任务头、实体显示名、库和发起者；不连接候选、图片 BLOB、远端 ID、证据摘要、许可、
 * attribution、幂等摘要或 Worker 字段。入口要求 `edit_metadata + run_scrape`；普通管理员还必须实时
 * manage 任务库，且艺术家在其他关联库的 manage 权限撤回后整项隐藏。
 */
final readonly class ArtworkProviderJobProjectionService
{
    private const TABLES = [
        'artwork_provider_search' => 'artwork_provider_search_jobs',
        'artwork_provider_import' => 'artwork_provider_import_jobs',
    ];

    /**
     * 返回一种远程封面任务的授权页。
     *
     * 所有过滤叠加在实体权限查询后；方法没有网络、文件或写事务副作用。
     *
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    public function page(string $type, array $actor, ?string $libraryId, ?string $status,
        ?string $requestedBy, ?string $createdFrom, ?string $createdBefore, int $limit, int $offset): array
    {
        $this->requireCapabilities($actor);
        $query = $this->scopedQuery($type, $actor);
        if ($libraryId !== null) $query->where('jobs.library_id', $libraryId);
        if ($status !== null) $query->where('jobs.status', $status);
        if ($requestedBy !== null) $query->where('jobs.requested_by', $requestedBy);
        if ($createdFrom !== null) $query->where('jobs.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('jobs.created_at', '<', $createdBefore);
        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.created_at')->orderByDesc('jobs.id')->offset(max(0, $offset))
            ->limit(max(1, min(100, $limit)))->get($this->columns())->all();
        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($type, $row), $rows),
            'total' => $total];
    }

    /** 在两个固定事实表中查找重新授权的任务；null 合并不存在与失权。 */
    public function find(array $actor, string $jobId): ?array
    {
        $this->requireCapabilities($actor);
        foreach (array_keys(self::TABLES) as $type) {
            /** @var stdClass|null $row */
            $row = $this->scopedQuery($type, $actor)->where('jobs.id', $jobId)->first($this->columns());
            if ($row instanceof stdClass) return $this->map($type, $row);
        }
        return null;
    }

    /**
     * 只从当前可见任务生成库和发起者筛选项，不枚举无任务账号或失权实体。
     *
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(string $type, array $actor): array
    {
        $this->requireCapabilities($actor);
        $base = $this->scopedQuery($type, $actor);
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])->distinct()->orderBy('libraries.name')
            ->get()->map(static fn (stdClass $row): array => ['id' => (string) $row->id,
                'label' => (string) $row->name])->all();
        $requesters = (clone $base)->whereNotNull('requesters.display_name')
            ->select(['jobs.requested_by as id', 'requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->label,
            ])->all();
        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /**
     * 构造固定事实查询并应用实体完整 manage 范围。
     *
     * 非超级管理员先限制任务库，再用两个 NOT EXISTS 排除艺术家在未管理歌曲库或专辑署名库中的
     * 引用。专辑额外要求任务 library 与专辑事实一致。表名只来自常量，浏览器不能注入 SQL 标识符。
     */
    private function scopedQuery(string $type, array $actor): mixed
    {
        $table = self::TABLES[$type] ?? null;
        if ($table === null) throw new JobCenterInvalid('Unsupported artwork provider task type.');
        $query = Db::table($table . ' as jobs')
            ->leftJoin('media_albums as albums', 'albums.id', '=', 'jobs.album_id')
            ->leftJoin('media_artists as artists', 'artists.id', '=', 'jobs.artist_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'jobs.requested_by')
            ->where(function ($scope): void {
                $scope->where(function ($album): void {
                    $album->whereNotNull('jobs.album_id')->whereColumn('albums.library_id', 'jobs.library_id');
                })->orWhere(function ($artist): void {
                    $artist->whereNotNull('jobs.artist_id')->whereNotNull('artists.id');
                });
            });
        // 逐曲刮削拥有自己的父任务、授权和详情投影；关联图片只是内部阶段，不能在任务中心重复显示。
        if (Db::connection()->getSchemaBuilder()->hasColumn($table, 'scrape_target_id')) {
            $query->whereNull('jobs.scrape_target_id');
        }
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = $this->managedLibraryIds($actor);
            $query->whereIn('jobs.library_id', $ids ?: [''])
                ->whereNotExists(function ($sub) use ($ids): void {
                    $sub->selectRaw('1')->from('media_song_artists as scoped_song_artists')
                        ->join('media_songs as scoped_songs', 'scoped_songs.id', '=', 'scoped_song_artists.song_id')
                        ->whereColumn('scoped_song_artists.artist_id', 'jobs.artist_id')
                        ->whereNotIn('scoped_songs.library_id', $ids ?: ['']);
                })->whereNotExists(function ($sub) use ($ids): void {
                    $sub->selectRaw('1')->from('media_album_artists as scoped_album_artists')
                        ->join('media_albums as scoped_albums', 'scoped_albums.id', '=', 'scoped_album_artists.album_id')
                        ->whereColumn('scoped_album_artists.artist_id', 'jobs.artist_id')
                        ->whereNotIn('scoped_albums.library_id', $ids ?: ['']);
                });
        }
        return $query;
    }

    /** @return list<string> 固定列排除图片、远端身份、摘要、许可、幂等和 Worker。 */
    private function columns(): array
    {
        return ['jobs.id', 'jobs.album_id', 'jobs.artist_id', 'jobs.library_id', 'jobs.requested_by',
            'jobs.status', 'jobs.phase', 'jobs.failure_count', 'jobs.error_code', 'jobs.created_at',
            'jobs.started_at', 'jobs.finished_at', 'jobs.updated_at', 'albums.title as album_title',
            'artists.name as artist_name', 'requesters.display_name as requester_name'];
    }

    /**
     * 映射单实体任务，不把候选数量当作总进度，也不伪造 ETA、重试或回滚能力。
     *
     * 终态 processed 为 1；失败只显示稳定错误码。来源详情回到实体封面工作区，并携带实体类型和 ID，
     * 不把 libraryId 或服务器路径写入 URL。
     */
    private function map(string $type, stdClass $row): array
    {
        $status = (string) $row->status;
        $finished = in_array($status, ['succeeded', 'failed', 'cancelled'], true);
        $entityType = $row->album_id === null ? 'artist' : 'album';
        $entityId = (string) ($row->album_id ?? $row->artist_id);
        $label = $entityType === 'album' ? (string) $row->album_title : (string) $row->artist_name;
        $errorCode = $row->error_code === null ? null : (string) $row->error_code;
        return [
            'id' => (string) $row->id, 'type' => $type, 'status' => $status, 'phase' => (string) $row->phase,
            'subject' => ['type' => $entityType, 'id' => $entityId, 'label' => $label],
            'requestedBy' => $row->requester_name === null ? null : ['id' => (string) $row->requested_by,
                'label' => (string) $row->requester_name],
            'progress' => ['processed' => $finished ? 1 : 0, 'discovered' => 1,
                'failed' => $status === 'failed' ? 1 : 0, 'percent' => null, 'speed' => null,
                'etaSeconds' => null],
            'attempt' => (int) $row->failure_count + 1,
            'error' => $errorCode === null ? null : ['code' => $errorCode,
                'message' => $type === 'artwork_provider_search'
                    ? '远程封面候选搜索失败，请返回实体封面工作区重新发起。'
                    : '远程封面导入失败，请返回实体封面工作区检查候选。'],
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => ['canCancel' => false, 'canRetry' => false, 'canRollback' => false,
                'cancelBehavior' => null, 'expectedVersion' => null],
            'sourceDetailHref' => '/admin/media/entity?type=' . $entityType . '&entityId=' . rawurlencode($entityId),
            'errorSamples' => null,
        ];
    }

    /** @return list<string> 普通 actor 仅把 manage grant 计入共享实体完整授权。 */
    private function managedLibraryIds(array $actor): array
    {
        $ids = [];
        foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
            if (is_array($library) && is_string($library['id'] ?? null)
                && ($library['accessLevel'] ?? null) === 'manage') $ids[] = $library['id'];
        }
        return array_values(array_unique($ids));
    }

    /** 双能力缺一即拒绝，前端隐藏入口不能替代该边界。 */
    private function requireCapabilities(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported artwork provider task type.');
        }
    }
}
