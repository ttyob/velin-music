<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 将音频标签写回任务投影为统一任务中心的只读安全契约。
 *
 * 适配器只读方案和任务头，不读取元数据快照、操作摘要或文件系统。入口要求 `edit_metadata`，非超级
 * 管理员还必须保有目标音乐库 manage grant，与单曲元数据编辑的对象范围完全一致；撤权后列表、
 * 筛选项和详情同时消失。响应禁止包含路径、标签值、文件身份、幂等摘要、Worker 或请求内部标识。
 */
final readonly class AudioTagWritebackJobProjectionService
{
    /**
     * 返回授权范围内的音频标签写回任务页。
     *
     * 所有筛选都叠加在实时对象权限查询之上；分页有界且只读，不会为了展示状态访问 FFmpeg 或媒体。
     *
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    public function page(array $actor, ?string $libraryId, ?string $status, ?string $requestedBy,
        ?string $createdFrom, ?string $createdBefore, int $limit, int $offset): array
    {
        $this->requireCapability($actor);
        $query = $this->scopedQuery($actor);
        if ($libraryId !== null) $query->where('jobs.library_id', $libraryId);
        if ($status !== null) $query->where('jobs.status', $status);
        if ($requestedBy !== null) $query->where('jobs.requested_by', $requestedBy);
        if ($createdFrom !== null) $query->where('jobs.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('jobs.created_at', '<', $createdBefore);
        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.created_at')->orderByDesc('jobs.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))->get($this->columns())->all();

        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /** 不存在和失权统一返回 null，由任务中心映射为不可枚举的 404。 */
    public function find(array $actor, string $jobId): ?array
    {
        $this->requireCapability($actor);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('jobs.id', $jobId)->first($this->columns());
        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 从当前可见任务中生成库和发起者筛选项；不枚举没有任务的账户或失权音乐库。
     *
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(array $actor): array
    {
        $this->requireCapability($actor);
        $base = $this->scopedQuery($actor);
        $libraries = (clone $base)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $base)->whereNotNull('requesters.display_name')
            ->select(['jobs.requested_by as id', 'requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->label,
            ])->all();
        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /** 构造固定事实查询并应用与元数据详情相同的 manage 级音乐库范围。 */
    private function scopedQuery(array $actor): mixed
    {
        $query = Db::table('audio_tag_writeback_jobs as jobs')
            ->join('audio_tag_writeback_plans as plans', 'plans.id', '=', 'jobs.plan_id')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'jobs.library_id')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'jobs.requested_by');
        // 已关联批量父方案的任务是内部执行单元，统一任务中心只展示一次用户批量意图。
        if (Db::connection()->getSchemaBuilder()->hasTable('audio_tag_writeback_batch_targets')) {
            $query->whereNotExists(function ($targets): void {
                $targets->selectRaw('1')->from('audio_tag_writeback_batch_targets as batch_targets')
                    ->whereColumn('batch_targets.writeback_plan_id', 'jobs.plan_id');
            });
        }
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                    && is_string($library['id'] ?? null)) $ids[] = $library['id'];
            }
            $query->whereIn('jobs.library_id', array_values(array_unique($ids)) ?: ['']);
        }
        return $query;
    }

    /** @return list<string> 固定列集排除元数据快照、路径、摘要、文件身份和内部调度字段。 */
    private function columns(): array
    {
        return [
            'jobs.id', 'jobs.plan_id', 'jobs.library_id', 'jobs.requested_by', 'jobs.status', 'jobs.phase',
            'jobs.attempt', 'jobs.error_code', 'jobs.created_at', 'jobs.started_at', 'jobs.finished_at',
            'jobs.updated_at', 'libraries.name as library_name', 'requesters.display_name as requester_name',
        ];
    }

    /** 单曲任务不伪造百分比、速度或 ETA，失败只暴露稳定错误码和固定安全摘要。 */
    private function map(stdClass $row): array
    {
        $status = (string) $row->status;
        $finished = in_array($status, ['succeeded', 'failed', 'cancelled'], true);
        $errorCode = $row->error_code === null ? null : (string) $row->error_code;
        return [
            'id' => (string) $row->id, 'type' => 'audio_tag_writeback', 'status' => $status,
            'phase' => (string) $row->phase,
            'subject' => ['type' => 'library', 'id' => (string) $row->library_id,
                'label' => (string) $row->library_name],
            'requestedBy' => $row->requester_name === null ? null : [
                'id' => (string) $row->requested_by, 'label' => (string) $row->requester_name,
            ],
            'progress' => ['processed' => $finished ? 1 : 0, 'discovered' => 1,
                'failed' => $status === 'failed' ? 1 : 0, 'percent' => null, 'speed' => null, 'etaSeconds' => null],
            'attempt' => (int) $row->attempt,
            'error' => $errorCode === null ? null : ['code' => $errorCode,
                'message' => '音频标签写回失败，请查看来源方案。'],
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => ['canCancel' => false, 'canRetry' => false, 'canRollback' => false,
                'cancelBehavior' => null, 'expectedVersion' => null],
            'sourceDetailHref' => '/admin/media/tag-writeback?planId=' . rawurlencode((string) $row->plan_id),
            'errorSamples' => null,
        ];
    }

    /** 适配器独立校验全局编辑能力，防止脱离任务中心时被误用。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('edit_metadata', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported audio tag writeback task type.');
        }
    }
}
