<?php

declare(strict_types=1);

namespace app\application\Job;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 将已经确认的批量元数据方案投影为统一任务中心契约。
 *
 * 批量方案本身就是 Durable Worker 的任务事实，因此本适配器不会复制状态或另建任务表。草稿只是
 * 24 小时预览，不代表已经提交的异步工作，必须从任务中心排除。非超级管理员只有在方案的全部冻结
 * 目标仍位于其 manage 音乐库范围内时才能看到方案；任一目标失权都会隐藏整项，避免通过汇总计数
 * 推断其他音乐库的数据。所有入口均要求 `edit_metadata`，且只返回计数、稳定错误摘要和不透明 ID，
 * 不返回字段值、原始标签、物理路径或逐歌曲内部版本。
 */
final readonly class MetadataBatchJobProjectionService
{
    /**
     * 返回当前身份可见的已确认批量任务页。
     *
     * 状态、音乐库、发起者和 UTC 半开日期条件均叠加在完整对象授权之后。音乐库筛选表示方案至少
     * 包含该库，而不是把跨库方案拆成多项；总数因此仍按方案计数。查询只读且最多返回 100 行。
     *
     * @param array<string,mixed> $actor 当前 Session 重新解析的能力和音乐库授权快照。
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
            $query->whereExists(function (Builder $targets) use ($libraryId): void {
                $targets->selectRaw('1')->from('metadata_batch_targets as selected_targets')
                    ->whereColumn('selected_targets.plan_id', 'plans.id')
                    ->where('selected_targets.library_id', $libraryId);
            });
        }
        if ($status !== null) $query->where('plans.status', $status);
        if ($requestedBy !== null) $query->where('plans.requested_by', $requestedBy);
        if ($createdFrom !== null) $query->where('plans.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('plans.created_at', '<', $createdBefore);

        $total = (clone $query)->count('plans.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('plans.created_at')->orderByDesc('plans.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))
            ->get($this->columns())->all();

        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /**
     * 返回一个重新授权后的已确认方案投影。
     *
     * null 同时表示不存在、仍是草稿或当前身份已失去任一目标库的 manage 权限，统一入口据此返回同一
     * 404，避免不透明 ID 被用来探测方案生命周期或跨库目标。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array<string,mixed>|null
     */
    public function find(array $actor, string $planId): ?array
    {
        $this->requireCapability($actor);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('plans.id', $planId)->first($this->columns());

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 只从可见且已确认的方案生成音乐库和发起者筛选项。
     *
     * 音乐库选项来自目标表，不会枚举没有批量任务的库；发起者删除后保留 null 系统显示，但不生成
     * 无效筛选项。该方法与列表共享完整方案授权，不能泄露部分可见的跨库方案。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(array $actor): array
    {
        $this->requireCapability($actor);
        $visibleIds = (clone $this->scopedQuery($actor))->select('plans.id');
        $libraries = Db::table('metadata_batch_targets as targets')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'targets.library_id')
            ->whereIn('targets.plan_id', $visibleIds)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $this->scopedQuery($actor))->whereNotNull('requesters.display_name')
            ->select(['plans.requested_by as id', 'requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->label,
            ])->all();

        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /**
     * 构造固定事实表查询，并确保方案全部目标仍属于 actor 的 manage 范围。
     *
     * `edit_metadata` 只是动作能力，不能代替对象授权。SQLite 使用相关 NOT EXISTS 排除包含未授权目标
     * 的方案；未来 MySQL 可保留同一语义并通过 `(plan_id, library_id)` 索引优化。超级管理员仍需全局
     * 能力，但无需枚举 grant。`confirmed_at IS NOT NULL` 是草稿与任务的事实边界。
     */
    private function scopedQuery(array $actor): Builder
    {
        $query = Db::table('metadata_batch_plans as plans')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'plans.requested_by')
            ->whereNotNull('plans.confirmed_at')->where('plans.status', '!=', 'draft');
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $managedIds = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && is_string($library['id'] ?? null)
                    && ($library['accessLevel'] ?? null) === 'manage') {
                    $managedIds[] = $library['id'];
                }
            }
            $managedIds = array_values(array_unique($managedIds));
            $query->whereNotExists(function (Builder $targets) use ($managedIds): void {
                $targets->selectRaw('1')->from('metadata_batch_targets as scope_targets')
                    ->whereColumn('scope_targets.plan_id', 'plans.id')
                    ->whereNotIn('scope_targets.library_id', $managedIds ?: ['']);
            });
        }

        return $query;
    }

    /** @return list<string> 固定白名单排除筛选 JSON、字段操作、样本、摘要、租约和逐目标错误。 */
    private function columns(): array
    {
        return [
            'plans.id', 'plans.requested_by', 'plans.target_count', 'plans.processed_count',
            'plans.succeeded_count', 'plans.failed_count', 'plans.status', 'plans.attempt',
            'plans.created_at', 'plans.started_at', 'plans.finished_at', 'plans.updated_at',
            'requesters.display_name as requester_name',
        ];
    }

    /**
     * 把方案真实状态和计数映射为统一契约，并保留 `partial` 独立终态。
     *
     * 批量任务没有可信速度和 ETA；百分比只在目标数为正时按已处理数计算。失败摘要不包含具体歌曲或
     * 字段值，详细逐项结果仍由来源方案页重新授权读取。当前状态机没有安全的统一取消/重试命令；
     * 失败重试必须在来源页创建只含失败目标的新草稿。
     *
     * @return array<string,mixed>
     */
    private function map(stdClass $row): array
    {
        $status = (string) $row->status;
        $targets = max(1, (int) $row->target_count);
        $failed = (int) $row->failed_count;
        $error = $failed > 0 ? [
            'code' => $status === 'partial' ? 'METADATA_BATCH_PARTIAL' : 'METADATA_BATCH_FAILED',
            'message' => $status === 'partial' ? '部分歌曲未能更新，请查看批量方案中的失败明细。'
                : '批量元数据更新失败，请查看批量方案中的失败明细。',
        ] : null;

        return [
            'id' => (string) $row->id,
            'type' => 'metadata_batch',
            'status' => $status,
            'phase' => $status === 'queued' ? 'queued'
                : ($status === 'running' ? 'applying_metadata'
                    : (in_array($status, ['succeeded', 'partial'], true) ? 'completed' : 'failed')),
            'subject' => ['type' => 'metadata_batch', 'id' => (string) $row->id,
                'label' => '批量元数据 · ' . $targets . ' 首'],
            'requestedBy' => $row->requester_name === null ? null : [
                'id' => (string) $row->requested_by, 'label' => (string) $row->requester_name,
            ],
            'progress' => [
                'processed' => (int) $row->processed_count,
                'discovered' => $targets,
                'failed' => $failed,
                'percent' => round(min(100, ((int) $row->processed_count / $targets) * 100), 1),
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => (int) $row->attempt,
            'error' => $error,
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => ['canCancel' => false, 'canRetry' => false, 'canRollback' => false,
                'cancelBehavior' => null, 'expectedVersion' => null],
            'sourceDetailHref' => '/admin/media/batch?planId=' . rawurlencode((string) $row->id),
            'errorSamples' => null,
        ];
    }

    /** 重复校验全局编辑能力，防止适配器脱离统一服务时绕过动作授权。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('edit_metadata', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported metadata batch task type.');
        }
    }
}
