<?php

declare(strict_types=1);

namespace app\application\Job;

use stdClass;
use support\Db;

/**
 * 将歌词音频标签写回任务投影为统一任务中心的无正文安全契约。
 *
 * 适配器只读取任务头与库名称，不读取歌词、摘要、文件身份、操作日志或文件系统。调用者必须拥有
 * `edit_metadata`，普通管理员还必须实时持有目标库 manage grant；失权与不存在统一不可见。
 */
final readonly class LyricsAudioTagWritebackJobProjectionService
{
    /** @return array{jobs:list<array<string,mixed>>,total:int} */
    public function page(array $actor,?string $libraryId,?string $status,?string $requestedBy,
        ?string $createdFrom,?string $createdBefore,int $limit,int $offset): array
    {
        $this->requireCapability($actor);$query=$this->query($actor);
        if($libraryId!==null)$query->where('jobs.library_id',$libraryId);
        if($status!==null)$query->where('jobs.status',$status);
        if($requestedBy!==null)$query->where('jobs.requested_by',$requestedBy);
        if($createdFrom!==null)$query->where('jobs.created_at','>=',$createdFrom);
        if($createdBefore!==null)$query->where('jobs.created_at','<',$createdBefore);
        $total=(clone$query)->count('jobs.id');
        $rows=$query->orderByDesc('jobs.created_at')->orderByDesc('jobs.id')->offset(max(0,$offset))
            ->limit(max(1,min(100,$limit)))->get($this->columns())->all();
        return['jobs'=>array_map(fn(stdClass $row):array=>$this->map($row),$rows),'total'=>$total];
    }

    /** 不存在或失权返回 null。 */
    public function find(array $actor,string $jobId): ?array
    {
        $this->requireCapability($actor);$row=$this->query($actor)->where('jobs.id',$jobId)->first($this->columns());
        return$row instanceof stdClass?$this->map($row):null;
    }

    /** @return array{libraries:list<array{id:string,label:string}>,requesters:list<array{id:string,label:string}>} */
    public function filterOptions(array $actor): array
    {
        $this->requireCapability($actor);$base=$this->query($actor);
        $libraries=(clone$base)->select(['libraries.id','libraries.name'])->distinct()->orderBy('libraries.name')->get()
            ->map(static fn(stdClass $row):array=>['id'=>(string)$row->id,'label'=>(string)$row->name])->all();
        $requesters=(clone$base)->whereNotNull('requesters.display_name')
            ->select(['jobs.requested_by as id','requesters.display_name as label'])->distinct()
            ->orderBy('requesters.display_name')->get()
            ->map(static fn(stdClass $row):array=>['id'=>(string)$row->id,'label'=>(string)$row->label])->all();
        return['libraries'=>$libraries,'requesters'=>$requesters];
    }

    /** 构造固定事实查询并应用 manage 级对象范围。 */
    private function query(array $actor): mixed
    {
        $query=Db::table('lyrics_audio_tag_writeback_jobs as jobs')
            ->join('lyrics_audio_tag_writeback_plans as plans','plans.id','=','jobs.plan_id')
            ->join('music_libraries as libraries','libraries.id','=','jobs.library_id')
            ->leftJoin('users as requesters','requesters.id','=','jobs.requested_by');
        // 已关联批量父方案的内部任务只在父项展示，避免同一次用户操作在任务中心重复出现。
        if(Db::connection()->getSchemaBuilder()->hasTable('lyrics_audio_tag_writeback_batch_targets'))
            $query->whereNotExists(function($targets):void{$targets->selectRaw('1')
                ->from('lyrics_audio_tag_writeback_batch_targets as batch_targets')
                ->whereColumn('batch_targets.writeback_plan_id','jobs.plan_id');});
        if(($actor['isSuperAdmin']??false)!==true){$ids=[];
            foreach(is_array($actor['libraries']??null)?$actor['libraries']:[] as $library)
                if(is_array($library)&&($library['accessLevel']??null)==='manage'&&is_string($library['id']??null))$ids[]=$library['id'];
            $query->whereIn('jobs.library_id',array_values(array_unique($ids))?:['']);}
        return$query;
    }

    /** @return list<string> 固定列集排除正文、路径、摘要、文件身份和内部调度字段。 */
    private function columns(): array
    {
        return['jobs.id','jobs.plan_id','jobs.library_id','jobs.requested_by','jobs.status','jobs.phase','jobs.attempt',
            'jobs.error_code','jobs.created_at','jobs.started_at','jobs.finished_at','jobs.updated_at',
            'libraries.name as library_name','requesters.display_name as requester_name'];
    }

    /** 单曲任务不伪造百分比、速度或 ETA。 */
    private function map(stdClass $row): array
    {
        $status=(string)$row->status;$finished=in_array($status,['succeeded','failed','cancelled'],true);
        $error=$row->error_code===null?null:(string)$row->error_code;
        return['id'=>(string)$row->id,'type'=>'lyrics_audio_tag_writeback','status'=>$status,'phase'=>(string)$row->phase,
            'subject'=>['type'=>'library','id'=>(string)$row->library_id,'label'=>(string)$row->library_name],
            'requestedBy'=>$row->requester_name===null?null:['id'=>(string)$row->requested_by,'label'=>(string)$row->requester_name],
            'progress'=>['processed'=>$finished?1:0,'discovered'=>1,'failed'=>$status==='failed'?1:0,
                'percent'=>null,'speed'=>null,'etaSeconds'=>null],'attempt'=>(int)$row->attempt,
            'error'=>$error===null?null:['code'=>$error,'message'=>'歌词音频标签写回失败，请查看来源方案。'],
            'createdAt'=>(string)$row->created_at,'startedAt'=>$row->started_at===null?null:(string)$row->started_at,
            'finishedAt'=>$row->finished_at===null?null:(string)$row->finished_at,'updatedAt'=>(string)$row->updated_at,
            'commands'=>['canCancel'=>false,'canRetry'=>false,'canRollback'=>false,'cancelBehavior'=>null,'expectedVersion'=>null],
            'sourceDetailHref'=>'/admin/media?audioTagPlanId='.rawurlencode((string)$row->plan_id),'errorSamples'=>null];
    }

    /** 防止适配器脱离任务中心被无能力调用。 */
    private function requireCapability(array $actor): void
    {
        if(!in_array('edit_metadata',is_array($actor['capabilities']??null)?$actor['capabilities']:[],true))
            throw new JobCenterInvalid('Unsupported lyrics audio tag writeback task type.');
    }
}
