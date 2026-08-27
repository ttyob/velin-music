<?php

declare(strict_types=1);

namespace app\application\Export;

use app\application\User\HighCostJobPolicy;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 提供个人数据导出排队、列表、取消和受控下载描述符。
 *
 * HTTP 请求只写任务事实，不读取播放历史或创建文件。列表严格按当前账号过滤；下载前根据任务 ID 重新
 * 解析固定 runtime 私有根、服务端文件名、普通文件身份和持久化大小，不接受请求提供路径。个人令牌
 * 不能调用这些接口，Controller 只使用经数据库重验的 Web Session。
 */
final readonly class PersonalDataExportService
{
    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
        private HighCostJobPolicy $highCostJobs = new HighCostJobPolicy(),
    ) {
    }

    /** @return array<string,mixed> */
    public function queue(string $userId, string $requestId): array
    {
        $id = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($id, $now, $requestId, $userId): void {
                $this->highCostJobs->assertCanQueue($userId);
                Db::table('personal_data_export_jobs')->insert([
                    'id' => $id,
                    'user_id' => $userId,
                    'requested_by' => $userId,
                    'request_id' => $requestId,
                    'status' => 'queued',
                    'phase' => 'queued',
                    'artifact_filename' => null,
                    'byte_size' => null,
                    'sha256' => null,
                    'record_count' => 0,
                    'attempt' => 0,
                    'worker_id' => null,
                    'heartbeat_at' => null,
                    'cancel_requested_at' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'expires_at' => null,
                    'error_code' => null,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->audit->record($userId, 'personal_export.queue', 'personal_data_export_job',
                    $id, 'success', $requestId);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new PersonalDataExportConflict('已有个人数据导出正在排队或执行。', previous: $exception);
            }
            throw $exception;
        }
        return $this->findOwned($userId, $id);
    }

    /** @return array{jobs:list<array<string,mixed>>} */
    public function list(string $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = Db::table('personal_data_export_jobs')->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get($this->columns())->all();
        return ['jobs' => array_map(fn (stdClass $row): array => $this->map($row), $rows)];
    }

    /** queued 直接取消；running 只登记请求，Worker 在分页读取/文件发布边界收敛。 */
    public function cancel(string $userId, string $jobId, int $expectedVersion, string $requestId): array
    {
        $this->ulid($jobId);
        if ($expectedVersion < 1) throw new PersonalDataExportInvalid('导出任务版本无效。');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::transaction(function () use ($expectedVersion, $jobId, $now, $requestId, $userId): void {
            /** @var stdClass|null $row */
            $row = Db::table('personal_data_export_jobs')->where('id', $jobId)->where('user_id', $userId)
                ->first(['status', 'version']);
            if (!$row instanceof stdClass) throw new PersonalDataExportNotFound('个人数据导出不存在。');
            if ((int) $row->version !== $expectedVersion
                || !in_array((string) $row->status, ['queued', 'running', 'cancel_requested'], true)) {
                throw new PersonalDataExportConflict('导出任务状态已变化，请刷新后重试。');
            }
            if ((string) $row->status === 'cancel_requested') return;
            $values = (string) $row->status === 'queued' ? [
                'status' => 'cancelled', 'phase' => 'cancelled', 'cancel_requested_at' => $now,
                'finished_at' => $now, 'error_code' => 'PERSONAL_EXPORT_USER_CANCELLED',
            ] : [
                'status' => 'cancel_requested', 'cancel_requested_at' => $now,
                'error_code' => 'PERSONAL_EXPORT_USER_CANCELLED',
            ];
            $changed = Db::table('personal_data_export_jobs')->where('id', $jobId)
                ->where('user_id', $userId)->where('version', $expectedVersion)
                ->where('status', (string) $row->status)->update($values + [
                    'version' => Db::raw('version + 1'), 'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new PersonalDataExportConflict('导出任务状态已变化，请刷新后重试。');
            $this->audit->record($userId, 'personal_export.cancel', 'personal_data_export_job',
                $jobId, 'success', $requestId, ['mode' => $values['status']]);
        });
        return $this->findOwned($userId, $jobId);
    }

    /**
     * 返回一次性响应使用的内部文件描述符；调用者不得序列化 path。
     *
     * @return array{path:string,downloadName:string,sha256:string}
     */
    public function resolveDownload(string $userId, string $jobId): array
    {
        $this->ulid($jobId);
        /** @var stdClass|null $row */
        $row = Db::table('personal_data_export_jobs')->where('id', $jobId)->where('user_id', $userId)
            ->first(['status', 'expires_at', 'artifact_filename', 'byte_size', 'sha256']);
        if (!$row instanceof stdClass) throw new PersonalDataExportNotFound('个人数据导出不存在。');
        if ((string) $row->status !== 'succeeded' || $row->expires_at === null
            || (string) $row->expires_at <= gmdate('Y-m-d\TH:i:s\Z') || !is_string($row->artifact_filename)
            || $row->artifact_filename !== 'personal-export-' . $jobId . '.json'
            || !is_string($row->sha256) || preg_match('/^[a-f0-9]{64}$/', $row->sha256) !== 1) {
            throw new PersonalDataExportInvalid('个人数据导出不可下载或已经过期。');
        }
        $root = $this->artifactRoot();
        $resolvedRoot = realpath($root);
        $candidate = $root . DIRECTORY_SEPARATOR . $row->artifact_filename;
        $resolved = realpath($candidate);
        $stat = $resolved === false ? false : @lstat($resolved);
        if ($resolvedRoot === false || $resolved === false
            || !str_starts_with($resolved . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR)
            || is_link($candidate) || !is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($stat['size'] ?? -1) !== (int) $row->byte_size) {
            throw new PersonalDataExportInvalid('个人数据导出文件不可用。');
        }
        return [
            'path' => $resolved,
            'downloadName' => 'velin-music-personal-data-' . gmdate('Y-m-d') . '.json',
            'sha256' => (string) $row->sha256,
        ];
    }

    /** @return array<string,mixed> */
    private function findOwned(string $userId, string $jobId): array
    {
        /** @var stdClass|null $row */
        $row = Db::table('personal_data_export_jobs')->where('id', $jobId)->where('user_id', $userId)
            ->first($this->columns());
        if (!$row instanceof stdClass) throw new PersonalDataExportNotFound('个人数据导出不存在。');
        return $this->map($row);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'id', 'status', 'phase', 'byte_size', 'sha256', 'record_count', 'attempt', 'error_code',
            'version', 'created_at', 'updated_at', 'started_at', 'finished_at', 'expires_at',
        ];
    }

    /** @return array<string,mixed> */
    private function map(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'byteSize' => $row->byte_size === null ? null : (int) $row->byte_size,
            'sha256' => $row->sha256 === null ? null : (string) $row->sha256,
            'recordCount' => (int) $row->record_count,
            'attempt' => (int) $row->attempt,
            'errorCode' => $row->error_code === null ? null : (string) $row->error_code,
            'version' => (int) $row->version,
            'downloadAvailable' => (string) $row->status === 'succeeded'
                && $row->expires_at !== null && (string) $row->expires_at > gmdate('Y-m-d\TH:i:s\Z'),
            'createdAt' => (string) $row->created_at,
            'updatedAt' => (string) $row->updated_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
        ];
    }

    private function artifactRoot(): string
    {
        return rtrim((string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime')), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'personal-exports';
    }

    private function ulid(string $value): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new PersonalDataExportInvalid('个人数据导出标识无效。');
        }
    }
}
