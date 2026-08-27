<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 将上传会话事实投影为后台统一任务中心契约（UPLOAD-008、OPS-JOB-001/002）。
 *
 * 该服务不复制或改写上传状态，不枚举暂存目录，也不运行哈希/FFprobe。入口要求
 * `manage_storage`，非超级管理员同时只能读取当前 Session 快照中拥有 manage 权限的目标库。
 * 上传工作台固定在 `/admin/uploads`；会话所有权不能替代后台能力或目标库管理授权。
 */
final readonly class UploadJobProjectionService
{
    /**
     * 返回经授权和固定筛选收窄的上传任务页。
     *
     * 任务中心状态会显式映射到多个上传原状态，不使用模糊 LIKE 或浏览器提供的表名。日期
     * 使用 UTC 半开区间，所有条件都位于库权限范围之内。
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
        $this->requireCapability($actor);
        $query = $this->scopedQuery($actor);
        if ($libraryId !== null) $query->where('sessions.library_id', $libraryId);
        if ($status !== null) $query->whereIn('sessions.status', $this->sourceStatuses($status));
        if ($requestedBy !== null) $query->where('sessions.user_id', $requestedBy);
        if ($createdFrom !== null) $query->where('sessions.created_at', '>=', $createdFrom);
        if ($createdBefore !== null) $query->where('sessions.created_at', '<', $createdBefore);
        $total = (clone $query)->count('sessions.id');
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('sessions.created_at')->orderByDesc('sessions.id')
            ->offset(max(0, $offset))->limit(max(1, min(100, $limit)))
            ->get($this->columns())->all();
        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows), 'total' => $total];
    }

    /**
     * 按 ULID 读取一个受权上传投影。
     *
     * 返回 null 同时表示不存在和无权；统一服务会再将其转换为 404，不允许 ULID 枚举。
     *
     * @param array<string,mixed> $actor 当前 Session 身份快照。
     * @return array<string,mixed>|null
     */
    public function find(array $actor, string $jobId): ?array
    {
        $this->requireCapability($actor);
        /** @var stdClass|null $row */
        $row = $this->scopedQuery($actor)->where('sessions.id', $jobId)->first($this->columns());
        return $row instanceof stdClass ? $this->map($row) : null;
    }

    /**
     * 只从当前可见上传事实构建库和发起者筛选项。
     *
     * 不枚举没有上传的账号，也不要求 `manage_users`；库授权撤销后对应上传者和选项会一起消失。
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
                'id' => (string) $row->id, 'label' => (string) $row->name,
            ])->all();
        $requesters = (clone $base)->select(['owners.id', 'owners.display_name'])->distinct()
            ->orderBy('owners.display_name')->get()->map(static fn (stdClass $row): array => [
                'id' => (string) $row->id, 'label' => (string) $row->display_name,
            ])->all();
        return ['libraries' => $libraries, 'requesters' => $requesters];
    }

    /** 构造带音乐库 manage 范围的固定上传事实查询。 */
    private function scopedQuery(array $actor)
    {
        $query = Db::table('upload_sessions as sessions')
            ->join('music_libraries as libraries', 'libraries.id', '=', 'sessions.library_id')
            ->join('users as owners', 'owners.id', '=', 'sessions.user_id');
        if (($actor['isSuperAdmin'] ?? false) !== true) {
            $ids = [];
            foreach (is_array($actor['libraries'] ?? null) ? $actor['libraries'] : [] as $library) {
                if (is_array($library) && ($library['accessLevel'] ?? null) === 'manage'
                    && is_string($library['id'] ?? null)) $ids[] = $library['id'];
            }
            $query->whereIn('sessions.library_id', array_values(array_unique($ids)) ?: ['']);
        }
        return $query;
    }

    /** @return list<string> 固定投影列不包含幂等摘要、Worker、路径或文件清单。 */
    private function columns(): array
    {
        return [
            'sessions.id', 'sessions.user_id', 'sessions.library_id', 'sessions.status',
            'sessions.total_files', 'sessions.total_bytes', 'sessions.received_bytes',
            'sessions.completed_files', 'sessions.published_files', 'sessions.attempt',
            'sessions.version', 'sessions.error_code', 'sessions.created_at', 'sessions.started_at',
            'sessions.finished_at', 'sessions.updated_at', 'libraries.name as library_name',
            'owners.display_name as owner_name',
        ];
    }

    /**
     * 将上传原状态与真实计数映射到统一契约。
     *
     * 仅接收字节阶段使用已持久化的 received/total 计算百分比；哈希、媒体校验和公布阶段没有
     * 可信子进度，因此 percent/speed/ETA 为 null。任何失败只返回稳定错误码和安全摘要。
     *
     * @return array<string,mixed>
     */
    private function map(stdClass $row): array
    {
        $sourceStatus = (string) $row->status;
        $status = match ($sourceStatus) {
            'ready' => 'queued',
            'created', 'uploading', 'publishing' => 'running',
            'completed' => 'succeeded',
            'cancelled', 'expired' => 'cancelled',
            default => 'failed',
        };
        $phase = match ($sourceStatus) {
            'created' => 'awaiting_upload',
            'uploading' => 'receiving',
            'ready' => 'queued_validation',
            'publishing' => 'validating_and_publishing',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            'expired' => 'expired',
            default => 'failed',
        };
        $percent = null;
        if (in_array($sourceStatus, ['created', 'uploading'], true) && (int) $row->total_bytes > 0) {
            $percent = min(100, round(((int) $row->received_bytes / (int) $row->total_bytes) * 100, 1));
        } elseif ($sourceStatus === 'completed') {
            $percent = 100.0;
        }
        $errorCode = $row->error_code === null ? null : (string) $row->error_code;
        return [
            'id' => (string) $row->id,
            'type' => 'upload',
            'status' => $status,
            'phase' => $phase,
            'subject' => ['type' => 'library', 'id' => (string) $row->library_id,
                'label' => (string) $row->library_name],
            'requestedBy' => ['id' => (string) $row->user_id, 'label' => (string) $row->owner_name],
            'progress' => [
                'processed' => (int) $row->completed_files,
                'discovered' => (int) $row->total_files,
                'failed' => $sourceStatus === 'failed' ? 1 : 0,
                'percent' => $percent,
                'speed' => null,
                'etaSeconds' => null,
            ],
            'attempt' => (int) $row->attempt,
            'error' => $errorCode === null ? null : [
                'code' => $errorCode, 'message' => '上传任务执行失败，请查看会话详情。',
            ],
            'createdAt' => (string) $row->created_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'updatedAt' => (string) $row->updated_at,
            'commands' => [
                'canCancel' => in_array($sourceStatus, ['created', 'uploading', 'ready'], true),
                'canRetry' => false,
                'canRollback' => false,
                'cancelBehavior' => in_array($sourceStatus, ['created', 'uploading', 'ready'], true)
                    ? 'immediate' : null,
                'expectedVersion' => (int) $row->version,
            ],
            'sourceDetailHref' => '/admin/uploads?sessionId=' . rawurlencode((string) $row->id),
            'errorSamples' => null,
        ];
    }

    /** @return list<string> 统一状态到上传原状态的固定、可审计映射。 */
    private function sourceStatuses(string $status): array
    {
        return match ($status) {
            'queued' => ['ready'],
            'running' => ['created', 'uploading', 'publishing'],
            'cancelled' => ['cancelled', 'expired'],
            'succeeded' => ['completed'],
            'failed' => ['failed'],
            default => ['__unsupported__'],
        };
    }

    /** 重复检查存储能力，防止适配器被统一服务以外的调用方误用。 */
    private function requireCapability(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('manage_storage', $capabilities, true)) {
            throw new JobCenterInvalid('Unsupported upload task type.');
        }
    }
}
