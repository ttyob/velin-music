<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use stdClass;
use support\Db;

/**
 * 从单曲写回任务事实重新计算批量父方案状态。
 *
 * 本服务不拥有第二套执行状态机，也不访问歌词正文、媒体文件或网络。单曲 plan/job 仍是每次文件写入
 * 的事实来源；父方案只是可重建汇总。Worker 终态、租约恢复和管理详情均可安全重复调用，因而进程在
 * 子任务完成与父计数更新之间退出时不会永久留下错误进度。
 */
final class LyricsWritebackBatchStateService
{
    /**
     * 幂等同步一个已确认批次的逐项目标和父级计数。
     *
     * 滚动升级或精简单元测试可能尚无批量表，此时直接返回。同步在短事务内完成，不领取任务；状态只
     * 能从 queued/running 向终态收敛，已是 draft/expired 的方案不会被子表意外激活。错误只复制稳定
     * `error_code`，不复制异常、路径、摘要或歌词内容。
     */
    public function synchronize(string $batchId): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('lyrics_writeback_batch_plans')
            || !$schema->hasTable('lyrics_writeback_batch_targets')) {
            return;
        }

        Db::transaction(function () use ($batchId): void {
            /** @var stdClass|null $batch */
            $batch = Db::table('lyrics_writeback_batch_plans')->where('id', $batchId)->first();
            if (!$batch instanceof stdClass || $batch->confirmed_at === null
                || in_array((string) $batch->status, ['draft', 'expired'], true)) {
                return;
            }
            /** @var list<stdClass> $targets */
            $targets = Db::table('lyrics_writeback_batch_targets as targets')
                ->join('lyrics_writeback_plans as plans', 'plans.id', '=', 'targets.writeback_plan_id')
                ->leftJoin('lyrics_writeback_jobs as jobs', 'jobs.plan_id', '=', 'plans.id')
                ->where('targets.batch_plan_id', $batchId)->orderBy('targets.position')->get([
                    'targets.position', 'targets.status as target_status',
                    'plans.status as plan_status', 'plans.error_code as plan_error_code',
                    'jobs.status as job_status', 'jobs.error_code as job_error_code',
                ])->all();
            if (count($targets) !== (int) $batch->target_count) {
                return;
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $succeeded = 0;
            $failed = 0;
            $running = 0;
            foreach ($targets as $target) {
                [$status, $errorCode] = $this->targetState($target);
                if ($status === 'succeeded') {
                    $succeeded++;
                } elseif (in_array($status, ['failed', 'stale'], true)) {
                    $failed++;
                } elseif ($status === 'running') {
                    $running++;
                }
                if ((string) $target->target_status !== $status) {
                    Db::table('lyrics_writeback_batch_targets')->where('batch_plan_id', $batchId)
                        ->where('position', (int) $target->position)->update([
                            'status' => $status,
                            'error_code' => $errorCode,
                            'updated_at' => $now,
                        ]);
                }
            }

            $processed = $succeeded + $failed;
            if ($processed === (int) $batch->target_count) {
                $status = $failed === 0 ? 'succeeded' : ($succeeded === 0 ? 'failed' : 'partial');
            } elseif ($running > 0 || $processed > 0) {
                $status = 'running';
            } else {
                $status = 'queued';
            }
            $terminal = in_array($status, ['succeeded', 'partial', 'failed'], true);
            Db::table('lyrics_writeback_batch_plans')->where('id', $batchId)
                ->whereIn('status', ['queued', 'running', 'succeeded', 'partial', 'failed'])->update([
                    'status' => $status,
                    'processed_count' => $processed,
                    'succeeded_count' => $succeeded,
                    'failed_count' => $failed,
                    'started_at' => $batch->started_at ?? ($status === 'running' || $terminal ? $now : null),
                    'finished_at' => $terminal ? ($batch->finished_at ?? $now) : null,
                    'updated_at' => $now,
                ]);
        });
    }

    /**
     * 将一个单曲 plan/job 状态转换为批量目标状态。
     *
     * 已有目标冲突在确认时已写成 failed 且没有 job，必须保留；其他目标优先信任 job 终态。`stale`
     * 单独保留便于管理员判断需要重新预览，父级计数仍把它计为失败。
     *
     * @return array{0:string,1:?string}
     */
    private function targetState(stdClass $target): array
    {
        if ((string) $target->target_status === 'failed' && $target->job_status === null) {
            return ['failed', 'LYRICS_TARGET_CONFLICT'];
        }
        if ($target->job_status !== null) {
            $jobStatus = (string) $target->job_status;
            if ($jobStatus === 'succeeded') return ['succeeded', null];
            if ($jobStatus === 'failed' || $jobStatus === 'cancelled') {
                $stale = (string) $target->plan_status === 'stale';
                return [$stale ? 'stale' : 'failed', $target->job_error_code === null
                    ? ($stale ? 'LYRICS_PLAN_STALE' : 'LYRICS_WRITEBACK_FAILED')
                    : (string) $target->job_error_code];
            }
            if ($jobStatus === 'running') return ['running', null];
            return ['queued', null];
        }
        if ((string) $target->plan_status === 'stale') {
            return ['stale', $target->plan_error_code === null
                ? 'LYRICS_PLAN_STALE' : (string) $target->plan_error_code];
        }

        return [(string) $target->target_status, null];
    }
}
