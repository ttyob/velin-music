<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use stdClass;
use support\Db;

/**
 * 从单曲歌词音频标签任务事实幂等重建批量父方案状态。
 *
 * 本服务不读取歌词正文、音频文件或网络，也不领取任务。单曲 plan/job 是唯一执行事实；父批次只
 * 提供可重建的进度投影，因此 Worker 在子任务终态与父计数更新之间退出不会留下永久错误状态。
 */
final class LyricsAudioTagWritebackBatchStateService
{
    /** 同步逐项目标与父计数；draft/expired 批次不会被意外激活。 */
    public function synchronize(string $batchId): void
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('lyrics_audio_tag_writeback_batch_plans')) return;
        Db::transaction(function () use ($batchId): void {
            $batch = Db::table('lyrics_audio_tag_writeback_batch_plans')->where('id',$batchId)->first();
            if (!$batch instanceof stdClass || $batch->confirmed_at === null
                || in_array((string)$batch->status,['draft','expired'],true)) return;
            $targets = Db::table('lyrics_audio_tag_writeback_batch_targets as targets')
                ->join('lyrics_audio_tag_writeback_plans as plans','plans.id','=','targets.writeback_plan_id')
                ->leftJoin('lyrics_audio_tag_writeback_jobs as jobs','jobs.plan_id','=','plans.id')
                ->where('targets.batch_plan_id',$batchId)->orderBy('targets.position')->get([
                    'targets.position','targets.status as target_status','plans.status as plan_status',
                    'plans.error_code as plan_error_code','jobs.status as job_status','jobs.error_code as job_error_code',
                ])->all();
            if (count($targets)!==(int)$batch->target_count) return;
            $succeeded=0;$failed=0;$running=0;$now=gmdate('Y-m-d\TH:i:s\Z');
            foreach($targets as $target){[$status,$code]=$this->state($target);
                if($status==='succeeded')$succeeded++;elseif(in_array($status,['failed','stale'],true))$failed++;
                elseif($status==='running')$running++;
                if((string)$target->target_status!==$status)Db::table('lyrics_audio_tag_writeback_batch_targets')
                    ->where('batch_plan_id',$batchId)->where('position',(int)$target->position)
                    ->update(['status'=>$status,'error_code'=>$code,'updated_at'=>$now]);
            }
            $processed=$succeeded+$failed;
            $status=$processed===(int)$batch->target_count
                ?($failed===0?'succeeded':($succeeded===0?'failed':'partial'))
                :($running>0||$processed>0?'running':'queued');
            $terminal=in_array($status,['succeeded','partial','failed'],true);
            Db::table('lyrics_audio_tag_writeback_batch_plans')->where('id',$batchId)
                ->whereIn('status',['queued','running','succeeded','partial','failed'])->update([
                    'status'=>$status,'processed_count'=>$processed,'succeeded_count'=>$succeeded,'failed_count'=>$failed,
                    'started_at'=>$batch->started_at??($status==='running'||$terminal?$now:null),
                    'finished_at'=>$terminal?($batch->finished_at??$now):null,'updated_at'=>$now,
                ]);
        });
    }

    /** @return array{0:string,1:?string} 把子任务状态转换为批量目标状态。 */
    private function state(stdClass $target): array
    {
        if($target->job_status!==null){$status=(string)$target->job_status;
            if($status==='succeeded')return['succeeded',null];
            if(in_array($status,['failed','cancelled'],true)){$stale=(string)$target->plan_status==='stale';
                return[$stale?'stale':'failed',$target->job_error_code===null
                    ?($stale?'LYRICS_AUDIO_TAG_PLAN_STALE':'LYRICS_AUDIO_TAG_WRITEBACK_FAILED'):(string)$target->job_error_code];}
            if($status==='running')return['running',null];return['queued',null];}
        if((string)$target->plan_status==='stale')return['stale',$target->plan_error_code===null
            ?'LYRICS_AUDIO_TAG_PLAN_STALE':(string)$target->plan_error_code];
        return[(string)$target->target_status,null];
    }
}
