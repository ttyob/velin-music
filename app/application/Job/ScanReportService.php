<?php

declare(strict_types=1);

namespace app\application\Job;

use app\application\Scan\ScanJobService;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * 为已经结束的音乐库扫描生成一次性可下载的完整 JSON 报告。
 *
 * 报告只复用 ScanJobService 已完成权限裁剪的任务和历史识别快照，不读取 inventory 路径、原始标签、
 * 歌词正文或命令输出。逐页读取和逐段写入避免大曲库报告一次性进入 PHP 内存；字节和行数上限用于
 * 阻止单个 HTTP 请求长期占用进程，超限时明确失败，绝不返回被截断却标称完整的文件。
 */
final class ScanReportService
{
    private const PAGE_SIZE = 100;
    private const ARTIFACT_TTL_SECONDS = 3600;

    private readonly ScanJobService $scans;
    private readonly string $reportRoot;

    public function __construct(
        ?ScanJobService $scans = null,
        ?string $reportRoot = null,
        private readonly int $maximumRows = 1_000_000,
        private readonly int $maximumBytes = 536_870_912,
    ) {
        $this->scans = $scans ?? new ScanJobService();
        $this->reportRoot = $reportRoot ?? base_path('runtime/job-reports');
    }

    /**
     * 重新授权并生成完整报告，返回仅供 Controller 发送文件使用的服务端产物描述。
     *
     * @param array<string,mixed> $actor 当前 Web Session 身份快照。
     * @return array{path:string,downloadName:string,sha256:string,byteSize:int,recordCount:int}
     */
    public function create(array $actor, string $jobId): array
    {
        $job = $this->scans->findJob($jobId, $actor);
        if (!in_array($job['status'], ['succeeded', 'failed', 'cancelled'], true)) {
            throw new JobReportUnavailable('扫描任务结束后才能生成完整报告。');
        }

        $firstPage = $this->scans->listFileResults($jobId, $actor, self::PAGE_SIZE, 0);
        $total = (int) $firstPage['total'];
        if ($total > $this->maximumRows) {
            throw new JobReportTooLarge('扫描报告记录数超过安全上限。');
        }

        $root = $this->preparePrivateRoot();
        $this->cleanupExpiredArtifacts($root);
        $target = $root . DIRECTORY_SEPARATOR . $jobId . '.json';
        $temporary = $root . DIRECTORY_SEPARATOR . '.' . $jobId . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('无法创建扫描报告临时文件。');
        }

        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('无法限制扫描报告文件权限。');
            }
            $summary = $this->safeSummary($job);
            $this->write($stream, $hash, $bytes, '{"schemaVersion":1,"generatedAt":'
                . $this->json(gmdate('c')) . ',"job":' . $this->json($summary)
                . ',"fileResultCount":' . $total . ',"files":[');

            $offset = 0;
            $writtenRows = 0;
            while ($offset < $total) {
                $page = $offset === 0
                    ? $firstPage
                    : $this->scans->listFileResults($jobId, $actor, self::PAGE_SIZE, $offset);
                foreach ($page['files'] as $file) {
                    $this->write($stream, $hash, $bytes,
                        ($writtenRows === 0 ? '' : ',') . $this->json($file));
                    $writtenRows++;
                }
                if ($page['files'] === []) {
                    throw new RuntimeException('扫描报告分页结果在生成期间发生变化。');
                }
                $offset += count($page['files']);
            }
            $this->write($stream, $hash, $bytes, ']}');
            if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) {
                throw new RuntimeException('扫描报告无法同步到存储。');
            }
            if (!fclose($stream)) {
                throw new RuntimeException('扫描报告无法关闭。');
            }
            $stream = null;
            if (!rename($temporary, $target) || !chmod($target, 0600)) {
                throw new RuntimeException('扫描报告无法原子发布。');
            }

            return [
                'path' => $target,
                'downloadName' => 'velin-music-scan-' . $jobId . '.json',
                'sha256' => hash_final($hash),
                'byteSize' => $bytes,
                'recordCount' => $writtenRows,
            ];
        } catch (Throwable $throwable) {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temporary)) @unlink($temporary);
            throw $throwable;
        }
    }

    /** 只挑选已脱敏的任务字段，防止未来来源投影新增内部字段后被整包导出。 */
    private function safeSummary(array $job): array
    {
        return [
            'id' => (string) $job['id'],
            'library' => ['id' => (string) $job['library']['id'], 'name' => (string) $job['library']['name']],
            'scanType' => (string) $job['scanType'],
            'status' => (string) $job['status'],
            'phase' => (string) $job['phase'],
            'processedEntries' => (int) $job['processedEntries'],
            'discoveredFiles' => (int) $job['discoveredFiles'],
            'addedFiles' => (int) $job['addedFiles'],
            'missingFiles' => (int) $job['missingFiles'],
            'ignoredEntries' => (int) $job['ignoredEntries'],
            'failedEntries' => (int) $job['failedEntries'],
            'metadataParsedFiles' => (int) $job['metadataParsedFiles'],
            'metadataFailedFiles' => (int) $job['metadataFailedFiles'],
            'updatedFiles' => (int) $job['updatedFiles'],
            'attempt' => (int) $job['attempt'],
            'requestedBy' => $job['requestedBy'],
            'error' => $job['error'],
            'createdAt' => (string) $job['createdAt'],
            'startedAt' => $job['startedAt'],
            'finishedAt' => $job['finishedAt'],
        ];
    }

    /** 建立 0700 私有目录，并拒绝使用符号链接作为报告根目录。 */
    private function preparePrivateRoot(): string
    {
        if (is_link($this->reportRoot)) {
            throw new RuntimeException('扫描报告目录不能是符号链接。');
        }
        if (!is_dir($this->reportRoot) && !mkdir($this->reportRoot, 0700, true) && !is_dir($this->reportRoot)) {
            throw new RuntimeException('无法创建扫描报告目录。');
        }
        if (!chmod($this->reportRoot, 0700)) {
            throw new RuntimeException('无法限制扫描报告目录权限。');
        }
        $resolved = realpath($this->reportRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('无法解析扫描报告目录。');
        }
        return $resolved;
    }

    /**
     * 每次生成前有界清理一小时前的本服务固定命名产物和中断临时文件。
     *
     * 一小时窗口覆盖慢速下载；最多检查 200 个目录项，避免清理工作反过来阻塞下载请求。无法安全
     * 识别的目录项或符号链接一律保留并交由运维处理。
     */
    private function cleanupExpiredArtifacts(string $root): void
    {
        $entries = scandir($root);
        if (!is_array($entries)) return;
        $checked = 0;
        $deadline = time() - self::ARTIFACT_TTL_SECONDS;
        foreach ($entries as $entry) {
            if ($checked >= 200) break;
            if (preg_match('/^(?:[0-9A-HJKMNP-TV-Z]{26}\.json|\.[0-9A-HJKMNP-TV-Z]{26}\.[a-f0-9]{16}\.tmp)$/', $entry) !== 1) continue;
            $checked++;
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            $stat = lstat($path);
            if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path)) continue;
            if ((int) ($stat['mtime'] ?? PHP_INT_MAX) < $deadline) @unlink($path);
        }
    }

    /** JSON 编码失败属于内部数据完整性故障，不能跳过坏行继续生成伪完整报告。 */
    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('扫描报告包含无法编码的数据。', 0, $exception);
        }
    }

    /** 完整写入一个片段，同时计算最终摘要并在越界前终止。 */
    private function write(mixed $stream, mixed $hash, int &$bytes, string $chunk): void
    {
        $length = strlen($chunk);
        if ($bytes + $length > $this->maximumBytes) {
            throw new JobReportTooLarge('扫描报告字节数超过安全上限。');
        }
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('扫描报告写入失败。');
            }
            $offset += $written;
        }
        hash_update($hash, $chunk);
        $bytes += $length;
    }
}
