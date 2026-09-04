<?php

declare(strict_types=1);

namespace app\application\Scan;

use app\infrastructure\Database\SqliteWriteGate;
use stdClass;
use support\Db;

/**
 * 有界清理老扫描任务的逐文件诊断，同时永久保留任务摘要、终态与审计。
 *
 * 每个音乐库最近十个 succeeded/failed/cancelled 任务保留完整明细；queued、running 与
 * cancel_requested 永不进入候选。一次调用最多清理一个任务，并在同一个短事务中删除明细和设置
 * `file_results_pruned_at`，因此进程中断只会留下“全部明细仍在”或“明细已删且状态已标记”两种状态。
 * 重试会跳过已标记任务，不访问媒体路径，也不改变歌曲、库存、扫描计数或任务审计。
 */
final class ScanHistoryRetentionService
{
    public function __construct(private readonly SqliteWriteGate $writeGate = new SqliteWriteGate())
    {
    }

    /**
     * 清理一个超过保留窗口的终态任务。
     *
     * @return array{jobs:int,fileResults:int}
     */
    public function pruneOne(int $retainPerLibrary = 10): array
    {
        if ($retainPerLibrary < 1 || $retainPerLibrary > 100) {
            throw new \InvalidArgumentException('Scan retention window is outside the supported range.');
        }
        if (!Db::connection()->getSchemaBuilder()->hasColumn('library_scan_jobs', 'file_results_pruned_at')) {
            return ['jobs' => 0, 'fileResults' => 0];
        }

        return $this->writeGate->run(fn (): array => Db::transaction(function () use ($retainPerLibrary): array {
            /** @var stdClass|null $job */
            $job = Db::selectOne(<<<'SQL'
SELECT jobs.id
FROM library_scan_jobs AS jobs
WHERE jobs.status IN ('succeeded', 'failed', 'cancelled')
  AND jobs.file_results_pruned_at IS NULL
  AND (
      SELECT COUNT(*)
      FROM library_scan_jobs AS newer
      WHERE newer.library_id = jobs.library_id
        AND newer.status IN ('succeeded', 'failed', 'cancelled')
        AND (
            COALESCE(newer.finished_at, newer.updated_at) > COALESCE(jobs.finished_at, jobs.updated_at)
            OR (
                COALESCE(newer.finished_at, newer.updated_at) = COALESCE(jobs.finished_at, jobs.updated_at)
                AND newer.id > jobs.id
            )
        )
  ) >= ?
ORDER BY COALESCE(jobs.finished_at, jobs.updated_at), jobs.id
LIMIT 1
SQL, [$retainPerLibrary]);
            if (!$job instanceof stdClass) {
                return ['jobs' => 0, 'fileResults' => 0];
            }
            $deleted = Db::table('library_scan_file_results')->where('scan_job_id', (string) $job->id)->delete();
            $changed = Db::table('library_scan_jobs')->where('id', (string) $job->id)
                ->whereIn('status', ['succeeded', 'failed', 'cancelled'])
                ->whereNull('file_results_pruned_at')
                ->update(['file_results_pruned_at' => gmdate('Y-m-d\TH:i:s\Z')]);
            return ['jobs' => $changed, 'fileResults' => $deleted];
        }));
    }
}
