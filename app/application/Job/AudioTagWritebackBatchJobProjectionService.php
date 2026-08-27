<?php

declare(strict_types=1);

namespace app\application\Job;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 将已确认的批量描述元数据音频标签方案投影为统一任务中心父任务。
 *
 * 批次表达管理员的一次操作意图，关联单曲任务只是内部执行单元。适配器只读取父计数、目标库和发起者，
 * 不读取标签值、路径、文件身份、摘要或逐项内部错误。普通管理员必须仍拥有批次全部目标库的 manage
 * 权限；任一目标失权时整批不可见，避免通过父计数推断其他音乐库对象。
 */
final readonly class AudioTagWritebackBatchJobProjectionService
{
    /**
     * 返回实时授权后的批量父任务页。
     *
     * 音乐库筛选表示“批次包含该库”，但授权仍要求调用者可管理批次涉及的所有库。方法只读且分页
     * 有界，不触发状态重建、文件访问或媒体处理。
     *
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
                $targets->selectRaw('1')->from('audio_tag_writeback_batch_targets as selected_targets')
                    ->whereColumn('selected_targets.batch_plan_id', 'batches.id')
                    ->where('selected_targets.library_id', $libraryId);
            });
        }
        if ($status !== null) $query->where('batches.status', $status);
        if ($requestedBy !== null) $query->where('batches.requested_by', $requestedBy);
        if ($createdFrom !== null) $query->where('batches.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('batches.created_at', '<', $createdBefore);

        $total = (clone $query)->count('batches.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('batches.created_at')->orderByDesc('batches.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))
            ->get($this->columns())->all();

        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /**
     * 返回单个可见父任务；null 统一表示不存在、未确认或当前账号失权。
     *
     * @return array<string,mixed>|null
     */
    public function find(array $actor, string $batchId): ?array
    {
        $this->requireCapability($actor);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('batches.id', $batchId)->first($this->columns());

        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 仅从当前可见父任务生成筛选项，不枚举没有任务的库或用户。
     *
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(array $actor): array
    {
        $this->requireCapability($actor);
        $visible = (clone $this->scopedQuery($actor))->select('batches.id');
        $libraries = Db::table('audio_tag_writeback_batch_targets as targets')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'targets.library_id')
            ->whereIn('targets.batch_plan_id', $visible)->select(['libraries.id', 'libraries.name'])->distinct()
            ->orderBy('libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $this->scopedQuery($actor))->whereNotNull('requesters.display_name')
            ->select(['batches.requested_by as id', 'requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id,
                'label' => (string) $row->label,
            ])->all();

        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /** 构造固定事实查询，并对普通管理员执行“全部目标库均可管理”的闭集授权。 */
    private function scopedQuery(array $actor): Builder
    {
        $query = Db::table('audio_tag_writeback_batch_plans as batches')
            ->leftJoin('users as requesters', 'requesters.id', '=', 'batches.requested_by')
            ->whereNotNull('batches.confirmed_at')->where('batches.status', '!=', 'draft');
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && is_string($library['id'] ?? null)
                    && ($library['accessLevel'] ?? null) === 'manage') {
                    $ids[] = $library['id'];
                }
            }
            $ids = array_values(array_unique($ids));
            $query->whereNotExists(function (Builder $targets) use ($ids): void {
                $targets->selectRaw('1')->from('audio_tag_writeback_batch_targets as scope_targets')
                    ->whereColumn('scope_targets.batch_plan_id', 'batches.id')
                    ->whereNotIn('scope_targets.library_id', $ids ?: ['']);
            });
        }

        return $query;
    }

    /** @return list<string> 固定字段集排除方案摘要、幂等摘要、歌曲标识和逐项目标事实。 */
    private function columns(): array
    {
        return [
            'batches.id', 'batches.requested_by', 'batches.target_count', 'batches.processed_count',
            'batches.succeeded_count', 'batches.failed_count', 'batches.status', 'batches.created_at',
            'batches.started_at', 'batches.finished_at', 'batches.updated_at',
            'requesters.display_name as requester_name',
        ];
    }

    /** 以真实子任务计数映射父任务；partial 保持独立终态，不伪造速度或 ETA。 */
    private function map(stdClass $row): array
    {
        $targets = max(1, (int) $row->target_count);
        $failed = (int) $row->failed_count;
        $status = (string) $row->status;

        return [
            'id' => (string) $row->id,
            'type' => 'audio_tag_writeback_batch',
            'status' => $status,
            'phase' => $status === 'queued' ? 'queued'
                : ($status === 'running' ? 'writing'
                    : (in_array($status, ['succeeded', 'partial'], true) ? 'completed' : 'failed')),
            'subject' => [
                'type' => 'audio_tag_writeback_batch',
                'id' => (string) $row->id,
                'label' => '批量音频标签写回 · ' . $targets . ' 首',
            ],
            'requestedBy' => $row->requester_name === null ? null : [
                'id' => (string) $row->requested_by,
                'label' => (string) $row->requester_name,
            ],
            'progress' => [
                'processed' => (int) $row->processed_count,
                'discovered' => $targets,
                'failed' => $failed,
                'percent' => round(min(100, ((int) $row->processed_count / $targets) * 100), 1),
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => 1,
            'error' => $failed < 1 ? null : [
                'code' => $status === 'partial' ? 'AUDIO_TAG_BATCH_PARTIAL' : 'AUDIO_TAG_BATCH_FAILED',
                'message' => $status === 'partial'
                    ? '部分歌曲未能写回，请查看批量方案结果。'
                    : '批量音频标签写回失败，请查看批量方案结果。',
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
            'sourceDetailHref' => '/admin/media/tag-writeback-batch?batchId=' . rawurlencode((string) $row->id),
            'errorSamples' => null,
        ];
    }

    /** 重复校验动作能力，防止适配器脱离统一任务服务后被无权限调用。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('edit_metadata', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported audio tag batch writeback task type.');
        }
    }
}
