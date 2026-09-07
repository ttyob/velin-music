<?php

declare(strict_types=1);

namespace app\application\User;

use app\application\System\SystemLimitSettingsService;
use support\Db;

/**
 * 从真实异步任务头聚合全站高成本任务用量，并在任务创建事务内执行准入。
 *
 * 当前定义包含音乐库扫描、逐曲刮削、歌词/音频标签写回。自动
 * Worker 派生且 requested_by 为 NULL 的任务也占用系统资源，因此纳入全局额度；终态任务自然退出
 * 计数，不需要易漂移的旁路计数器。滚动升级期间新表可能尚未创建，因此不存在的模块按零用量处理。
 */
final readonly class HighCostJobPolicy
{
    public function __construct(private SystemLimitSettingsService $limits = new SystemLimitSettingsService())
    {
    }

    /** 返回全站所有真实 queued/running/cancel_requested 高成本任务数；参数仅保留滚动升级调用兼容。 */
    public function usage(?string $unusedUserId = null): int
    {
        $sources = [
            'library_scan_jobs' => ['queued', 'running', 'cancel_requested'],
            'metadata_sync_scrape_jobs' => ['queued', 'running'],
            'lyrics_writeback_jobs' => ['queued', 'running'],
            'audio_tag_writeback_jobs' => ['queued', 'running'],
            'lyrics_audio_tag_writeback_jobs' => ['queued', 'running'],
        ];
        $schema = Db::connection()->getSchemaBuilder();
        $total = 0;
        foreach ($sources as $table => $statuses) {
            // 独立模块的最小单元测试或滚动升级阶段可能尚无对应任务表；不存在的模块不产生用量。
            if (!$schema->hasTable($table)) continue;
            $total += (int) Db::table($table)->whereIn('status', $statuses)->count();
        }
        return $total;
    }

    /**
     * 检查本事务将新增的任务数量；调用者必须在自己的 SQLite 写事务内调用以关闭并发竞争窗口。
     */
    public function assertCanQueue(string $userId, int $additional = 1): void
    {
        if ($additional < 1 || $additional > 100) throw new \InvalidArgumentException('HIGH_COST_JOB_ADDITION_INVALID');
        $maximum = (int) $this->limits->get()['maxHighCostJobs'];
        $current = $this->usage();
        if ($current + $additional > $maximum) {
            throw new UserRuntimeLimitExceeded('HIGH_COST_JOB_LIMIT_EXCEEDED', $current, $maximum);
        }
    }
}
