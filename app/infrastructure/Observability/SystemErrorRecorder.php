<?php

declare(strict_types=1);

namespace app\infrastructure\Observability;

use Error;
use JsonException;
use Symfony\Component\Uid\Ulid;
use Throwable;
use Webman\Http\Request;
use support\Db;

/**
 * 将未处理异常与 error 级日志写成按根因聚合的后台诊断事实。
 *
 * 捕获器运行在失败路径，不能再次抛错或写日志。SQLite 表缺失、数据库锁定、JSON 编码失败等情况均返回
 * false，让原文件日志继续作为降级事实。相同指纹使用原子 upsert 累计，已解决根因再次出现会重新打开；
 * 指纹不包含请求 ID、对象 ID或消息中的动态值，避免一次故障制造无界记录。
 */
final readonly class SystemErrorRecorder
{
    public function __construct(private SystemErrorSanitizer $sanitizer = new SystemErrorSanitizer())
    {
    }

    /** 捕获一个未处理 Throwable；请求只读取方法和无查询路径，不读取头、IP、Session 或正文。 */
    public function recordThrowable(Throwable $throwable, ?Request $request, ?string $requestId): bool
    {
        $stack = $this->sanitizer->stack($throwable);
        $source = $request === null ? 'worker' : 'http';
        $routeMethod = $request === null ? null : strtoupper($request->method());
        $routePath = $request === null ? null : $this->sanitizer->route($request->path());
        $origin = $stack[0] ?? null;

        return $this->persist([
            'severity' => $throwable instanceof Error ? 'critical' : 'error',
            'source' => $source,
            'exceptionClass' => $this->exceptionClass($throwable::class),
            'errorCode' => $this->sanitizer->code(is_string($throwable->getCode()) ? $throwable->getCode() : null),
            'message' => $this->sanitizer->message($throwable->getMessage()),
            'requestId' => $this->sanitizer->correlationId($requestId),
            'jobId' => null,
            'routeMethod' => $routeMethod,
            'routePath' => $routePath,
            'stack' => $stack,
            'fingerprintParts' => [
                'throwable', $throwable::class, $origin['file'] ?? 'outside-app', $origin['line'] ?? 0,
            ],
        ]);
    }

    /** 捕获 Monolog error/critical 记录；只读取调用点已约定的稳定 context 键。 */
    public function recordLog(array $record, ?Request $request = null): bool
    {
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];
        if (($context['system_error_recorded'] ?? false) === true) {
            return true;
        }
        $message = $this->sanitizer->message(is_string($record['message'] ?? null)
            ? $record['message'] : '后端记录了未分类错误。');
        $exceptionClass = $this->exceptionClass($context['exception_class'] ?? null);
        $errorCode = $this->sanitizer->code($context['error_code'] ?? $context['reason_code'] ?? null);
        $routeMethod = $request === null ? null : strtoupper($request->method());
        $routePath = $request === null ? null : $this->sanitizer->route($request->path());

        return $this->persist([
            'severity' => (int) ($record['level'] ?? 400) >= 500 ? 'critical' : 'error',
            'source' => $request === null ? 'worker' : 'http',
            'exceptionClass' => $exceptionClass,
            'errorCode' => $errorCode,
            'message' => $message,
            'requestId' => $this->sanitizer->correlationId($context['request_id'] ?? null),
            'jobId' => $this->sanitizer->correlationId($context['job_id'] ?? null),
            'routeMethod' => $routeMethod,
            'routePath' => $routePath,
            'stack' => [],
            'fingerprintParts' => ['log', $record['channel'] ?? 'default', $message, $exceptionClass, $errorCode],
        ]);
    }

    /**
     * 执行单条 SQLite 原子 upsert；任何失败都被限制在诊断支路，不能覆盖原异常或业务事务结果。
     *
     * @param array{severity:string,source:string,exceptionClass:?string,errorCode:?string,message:string,
     * requestId:?string,jobId:?string,routeMethod:?string,routePath:?string,stack:array,fingerprintParts:array} $event
     */
    private function persist(array $event): bool
    {
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $fingerprint = hash('sha256', json_encode(
                $event['fingerprintParts'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $stack = json_encode($event['stack'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            Db::statement(<<<'SQL'
INSERT INTO system_error_events (
    id, fingerprint, severity, source, exception_class, error_code, message_summary,
    request_id, job_id, route_method, route_path, stack_json, occurrence_count, status,
    resolved_by, resolved_at, version, first_occurred_at, last_occurred_at, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'open', NULL, NULL, 1, ?, ?, ?, ?)
ON CONFLICT(fingerprint) DO UPDATE SET
    severity = CASE WHEN excluded.severity = 'critical' THEN 'critical' ELSE system_error_events.severity END,
    source = excluded.source,
    exception_class = COALESCE(excluded.exception_class, system_error_events.exception_class),
    error_code = COALESCE(excluded.error_code, system_error_events.error_code),
    message_summary = excluded.message_summary,
    request_id = COALESCE(excluded.request_id, system_error_events.request_id),
    job_id = COALESCE(excluded.job_id, system_error_events.job_id),
    route_method = COALESCE(excluded.route_method, system_error_events.route_method),
    route_path = COALESCE(excluded.route_path, system_error_events.route_path),
    stack_json = CASE WHEN excluded.stack_json <> '[]' THEN excluded.stack_json ELSE system_error_events.stack_json END,
    occurrence_count = system_error_events.occurrence_count + 1,
    status = 'open', resolved_by = NULL, resolved_at = NULL,
    version = system_error_events.version + 1,
    last_occurred_at = excluded.last_occurred_at,
    updated_at = excluded.updated_at
SQL, [
                (string) new Ulid(), $fingerprint, $event['severity'], $event['source'],
                $event['exceptionClass'], $event['errorCode'], $event['message'], $event['requestId'],
                $event['jobId'], $event['routeMethod'], $event['routePath'], $stack,
                $now, $now, $now, $now,
            ]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function exceptionClass(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 255) {
            return null;
        }
        foreach (explode('\\', ltrim($value, '\\')) as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                return null;
            }
        }

        return $value;
    }
}
