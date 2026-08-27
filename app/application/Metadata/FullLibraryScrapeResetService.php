<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;

/**
 * 在强制全量扫描完成后建立指定音乐库的安全刷新边界。
 *
 * 全量刷新必须保留当前 scraped 字段、Provider 歌词/封面和已发布缓存，直到对应歌曲获得新的可靠结果。
 * 这避免平台故障或无匹配把整库已经可用的元数据提前清空。服务只在确认没有活动逐曲或图片任务后，
 * 事务性删除旧的终态任务快照，再由调度器为扫描后的全部有效歌曲创建新任务。旧结果随后由现有写入
 * 服务按字段或资源幂等更新；失败、重试耗尽或 unmatched 都不会破坏旧业务事实，也不会触碰音频文件。
 */
final readonly class FullLibraryScrapeResetService
{
    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
    ) {}

    /**
     * 清除整库旧终态任务记录，并返回仍可用于新任务的歌曲数量。
     *
     * @param list<string> $songIds 扫描完成后按稳定顺序冻结的可用歌曲 ID
     * @throws MediaMetadataConflict 存在活动刮削/图片任务，不能建立互斥的重建边界
     */
    public function reset(
        string $libraryId,
        array $songIds,
        string $scanJobId,
        string $actorId,
        string $requestId,
    ): int {
        $songIds = array_values(array_unique($songIds));
        $this->assertIdle($libraryId);

        Db::transaction(function () use ($actorId, $libraryId, $requestId, $scanJobId, $songIds): void {
            [$targetCount, $jobCount] = $this->clearTaskRecords($libraryId);
            $this->audit->record(
                $actorId,
                'metadata.full_scrape.reset',
                'music_library',
                $libraryId,
                'success',
                $requestId,
                [
                    'scanJobId' => $scanJobId,
                    'songCount' => count($songIds),
                    'preservedExistingScrapeFacts' => true,
                    'deletedTargetCount' => $targetCount,
                    'deletedJobCount' => $jobCount,
                ],
            );
        });

        return count($songIds);
    }

    /** 活动 target 或图片任务可能仍在写库/发布文件；全量重建必须等待它们终结。 */
    private function assertIdle(string $libraryId): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        $targets = Db::table('metadata_sync_scrape_targets')->where('library_id', $libraryId)
            ->whereIn('status', ['pending', 'running']);
        if ($schema->hasColumn('metadata_sync_scrape_targets', 'awaiting_confirmation')) {
            $targets->orWhere(static function ($query) use ($libraryId): void {
                $query->where('library_id', $libraryId)->where('awaiting_confirmation', 1);
            });
        }
        if ($targets->exists()) {
            throw new MediaMetadataConflict('音乐库仍有活动刮削任务，不能执行全量刮削重建。');
        }
        foreach (['artwork_provider_search_jobs', 'artwork_provider_import_jobs'] as $table) {
            if ($schema->hasTable($table)
                && Db::table($table)->where('library_id', $libraryId)
                    ->whereIn('status', ['queued', 'running'])->exists()) {
                throw new MediaMetadataConflict('音乐库仍有活动封面任务，不能执行全量刮削重建。');
            }
        }
    }

    /**
     * 删除该库终态逐曲记录并重算可能跨库的父任务；审计日志是不可变事实，永不随重建删除。
     *
     * @return array{int,int} 删除的 target 数与空父任务数
     */
    private function clearTaskRecords(string $libraryId): array
    {
        $schema = Db::connection()->getSchemaBuilder();
        /** @var list<stdClass> $rows */
        $rows = Db::table('metadata_sync_scrape_targets')->where('library_id', $libraryId)
            ->whereIn('status', ['succeeded', 'unmatched', 'failed'])->get(['id', 'job_id'])->all();
        $targetIds = array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
        $jobIds = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->job_id, $rows)));
        if ($targetIds !== []) {
            foreach (['artwork_provider_search_jobs', 'artwork_provider_import_jobs'] as $table) {
                if ($schema->hasTable($table) && $schema->hasColumn($table, 'scrape_target_id')) {
                    Db::table($table)->whereIn('scrape_target_id', $targetIds)->update(['scrape_target_id' => null]);
                }
            }
            Db::table('metadata_sync_scrape_channel_results')->whereIn('target_id', $targetIds)->delete();
            Db::table('scrape_asset_publications')->whereIn('scrape_target_id', $targetIds)->delete();
            Db::table('metadata_sync_scrape_targets')->whereIn('id', $targetIds)->delete();
        }
        $deletedJobs = 0;
        foreach ($jobIds as $jobId) {
            /** @var list<string> $statuses */
            $statuses = Db::table('metadata_sync_scrape_targets')->where('job_id', $jobId)
                ->pluck('status')->map('strval')->all();
            if ($statuses === []) {
                $deletedJobs += Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->delete();
                continue;
            }
            $counts = array_count_values($statuses);
            $processed = ($counts['succeeded'] ?? 0) + ($counts['unmatched'] ?? 0) + ($counts['failed'] ?? 0);
            $targetCount = count($statuses);
            $terminal = $processed === $targetCount;
            $status = !$terminal ? 'running' : (($counts['succeeded'] ?? 0) === $targetCount
                ? 'succeeded' : (($counts['failed'] ?? 0) === $targetCount ? 'failed' : 'partial'));
            Db::table('metadata_sync_scrape_jobs')->where('id', $jobId)->update([
                'status' => $status, 'target_count' => $targetCount, 'processed_count' => $processed,
                'succeeded_count' => $counts['succeeded'] ?? 0, 'unmatched_count' => $counts['unmatched'] ?? 0,
                'failed_count' => $counts['failed'] ?? 0, 'version' => Db::raw('version + 1'),
                'finished_at' => $terminal ? gmdate('Y-m-d\TH:i:s\Z') : null,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        return [count($targetIds), $deletedJobs];
    }

}
