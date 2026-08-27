<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 将歌词 sidecar 写回任务投影为统一任务中心的只读安全契约。
 *
 * 本适配器只读取歌词写回事实表，不复制状态、不读取歌词正文，也不访问文件系统。入口要求全局
 * `edit_metadata`，非超级管理员还必须保有目标音乐库的 read 或 manage grant；授权撤销后列表、
 * 筛选项和详情会同时消失。投影禁止返回方案摘要、幂等摘要、目标路径、文件身份及操作日志。
 */
final readonly class LyricsWritebackJobProjectionService
{
    /**
     * 返回当前授权范围内的歌词写回任务页。
     *
     * 状态、发起者、音乐库和 UTC 半开日期条件都在对象授权查询之上叠加。方法只读且有界，失败不会
     * 回退到无范围查询，也不会为了显示进度读取任何歌词文件正文。
     *
     * @param array<string,mixed> $actor 当前 Session 重新解析的身份、能力和音乐库授权快照。
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    public function page(
        array $actor,
        ?string $libraryId,
        ?string $status,
        ?string $requestedBy,
        ?string $createdFrom,
        ?string $createdBefore,
        int $limit,
        int $offset,
    ): array {
        $this->requireCapability($actor);
        $query = $this->scopedQuery($actor);
        if ($libraryId !== null) {
            $query->where('jobs.library_id', $libraryId);
        }
        if ($status !== null) {
            $query->where('jobs.status', $status);
        }
        if ($requestedBy !== null) {
            $query->where('jobs.requested_by', $requestedBy);
        }
        if ($createdFrom !== null) {
            $query->where('jobs.created_at', '>=', $createdFrom);
        }
        if ($createdBefore !== null) {
            $query->where('jobs.created_at', '<', $createdBefore);
        }

        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.created_at')->orderByDesc('jobs.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))
            ->get($this->columns())->all();

        return [
            'jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * 按任务 ULID 返回重新授权后的单项投影。
     *
     * null 同时表示对象不存在和当前身份不可见，统一服务会映射为同一个 404，防止任务 ID 枚举。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array<string,mixed>|null
     */
    public function find(array $actor, string $jobId): ?array
    {
        $this->requireCapability($actor);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('jobs.id', $jobId)->first($this->columns());

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 只从可见歌词任务中生成音乐库与发起者筛选选项。
     *
     * 该查询不会枚举没有任务的用户；失去库 grant 后，对应库和发起者选项会与任务一起消失。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(array $actor): array
    {
        $this->requireCapability($actor);
        $base = $this->scopedQuery($actor);
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $base)->whereNotNull('requesters.display_name')
            ->select(['jobs.requested_by as id', 'requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->label,
            ])->all();

        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /**
     * 构造固定事实表查询并应用歌词编辑的实时库范围。
     *
     * `edit_metadata` 是动作能力，read/manage grant 是对象范围；这里与写回方案服务保持同一授权
     * 语义，不能误收紧为 manage，也不能因拥有全局能力而省略库条件。
     */
    private function scopedQuery(array $actor): mixed
    {
        $query = Db::table('lyrics_writeback_jobs as jobs')
            ->join('lyrics_writeback_plans as plans', 'plans.id', '=', 'jobs.plan_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'jobs.requested_by');
        // 批量操作在任务中心由父方案展示；内部子任务仍保留完整事实，但不能制造几十条重复用户意图。
        $schema = Db::connection()->getSchemaBuilder();
        if ($schema->hasTable('lyrics_writeback_batch_targets')
            && $schema->hasColumn('lyrics_writeback_batch_targets', 'writeback_plan_id')) {
            $query->whereNotExists(function (mixed $targets): void {
                $targets->selectRaw('1')->from('lyrics_writeback_batch_targets as batch_targets')
                    ->whereColumn('batch_targets.writeback_plan_id', 'plans.id');
            });
        }
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (
                    is_array($library)
                    && is_string($library['id'] ?? null)
                    && in_array($library['accessLevel'] ?? null, ['read', 'manage'], true)
                ) {
                    $ids[] = $library['id'];
                }
            }
            $query->whereIn('jobs.library_id', array_values(array_unique($ids)) ?: ['']);
        }

        return $query;
    }

    /** @return list<string> 固定字段集排除歌词、路径、摘要、文件身份、Worker 和请求内部标识。 */
    private function columns(): array
    {
        return [
            'jobs.id', 'jobs.plan_id', 'jobs.library_id', 'jobs.requested_by', 'jobs.status',
            'jobs.phase', 'jobs.attempt', 'jobs.error_code', 'jobs.created_at', 'jobs.started_at',
            'jobs.finished_at', 'jobs.updated_at', 'libraries.name as library_name',
            'requesters.display_name as requester_name',
        ];
    }

    /**
     * 映射一个单曲写回任务，不伪造文件级百分比、速度或 ETA。
     *
     * 任务终态只表示一个固定 sidecar 意图已经处理，故 processed 为 1；错误仅暴露稳定错误码和
     * 固定安全消息。取消、重试与回滚在专用状态机落地前始终关闭。
     *
     * @return array<string,mixed>
     */
    private function map(stdClass $row): array
    {
        $status = (string) $row->status;
        $finished = in_array($status, ['succeeded', 'failed', 'cancelled'], true);
        $errorCode = $row->error_code === null ? null : (string) $row->error_code;

        return [
            'id' => (string) $row->id,
            'type' => 'lyrics_writeback',
            'status' => $status,
            'phase' => (string) $row->phase,
            'subject' => [
                'type' => 'library',
                'id' => (string) $row->library_id,
                'label' => (string) $row->library_name,
            ],
            'requestedBy' => $row->requester_name === null ? null : [
                'id' => (string) $row->requested_by,
                'label' => (string) $row->requester_name,
            ],
            'progress' => [
                'processed' => $finished ? 1 : 0,
                'discovered' => 1,
                'failed' => $status === 'failed' ? 1 : 0,
                'percent' => null,
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => (int) $row->attempt,
            'error' => $errorCode === null ? null : [
                'code' => $errorCode,
                'message' => '歌词写回失败，请查看来源方案。',
            ],
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => [
                'canCancel' => false,
                'canRetry' => false,
                'canRollback' => false,
                'cancelBehavior' => null,
                'expectedVersion' => null,
            ],
            'sourceDetailHref' => '/admin/media?planId=' . rawurlencode((string) $row->plan_id),
            'errorSamples' => null,
        ];
    }

    /** 重复校验全局编辑能力，防止适配器脱离统一服务被误用。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('edit_metadata', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported lyrics writeback task type.');
        }
    }
}
