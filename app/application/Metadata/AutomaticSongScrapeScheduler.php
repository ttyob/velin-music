<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Auth\CapabilityResolver;
use app\application\Library\LibraryAccessResolver;
use stdClass;
use support\Db;

/**
 * 把成功扫描中实际新增或更新的歌曲统一提交到逐曲刮削状态机。
 *
 * 扫描 Worker 只在扫描成功终态提交后调用本服务，因此后台手工扫描、计划扫描、监听扫描、上传后扫描
 * 和下载入库扫描都必须进入同一条逐曲流程，不能根据发起者或 HTTP requestId 静默跳过。歌曲集合来自
 * 扫描报告中 `indexed + metadata_parsed` 的路径无关歌曲 ID，按报告顺序稳定去重后每 50 首拆批；每个
 * 批次仍由 MetadataSyncScrapeService 冻结证据、复验授权并执行元数据、歌词和图片流程。扫描任务 ID
 * 与批次位置组成确定 requestId，Worker 在扫描终态提交后崩溃并重放调度时不会创建重复刮削任务。
 *
 * 自动任务必须借用一个真实、活动且仍具备 `edit_metadata + run_scrape` 的超级管理员作为审计主体；
 * 没有合格主体时保持无副作用。方法不访问网络或媒体文件，任一批次创建失败由扫描 Worker 记录脱敏
 * 日志，已经成功的扫描事实和先前批次不回滚。
 */
final readonly class AutomaticSongScrapeScheduler
{
    public function __construct(
        private MetadataSyncScrapeService $scrapes = new MetadataSyncScrapeService(),
        private CapabilityResolver $capabilities = new CapabilityResolver(),
        private LibraryAccessResolver $libraries = new LibraryAccessResolver(),
        private FullLibraryScrapeResetService $fullReset = new FullLibraryScrapeResetService(),
    ) {}

    /**
     * 为一个已成功的扫描创建零个或多个自动逐曲刮削批次。
     *
     * @param array<string,mixed> $scan ScanWorker 已领取的不可变任务投影。
     * @return int 本次实际新增的逐曲目标数；幂等重放和无匹配歌曲返回零。
     */
    public function dispatch(array $scan): int
    {
        $jobId = $scan['id'] ?? null;
        if (!is_string($jobId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $jobId) !== 1) {
            return 0;
        }
        $actor = $this->automationActor();
        if ($actor === null) return 0;

        $full = ($scan['scanType'] ?? null) === 'full';
        $libraryId = $scan['libraryId'] ?? null;
        $requestId = $scan['requestId'] ?? null;
        $downloadImport = is_string($requestId) && str_starts_with($requestId, 'scan-automation:download_import:');
        /** @var list<string> $songIds */
        $songs = Db::table('library_scan_file_results as results')
            ->join('library_file_inventory as inventory', 'inventory.id', '=', 'results.inventory_file_id')
            ->where('results.scan_job_id', $jobId)->whereNotNull('results.song_id');
        if ($full) {
            $songs->whereIn('results.result_kind', ['indexed', 'unchanged']);
        } elseif ($downloadImport) {
            // 下载导入的新文件可能在扫描快照已由目录阶段完成时报告为 unchanged；首次发现标记才是
            // 本次下载的稳定身份边界，不能再要求 metadata_parsed=1。
            $songs->whereIn('results.result_kind', ['indexed', 'unchanged']);
        } else {
            $songs->where('results.result_kind', 'indexed')->where('results.metadata_parsed', 1);
        }
        // 下载入库扫描会同时重建一批已有文件的标签快照；这些文件不是本次下载目标，不能因解析器版本
        // 或快照变化再次触发歌曲插件。watch、scheduled 和手工扫描仍按完整扫描报告调度新增或变更歌曲。
        if ($downloadImport) {
            $songs->where('inventory.first_seen_scan_job_id', $jobId);
        }
        $songIds = $songs
            ->orderBy('results.file_name')->orderBy('results.inventory_file_id')
            ->pluck('results.song_id')->map('strval')->unique()->values()->all();
        $chunks = array_chunk($songIds, 50);
        $existingPositions = [];
        foreach (array_keys($chunks) as $position) {
            $request = 'automatic-song-scrape:' . $jobId . ':' . $position;
            if (Db::table('metadata_sync_scrape_jobs')->where('request_id', $request)->exists()) {
                $existingPositions[$position] = true;
            }
        }
        if ($chunks !== [] && count($existingPositions) === count($chunks)) return 0;
        // 全量重建只在第一个分段尚未提交时执行。若进程在前几个分段后崩溃，保留活动目标并只补齐缺失分段，
        // 否则 assertIdle 会把可恢复的半批次误判为冲突。
        if ($full && $existingPositions === []) {
            if (!is_string($libraryId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) return 0;
            $this->fullReset->reset(
                $libraryId,
                $songIds,
                $jobId,
                (string) $actor['id'],
                is_string($scan['requestId'] ?? null) ? (string) $scan['requestId'] : $jobId,
            );
        }
        $created = 0;
        // 每个分段都有确定 requestId；逐段检查而不是用“已有任一分段就整体返回”，
        // 这样扫描 Worker 在提交前几个分段后崩溃时，重放仍能补齐剩余歌曲。
        foreach ($chunks as $position => $chunk) {
            $batchRequestId = 'automatic-song-scrape:' . $jobId . ':' . $position;
            if (isset($existingPositions[$position])) continue;
            $this->scrapes->createAutomaticBatch($actor, $chunk, $batchRequestId, $full);
            $created += count($chunk);
        }
        return $created;
    }

    /** @return array<string,mixed>|null 返回可被逐曲 Worker 再次实时复验的真实超级管理员快照。 */
    private function automationActor(): ?array
    {
        /** @var stdClass|null $user */
        $user = Db::table('users')->where('status', 'active')->where('is_super_admin', 1)
            ->whereNull('deleted_at')->orderBy('id')->first(['id']);
        if (!$user instanceof stdClass) return null;
        $userId = (string) $user->id;
        $capabilities = $this->capabilities->resolve($userId, true);
        if (!in_array('edit_metadata', $capabilities, true) || !in_array('run_scrape', $capabilities, true)) return null;
        return [
            'id' => $userId,
            'isSuperAdmin' => true,
            'capabilities' => $capabilities,
            'libraries' => $this->libraries->resolve($userId, true),
        ];
    }
}
