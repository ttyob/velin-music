<?php

declare(strict_types=1);

namespace app\infrastructure\Observability;

use Throwable;

/**
 * 把异常和 error 日志压缩为可进入后台的低敏诊断字段。
 *
 * 本类不判断权限也不写数据库。摘要会移除 URL、绝对路径、邮箱、长标识和常见凭据赋值；SQL 异常只
 * 保留 SQLSTATE 机器码，避免绑定值或查询正文进入后台。调用栈仅允许 `backend/app` 与
 * `backend/config` 下的相对文件和行号，不保存参数。规则宁可损失细节也不能把秘密交给浏览器。
 */
final class SystemErrorSanitizer
{
    private const SECRET_ASSIGNMENT = '/\b(password|passwd|secret|token|authorization|cookie|credential|api[_-]?key)\b\s*[:=]\s*([^\s,;]+)/iu';

    /** 返回最多 1,000 字节的单行摘要；空消息回退为稳定类别，便于聚合和展示。 */
    public function message(string $message): string
    {
        if (preg_match('/SQLSTATE\[([A-Z0-9]+)\]/i', $message, $match) === 1) {
            return '数据库操作失败（SQLSTATE ' . strtoupper($match[1]) . '）';
        }
        $safe = preg_replace(self::SECRET_ASSIGNMENT, '$1=[redacted]', $message) ?? '';
        $safe = preg_replace('#https?://[^\s<>"\']+#iu', '[url]', $safe) ?? '';
        $safe = preg_replace('/[A-Z]:\\\\[^\r\n\t]+/iu', '[path]', $safe) ?? '';
        $safe = preg_replace('#(?<![A-Za-z0-9])/(?:[^\s/]+/)+[^\s,;:]*#u', '[path]', $safe) ?? '';
        $safe = preg_replace('/\b[A-Z0-9]{26}\b/i', '[id]', $safe) ?? '';
        $safe = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '[id]', $safe) ?? '';
        $safe = preg_replace('/\b[0-9a-f]{32,}\b/i', '[digest]', $safe) ?? '';
        $safe = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u', '[email]', $safe) ?? '';
        $safe = preg_replace('/\s+/u', ' ', trim($safe)) ?? '';
        if ($safe === '') {
            $safe = '后端处理发生未分类异常。';
        }

        return $this->bounded($safe, 1_000);
    }

    /** 返回只包含应用相对文件、行号和函数名的有界调用栈，绝不序列化参数或对象。 */
    public function stack(Throwable $throwable): array
    {
        $frames = [[
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'function' => null,
        ]];
        foreach ($throwable->getTrace() as $frame) {
            $frames[] = [
                'file' => is_string($frame['file'] ?? null) ? $frame['file'] : '',
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : 0,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
            ];
        }

        $safe = [];
        foreach ($frames as $frame) {
            $relative = $this->applicationPath((string) $frame['file']);
            if ($relative === null) {
                continue;
            }
            $safe[] = [
                'file' => $relative,
                'line' => max(1, (int) $frame['line']),
                'function' => $frame['function'] === null
                    ? null : $this->bounded((string) $frame['function'], 160),
            ];
            if (count($safe) >= 16) {
                break;
            }
        }

        return $safe;
    }

    /** 把 HTTP 路径降为无查询参数的路由形状，并替换常见不透明对象标识。 */
    public function route(string $path): string
    {
        $path = '/' . ltrim(explode('?', $path, 2)[0], '/');
        $segments = array_map(static function (string $segment): string {
            if ($segment === '{id}') {
                return $segment;
            }
            if (preg_match('/^(?:[A-Z0-9]{26}|[0-9a-f-]{36}|\d{4,})$/i', $segment) === 1) {
                return '{id}';
            }
            return rawurlencode(rawurldecode(substr($segment, 0, 100)));
        }, explode('/', $path));

        return $this->bounded(implode('/', $segments), 300);
    }

    /** 只允许稳定机器码进入筛选和聚合；任意外部正文都返回 null。 */
    public function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_:-]{1,79}$/', $value) === 1 ? $value : null;
    }

    /** 只接受项目约定的关联 ID 字符集，拒绝把请求头或任意正文借字段名写入。 */
    public function correlationId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9:_-]{8,128}$/', $value) === 1 ? $value : null;
    }

    private function applicationPath(string $path): ?string
    {
        $root = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $root)) {
            return null;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        if (!str_starts_with($relative, 'app/') && !str_starts_with($relative, 'config/')) {
            return null;
        }

        return $this->bounded($relative, 300);
    }

    private function bounded(string $value, int $bytes): string
    {
        return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes) . '[truncated]';
    }
}
