<?php

declare(strict_types=1);

namespace app\application\SystemError;

use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Observability\SystemErrorSanitizer;
use JsonException;
use stdClass;
use support\Db;

/**
 * 提供仅系统管理员可调用的异常聚合查询和处理状态机。
 *
 * Controller 必须先验证 `manage_system`；本服务不接受用户范围，因为记录属于部署而非个人。列表不返回
 * 调用栈，详情才解码已由捕获器限制的相对应用帧。解决/重开使用版本锁并与审计处于同一短事务；错误
 * 复发时捕获器会原子重开并增加版本，因此旧页面不能误把新故障标成已解决。
 */
final readonly class SystemErrorService
{
    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
        private SystemErrorSanitizer $sanitizer = new SystemErrorSanitizer(),
    )
    {
    }

    /**
     * 返回按最后发生时间倒序的有界页面，以及不受当前筛选影响的待处理摘要。
     *
     * @return array{errors:list<array<string,mixed>>,total:int,limit:int,offset:int,summary:array{open:int,critical:int}}
     */
    public function list(
        string $status,
        ?string $severity,
        ?string $source,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        $query = Db::table('system_error_events');
        if ($status !== 'all') $query->where('status', $status);
        if ($severity !== null) $query->where('severity', $severity);
        if ($source !== null) $query->where('source', $source);
        if ($search !== null) {
            $query->where(static function ($nested) use ($search): void {
                $pattern = '%' . $search . '%';
                $nested->where('message_summary', 'like', $pattern)
                    ->orWhere('exception_class', 'like', $pattern)
                    ->orWhere('error_code', 'like', $pattern)
                    ->orWhere('request_id', 'like', $pattern)
                    ->orWhere('fingerprint', 'like', $pattern);
            });
        }
        $total = (clone $query)->count();
        /** @var list<stdClass> $rows */
        $rows = $query->orderByDesc('last_occurred_at')->orderByDesc('id')
            ->offset($offset)->limit($limit)->get()->all();

        return [
            'errors' => array_map(fn (stdClass $row): array => $this->map($row, false), $rows),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'summary' => [
                'open' => Db::table('system_error_events')->where('status', 'open')->count(),
                'critical' => Db::table('system_error_events')->where('status', 'open')->where('severity', 'critical')->count(),
            ],
        ];
    }

    /** 返回单条脱敏详情；不存在和非法 ID 由上层统一为 404。 */
    public function find(string $id): array
    {
        /** @var stdClass|null $row */
        $row = Db::table('system_error_events')->where('id', $id)->first();
        if (!$row instanceof stdClass) {
            throw new SystemErrorNotFound('System error record not found.');
        }
        return $this->map($row, true);
    }

    /**
     * 解决或重新打开记录；相同状态是幂等读取，不增加版本或重复审计。
     *
     * @param array<string,mixed> $actor 已由 AuthorizationService 验证的系统管理员。
     */
    public function changeStatus(
        array $actor,
        string $id,
        string $status,
        int $expectedVersion,
        string $requestId,
    ): array {
        $actorId = $actor['id'] ?? null;
        if (!is_string($actorId) || $actorId === '') {
            throw new SystemErrorInvalid('Authenticated actor ID is missing.');
        }
        Db::transaction(function () use ($actorId, $expectedVersion, $id, $requestId, $status): void {
            /** @var stdClass|null $row */
            $row = Db::table('system_error_events')->where('id', $id)->first(['status', 'version']);
            if (!$row instanceof stdClass) throw new SystemErrorNotFound('System error record not found.');
            if ((int) $row->version !== $expectedVersion) {
                throw new SystemErrorConflict('System error record changed after it was loaded.');
            }
            if ((string) $row->status === $status) return;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('system_error_events')->where('id', $id)->where('version', $expectedVersion)->update([
                'status' => $status,
                'resolved_by' => $status === 'resolved' ? $actorId : null,
                'resolved_at' => $status === 'resolved' ? $now : null,
                'version' => $expectedVersion + 1,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) throw new SystemErrorConflict('System error record changed after it was loaded.');
            $this->audit->record(
                $actorId,
                $status === 'resolved' ? 'system_error.resolve' : 'system_error.reopen',
                'system_error_event',
                $id,
                'success',
                $requestId,
                ['status' => $status, 'previousVersion' => $expectedVersion],
            );
        });

        return $this->find($id);
    }

    /** 把持久行映射为固定后台投影；详情调用栈若损坏则失败，不返回未经验证的原始 JSON。 */
    private function map(stdClass $row, bool $includeStack): array
    {
        $fingerprint = (string) $row->fingerprint;
        if (preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1) {
            throw new JsonException('System error fingerprint is invalid.');
        }
        $exceptionClass = $this->safeExceptionClass($row->exception_class);
        $errorCode = $this->sanitizer->code($row->error_code);
        $requestId = $this->sanitizer->correlationId($row->request_id);
        $jobId = $this->sanitizer->correlationId($row->job_id);
        $routeMethod = is_string($row->route_method ?? null)
            && in_array($row->route_method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)
            ? (string) $row->route_method : null;
        $result = [
            'id' => (string) $row->id,
            'fingerprint' => $fingerprint,
            'severity' => (string) $row->severity,
            'source' => (string) $row->source,
            'exceptionClass' => $exceptionClass,
            'errorCode' => $errorCode,
            'message' => $this->sanitizer->message((string) $row->message_summary),
            'requestId' => $requestId,
            'jobId' => $jobId,
            'route' => $row->route_path === null ? null : [
                'method' => $routeMethod,
                'path' => $this->sanitizer->route((string) $row->route_path),
            ],
            'occurrenceCount' => (int) $row->occurrence_count,
            'status' => (string) $row->status,
            'resolvedAt' => $row->resolved_at === null ? null : (string) $row->resolved_at,
            'version' => (int) $row->version,
            'firstOccurredAt' => (string) $row->first_occurred_at,
            'lastOccurredAt' => (string) $row->last_occurred_at,
        ];
        if ($includeStack) {
            $stack = json_decode((string) $row->stack_json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($stack)) throw new JsonException('System error stack is invalid.');
            $safeStack = [];
            foreach ($stack as $frame) {
                if (!is_array($frame) || !is_string($frame['file'] ?? null)
                    || preg_match('#^(?:app|config)/(?!.*\.\.)[A-Za-z0-9_./-]{1,280}\.php$#', $frame['file']) !== 1
                    || !is_int($frame['line'] ?? null) || $frame['line'] < 1 || $frame['line'] > 10_000_000
                    || (($frame['function'] ?? null) !== null && (!is_string($frame['function'])
                        || strlen($frame['function']) > 160))) {
                    continue;
                }
                $safeStack[] = [
                    'file' => $frame['file'],
                    'line' => $frame['line'],
                    'function' => $frame['function'] ?? null,
                ];
                if (count($safeStack) >= 16) break;
            }
            $result['stack'] = $safeStack;
        }
        return $result;
    }

    /** 重新验证持久异常类的 PHP 命名空间形状，损坏值不会进入浏览器。 */
    private function safeExceptionClass(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || $value === '' || strlen($value) > 255) return null;
        foreach (explode('\\', ltrim($value, '\\')) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) return null;
        }
        return $value;
    }
}
