<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 将逐曲刮削事实投影到统一“任务与问题”列表。
 *
 * 本服务只读取 `metadata_sync_scrape_*` 事实表，不复制状态、不访问第三方平台，也不读取媒体物理路径。
 * 当前逐曲刮削任务只允许发起者本人查看，并要求 `run_scrape`、`edit_metadata` 以及全部目标音乐库的
 * 实时 manage 权限；任一目标库失权都会隐藏整个批次，不能返回部分歌曲而泄露历史授权对象。
 */
final readonly class SongScrapeJobProjectionService
{
    /**
     * 返回经过能力、发起者和音乐库范围过滤的逐曲刮削任务页。
     *
     * 状态、日期和音乐库条件均使用固定列与白名单映射。资源发布的细节由详情接口重新读取；列表只
     * 展示父任务持久计数，因此不会为了计算进度访问缓存目录或外部渠道。方法只读且可重复调用。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
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
        $query = $this->scopedQuery($actor);
        if ($libraryId !== null) {
            $query->whereExists(function ($target) use ($libraryId): void {
                $target->selectRaw('1')->from('metadata_sync_scrape_targets as selected_targets')
                    ->whereColumn('selected_targets.job_id', 'jobs.id')
                    ->where('selected_targets.library_id', $libraryId);
            });
        }
        if ($status !== null) $query->whereIn('jobs.status', $this->sourceStatuses($status));
        if ($requestedBy !== null) $query->where('jobs.requested_by', $requestedBy);
        if ($createdFrom !== null) $query->where('jobs.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('jobs.created_at', '<', $createdBefore);

        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.created_at')->orderByDesc('jobs.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))
            ->get($this->columns())->all();

        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /**
     * 返回当前管理员仍需选择渠道的逐曲刮削任务。
     *
     * 待确认不能从父任务 `partial` 推断，因为同一状态也可表示资源发布降级；查询必须同时命中
     * confirmation_required 和至少一个 target.awaiting_confirmation。结果继续复用发起者与全部目标库
     * manage 范围，最多返回 20 条供任务中心固定展示，不读取候选正文或外部资源。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{jobs:list<array<string,mixed>>,total:int}
     */
    public function awaitingConfirmation(array $actor, int $limit = 20): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasColumn('metadata_sync_scrape_jobs', 'confirmation_required')
            || !$schema->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
            return ['jobs' => [], 'total' => 0];
        }
        $query = $this->scopedQuery($actor)->where('jobs.confirmation_required', 1)
            ->whereExists(function ($target): void {
                $target->selectRaw('1')->from('metadata_sync_scrape_targets as awaiting_targets')
                    ->whereColumn('awaiting_targets.job_id', 'jobs.id')
                    ->where('awaiting_targets.awaiting_confirmation', 1);
            });
        $total = (clone $query)->count('jobs.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('jobs.updated_at')->orderByDesc('jobs.id')
            ->limit(max(1, min(20, $limit)))->get($this->columns())->all();

        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /**
     * 按任务 ID 返回一个重新授权后的统一投影；不存在和失权均返回 null，阻止任务枚举。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array<string,mixed>|null
     */
    public function find(array $actor, string $jobId): ?array
    {
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('jobs.id', $jobId)->first($this->columns());
        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 只从当前可见逐曲刮削事实生成音乐库和发起者筛选项。
     *
     * 查询不枚举独立用户目录；当前模型只返回本人的任务，因此发起者选项最多一项。音乐库选项仍按
     * 每个任务全部目标均有实时 manage 权限的基础范围生成，撤权后对应选项立即消失。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>}
     */
    public function filterOptions(array $actor): array
    {
        $base = $this->scopedQuery($actor);
        $libraries = (clone $base)
            ->join('metadata_sync_scrape_targets as option_targets', 'option_targets.job_id', '=', 'jobs.id')
            ->join('music_libraries as option_libraries', 'option_libraries.id', '=', 'option_targets.library_id')
            ->select(['option_libraries.id', 'option_libraries.name'])->distinct()
            ->orderBy('option_libraries.name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $base)->select(['owners.id', 'owners.display_name'])->distinct()
            ->orderBy('owners.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->display_name,
            ])->all();

        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /**
     * 构造逐曲刮削的实时授权查询。
     *
     * 非超级管理员使用 Session 已解析的 manage 库集合排除包含任一失权目标的整个父任务；即使调用方
     * 以后放宽列表筛选，也不能绕过这里的失败关闭条件。任务始终限制为当前发起者，符合重新刮削结果
     * 只向操作者本人开放的现有契约。
     */
    private function scopedQuery(array $actor)
    {
        $this->requireCapability($actor);
        $query = Db::table('metadata_sync_scrape_jobs as jobs')
            ->join('users as owners', 'owners.id', '=', 'jobs.requested_by')
            ->where('jobs.requested_by', (string) ($actor['id'] ?? ''));
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                    && is_string($library['id'] ?? null)) $ids[] = $library['id'];
            }
            $manageable = array_values(array_unique($ids)) ?: [''];
            $query->whereNotExists(function ($target) use ($manageable): void {
                $target->selectRaw('1')->from('metadata_sync_scrape_targets as denied_targets')
                    ->whereColumn('denied_targets.job_id', 'jobs.id')
                    ->whereNotIn('denied_targets.library_id', $manageable);
            });
        }
        return $query;
    }

    /** @return list<string> 固定父任务列不包含 request_id、Worker 租约、候选正文或物理路径。 */
    private function columns(): array
    {
        return [
            'jobs.id', 'jobs.requested_by', 'jobs.status', 'jobs.target_count', 'jobs.processed_count',
            'jobs.succeeded_count', 'jobs.unmatched_count', 'jobs.failed_count', 'jobs.created_at',
            'jobs.started_at', 'jobs.finished_at', 'jobs.updated_at', 'owners.display_name as owner_name',
        ];
    }

    /**
     * 将一个父任务映射为通用列表字段，并只读取首个目标的安全歌曲标题作为对象摘要。
     *
     * 多曲任务不拼接全部标题，避免列表过宽；完整渠道候选和资源发布结果由详情弹窗通过原业务接口
     * 获取。列表进度只使用父任务已提交计数，终态可给出 100%，运行态不推算速度或剩余时间。
     *
     * @return array<string,mixed>
     */
    private function map(stdClass $row): array
    {
        /** @var stdClass|null $target */
        $targetColumns = ['targets.song_id', 'targets.error_code', 'targets.attempt', 'songs.title'];
        if (Db::connection()->getSchemaBuilder()->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
            $targetColumns[] = 'targets.awaiting_confirmation';
        }
        $target = Db::table('metadata_sync_scrape_targets as targets')
            ->leftJoin('media_songs as songs', 'songs.id', '=', 'targets.song_id')
            ->where('targets.job_id', (string) $row->id)->orderBy('targets.position')
            ->first($targetColumns);
        $targetCount = (int) $row->target_count;
        $processed = (int) $row->processed_count;
        $status = (string) $row->status;
        $percent = $targetCount > 0 ? round(min(1, $processed / $targetCount) * 100, 1) : null;
        if ($status === 'succeeded') $percent = 100.0;
        $label = $targetCount === 1 && $target instanceof stdClass && is_string($target->title)
            ? $target->title : $targetCount . ' 首歌曲';
        $errorCode = $target instanceof stdClass && is_string($target->error_code)
            ? $target->error_code : null;
        $awaitingConfirmation = $target instanceof stdClass
            && property_exists($target, 'awaiting_confirmation')
            && (int) $target->awaiting_confirmation === 1;

        return [
            'id' => (string) $row->id,
            'type' => 'song_scrape',
            'status' => $status,
            'phase' => $awaitingConfirmation ? 'awaiting_confirmation'
                : (in_array($status, ['succeeded', 'partial', 'failed'], true) ? 'completed' : 'scraping'),
            'attention' => $awaitingConfirmation ? 'confirmation' : null,
            'subject' => [
                'type' => $targetCount === 1 ? 'song' : 'song_batch',
                'id' => $targetCount === 1 && $target instanceof stdClass && is_string($target->song_id)
                    ? $target->song_id : (string) $row->id,
                'label' => $label,
            ],
            'requestedBy' => ['id' => (string) $row->requested_by, 'label' => (string) $row->owner_name],
            'progress' => [
                'processed' => $processed,
                'discovered' => $targetCount,
                'failed' => (int) $row->failed_count,
                'percent' => $percent,
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => $target instanceof stdClass ? (int) $target->attempt : 0,
            'error' => $errorCode === null ? null : [
                'code' => $errorCode,
                'message' => '逐曲刮削未完成，请查看渠道与派生资源结果。',
            ],
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => [
                'canCancel' => false, 'canRetry' => false, 'canRollback' => false,
                'cancelBehavior' => null, 'expectedVersion' => null,
            ],
            'sourceDetailHref' => '/admin/jobs?type=song_scrape&jobId=' . rawurlencode((string) $row->id),
            'errorSamples' => null,
        ];
    }

    /** @return list<string> 统一状态到逐曲刮削父状态的固定映射。 */
    private function sourceStatuses(string $status): array
    {
        return match ($status) {
            'queued' => ['queued'],
            'running' => ['running'],
            'succeeded' => ['succeeded'],
            'partial' => ['partial'],
            'failed' => ['failed'],
            default => ['__unsupported__'],
        };
    }

    /** 双能力缺一即拒绝，防止只会编辑本地元数据的账户枚举外部刮削事实。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('run_scrape', $capabilities, true) || !in_array('edit_metadata', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported song scrape task type.');
        }
    }
}
