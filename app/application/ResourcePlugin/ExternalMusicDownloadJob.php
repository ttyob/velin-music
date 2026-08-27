<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

/**
 * 表示统一下载创建接口返回的最小任务投影。
 *
 * 核心只公开任务身份、展示元数据、目标库、进度、稳定错误码和时间。下载引用、临时 URL、远端 hash、
 * Worker、requestId、staging 与发布路径永不进入本对象。插件任务可以拥有更多内部状态，但必须通过各自
 * 管理页面或未来版本化诊断合同访问，不能借统一创建响应泄漏。
 */
final readonly class ExternalMusicDownloadJob
{
    /** @param array<string,mixed> $projection 已固定白名单的 JSON 投影 */
    private function __construct(private array $projection)
    {
    }

    /**
     * 从插件创建结果建立统一任务投影。
     *
     * 任务和目标库必须使用 ULID，状态及错误码只允许稳定机器标识，进度范围为 0..100。协议损坏被视为
     * 插件不可用，不回退返回原始数组；任务可能已由插件耐久创建，因此调用方应使用同一 leaseId 重试，
     * 由插件幂等唯一约束返回原任务，不能改用新租约制造重复副作用。
     *
     * @param array<string,mixed> $job 插件返回的未信任任务投影
     * @throws ExternalMusicUnavailable 插件响应不符合统一协议
     */
    public static function fromPluginJob(string $pluginKey, string $pluginName, array $job): self
    {
        $id = self::ulid($job['id'] ?? null);
        $library = $job['library'] ?? null;
        if (!is_array($library) || array_is_list($library)) throw new ExternalMusicUnavailable();
        $libraryId = self::ulid($library['id'] ?? null);
        $libraryName = self::text($library['name'] ?? null, 120, false);
        $status = self::identifier($job['status'] ?? null, 40, false);
        $progress = $job['progress'] ?? null;
        if (!is_int($progress) && !is_float($progress)) throw new ExternalMusicUnavailable();
        $progress = (float) $progress;
        if (!is_finite($progress) || $progress < 0 || $progress > 100) throw new ExternalMusicUnavailable();

        $projection = [
            'pluginKey' => $pluginKey,
            'pluginName' => $pluginName,
            'id' => $id,
            'title' => self::text($job['title'] ?? null, 500, false),
            'artist' => self::text($job['artist'] ?? null, 300, true),
            'album' => self::text($job['album'] ?? null, 300, true),
            'source' => self::text($job['source'] ?? $job['indexer'] ?? $pluginName, 160, false),
            'quality' => self::text($job['quality'] ?? null, 40, true),
            'format' => self::text($job['format'] ?? null, 24, true),
            'library' => ['id' => $libraryId, 'name' => $libraryName],
            'status' => $status,
            'progress' => $progress,
            'downloadedBytes' => self::integer($job['downloadedBytes'] ?? 0),
            'totalBytes' => self::integer($job['totalBytes'] ?? 0),
            'importedFiles' => self::integer($job['importedFiles'] ?? 0),
            'scanJobId' => ($job['scanJobId'] ?? null) === null ? null : self::ulid($job['scanJobId']),
            'errorCode' => self::identifier($job['errorCode'] ?? null, 96, true),
            'createdAt' => self::timestamp($job['createdAt'] ?? null, false),
            'updatedAt' => self::timestamp($job['updatedAt'] ?? null, false),
            'finishedAt' => self::timestamp($job['finishedAt'] ?? null, true),
        ];
        return new self($projection);
    }

    /**
     * 返回固定字段的 JSON 安全任务投影。
     *
     * projection 已在工厂方法中完成白名单重建，本方法不附加插件原始数据，也不进行数据库读取或产生
     * 副作用。调用方不得把它与插件私有任务数组合并，否则会重新引入路径或下载引用泄漏。
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->projection;
    }

    /** 验证不透明 ULID；格式证明不等于对象存在或调用者拥有权限。 */
    private static function ulid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) !== 1) {
            throw new ExternalMusicUnavailable();
        }
        return $value;
    }

    /** 读取固定长度展示文本，并拒绝控制字符和无效 UTF-8。 */
    private static function text(mixed $value, int $maximum, bool $nullable): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new ExternalMusicUnavailable();
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) throw new ExternalMusicUnavailable();
        return $value;
    }

    /** 验证状态和稳定错误码；nullable 只用于尚未失败的任务。 */
    private static function identifier(mixed $value, int $maximum, bool $nullable): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value) || strlen($value) > $maximum
            || preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,95}$/D', $value) !== 1) {
            throw new ExternalMusicUnavailable();
        }
        return $value;
    }

    /** 验证非负字节、文件计数等整数事实。 */
    private static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0) throw new ExternalMusicUnavailable();
        return $value;
    }

    /** 验证核心统一采用的 UTC 秒精度时间；null 只允许未进入终态的 finishedAt。 */
    private static function timestamp(mixed $value, bool $nullable): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new ExternalMusicUnavailable();
        }
        return $value;
    }
}
