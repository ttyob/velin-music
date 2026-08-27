<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\application\Scrape\AudioLyricsWriter;
use app\application\Scrape\AudioMetadataRewrite;
use app\application\Scrape\FfmpegAudioMetadataWriter;
use app\application\Scrape\ScrapePipelineFailed;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Media\FfprobeMediaProbe;
use Closure;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;
use support\Log;
use Throwable;

/**
 * 消费歌词音频标签写回任务并维护文件系统、库存身份和扫描 Outbox 的补偿状态机。
 *
 * Worker 是实际修改最终音乐库音频的唯一入口。领取后重新读取歌词正文，但正文只存在于当前调用栈；
 * 方案、任务、操作日志、审计、异常与 SSE 均只保存对象标识、摘要之外的安全计数和稳定错误码。底层
 * 写入器与描述标签流程共享完整备份、无重编码、硬链接分离、原子发布和精确回滚实现。
 */
final class LyricsAudioTagWritebackWorkerService
{
    private const LEASE_SECONDS = 900;
    /** @var Closure(string,array<string,mixed>):void 数据库提交后备份清理失败的脱敏告警出口。 */
    private readonly Closure $warningLogger;

    public function __construct(
        private readonly AudioLyricsWriter $writer = new FfmpegAudioMetadataWriter(),
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
        private readonly FfprobeMediaProbe $probe = new FfprobeMediaProbe(),
        private readonly AuditLogger $audit = new AuditLogger(),
        ?Closure $warningLogger = null,
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    ) {
        $this->warningLogger = $warningLogger ?? static fn (string $message, array $context): mixed => Log::warning($message, $context);
    }

    /** 使用状态 CAS 领取最早 queued 任务，竞争者不会重复取得同一任务。 */
    public function claimNext(string $workerId): ?array
    {
        return Db::transaction(function () use ($workerId): ?array {
            $schema = Db::connection()->getSchemaBuilder();
            if (!$schema->hasTable('lyrics_audio_tag_writeback_jobs')) return null;
            /** @var stdClass|null $job */
            $job = Db::table('lyrics_audio_tag_writeback_jobs')->where('status','queued')
                ->orderBy('created_at')->orderBy('id')->first(['id','plan_id']);
            if (!$job instanceof stdClass) return null;
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $changed = Db::table('lyrics_audio_tag_writeback_jobs')->where('id',(string)$job->id)
                ->where('status','queued')->update(['status'=>'running','phase'=>'validating','attempt'=>Db::raw('attempt + 1'),
                    'worker_id'=>$workerId,'heartbeat_at'=>$now,'started_at'=>$now,'updated_at'=>$now]);
            if ($changed !== 1) return null;
            $planChanged = Db::table('lyrics_audio_tag_writeback_plans')->where('id',(string)$job->plan_id)
                ->where('status','queued')->update(['status'=>'running','updated_at'=>$now]);
            if ($planChanged !== 1) {
                throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_PLAN_STATE_INVALID','歌词音频标签方案状态无效。');
            }
            return ['id'=>(string)$job->id];
        });
    }

    /** 执行一次完整替换；数据库提交前任何失败都恢复原文件。 */
    public function execute(array $claimed): void
    {
        $jobId = $claimed['id'] ?? '';
        if (!is_string($jobId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/',$jobId)!==1) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_JOB_INVALID','歌词音频标签任务标识无效。');
        }
        $row = $this->row($jobId);
        $rewrite = null;
        try {
            [$content,$target] = $this->validate($row);
            $this->phase($jobId,'writing');
            $this->startOperation($jobId,1,'rewrite',(int)$row->source_link_count>1);
            try {
                $rewrite = $this->writer->rewriteLyrics($target,$content,(string)$row->lyric_kind,$jobId);
            } catch (ScrapePipelineFailed $failure) {
                throw new LyricsAudioTagWritebackFailed($failure->errorCode,'音频容器拒绝歌词标签写入。',$failure);
            }
            $this->phase($jobId,'verifying');
            $identity = $this->writtenIdentity($target);
            $this->finishOperation($jobId,1,'succeeded',null,$rewrite->detachedLink,$identity);
            $this->startOperation($jobId,2,'verify',$rewrite->detachedLink);
            $this->finishOperation($jobId,2,'succeeded',null,$rewrite->detachedLink,$identity);
            $this->phase($jobId,'persisting');
            $this->complete($row,$identity);
            // 事务成功后库存已经指向新 inode，此后绝不能再回滚；清理异常只留下受控备份和诊断。
            $committed = $rewrite;
            $rewrite = null;
            try { $this->writer->commit($committed); }
            catch (Throwable $failure) { $this->recordCleanupFailure($row,$committed->detachedLink,$failure); }
        } catch (LyricsAudioTagWritebackFailed $failure) {
            $code = $failure->reasonCode;
            if ($rewrite instanceof AudioMetadataRewrite) {
                $code = $this->compensate($row,$rewrite)
                    ? 'LYRICS_AUDIO_TAG_DATABASE_COMMIT_FAILED' : 'LYRICS_AUDIO_TAG_COMPENSATION_FAILED';
            }
            $this->finishOpenOperation($jobId,$code);
            $this->fail($row,$code,$this->isStaleCode($failure->reasonCode));
        } catch (Throwable) {
            $code = 'LYRICS_AUDIO_TAG_WORKER_FAILED';
            if ($rewrite instanceof AudioMetadataRewrite) {
                $code = $this->compensate($row,$rewrite)
                    ? 'LYRICS_AUDIO_TAG_DATABASE_COMMIT_FAILED' : 'LYRICS_AUDIO_TAG_COMPENSATION_FAILED';
            }
            $this->finishOpenOperation($jobId,$code);
            $this->fail($row,$code,false);
        } finally {
            // 父批次不能依赖浏览器轮询才能收敛；单曲终态提交后按关联事实幂等重建父状态。
            // 同步失败不能改写已经确定的单曲文件/数据库结果，后续详情读取仍会再次执行相同重建。
            if (isset($row) && $row instanceof stdClass) {
                $this->synchronizeBatch((string) $row->plan_id);
            }
        }
    }

    /** 在迁移已完成且当前方案属于批次时同步父状态，不读取歌词正文或文件。 */
    private function synchronizeBatch(string $planId): void
    {
        try {
            $schema=Db::connection()->getSchemaBuilder();
            if(!$schema->hasTable('lyrics_audio_tag_writeback_batch_targets'))return;
            $batchId=Db::table('lyrics_audio_tag_writeback_batch_targets')
                ->where('writeback_plan_id',$planId)->value('batch_plan_id');
            if(is_string($batchId)&&preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/',$batchId)===1)
                (new LyricsAudioTagWritebackBatchStateService())->synchronize($batchId);
        } catch (Throwable $error) {
            try { ($this->warningLogger)('Lyrics audio tag batch synchronization failed after child terminal state.',
                ['plan_id'=>$planId,'exception_class'=>$error::class]); } catch (Throwable) {}
        }
    }

    /** 过期租约失败关闭；操作日志无法证明崩溃点，因此不猜测性重放或删除文件。 */
    public function recoverStaleLeases(): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('lyrics_audio_tag_writeback_jobs')) return;
        $threshold = gmdate('Y-m-d\TH:i:s\Z',time()-self::LEASE_SECONDS);
        $rows = Db::table('lyrics_audio_tag_writeback_jobs')->where('status','running')
            ->where('heartbeat_at','<',$threshold)->get(['id','plan_id','heartbeat_at'])->all();
        foreach ($rows as $row) {
            Db::transaction(function () use ($row): void {
                $now=gmdate('Y-m-d\TH:i:s\Z');
                $changed=Db::table('lyrics_audio_tag_writeback_jobs')->where('id',(string)$row->id)
                    ->where('status','running')->where('heartbeat_at',(string)$row->heartbeat_at)->update([
                        'status'=>'failed','phase'=>'failed','worker_id'=>null,'heartbeat_at'=>null,'finished_at'=>$now,
                        'error_code'=>'LYRICS_AUDIO_TAG_LEASE_EXPIRED','updated_at'=>$now]);
                if($changed===1) Db::table('lyrics_audio_tag_writeback_plans')->where('id',(string)$row->plan_id)
                    ->where('status','running')->update(['status'=>'failed','finished_at'=>$now,
                        'error_code'=>'LYRICS_AUDIO_TAG_LEASE_EXPIRED','updated_at'=>$now]);
            });
        }
    }

    /** 读取 running 任务、不可变方案、歌词、库存和当前库事实。 */
    private function row(string $jobId): stdClass
    {
        $row=Db::table('lyrics_audio_tag_writeback_jobs as jobs')
            ->join('lyrics_audio_tag_writeback_plans as plans','plans.id','=','jobs.plan_id')
            ->join('media_lyrics as lyrics','lyrics.id','=','plans.lyric_id')
            ->join('media_songs as songs','songs.id','=','plans.song_id')
            ->join('library_file_inventory as files','files.id','=','plans.inventory_file_id')
            ->join('music_libraries as libraries','libraries.id','=','plans.library_id')
            ->where('jobs.id',$jobId)->where('jobs.status','running')->where('plans.status','running')->first([
                'jobs.id as job_id','jobs.plan_id','jobs.requested_by','jobs.request_id',
                'plans.song_id','plans.lyric_id','plans.library_id','plans.inventory_file_id',
                'plans.expected_lyric_version','plans.expected_library_version','plans.source_kind','plans.source_format',
                'plans.language','plans.lyric_kind','plans.license_policy','plans.lyric_content_sha256',
                'plans.output_sha256','plans.output_size_bytes','plans.existing_tag_sha256','plans.source_relative_path',
                'plans.source_device','plans.source_inode','plans.source_file_size','plans.source_modified_at',
                'plans.source_link_count','plans.container_key','plans.tag_key',
                'lyrics.song_id as lyric_song_id','lyrics.source_kind as current_source_kind',
                'lyrics.source_format as current_source_format','lyrics.language as current_language',
                'lyrics.lyric_kind as current_lyric_kind','lyrics.content_sha256 as current_content_sha256',
                'lyrics.license_policy as current_license_policy','lyrics.version as current_lyric_version',
                'songs.library_id as song_library_id','songs.inventory_file_id as song_inventory_file_id',
                'files.library_id as file_library_id','files.relative_path','files.resolved_path','files.device_id','files.inode',
                'files.file_size','files.modified_at','files.status as file_status','files.metadata_status',
                'libraries.resolved_root_path','libraries.status as library_status','libraries.version as library_version',
            ]);
        if(!$row instanceof stdClass) throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_JOB_STATE_INVALID','任务状态无效。');
        return $row;
    }

    /**
     * 第三次复验方案全部证据并返回仅在内存存在的正文与真实目标。
     *
     * @return array{0:string,1:string}
     */
    private function validate(stdClass $row): array
    {
        if((string)$row->lyric_song_id!==(string)$row->song_id
            ||(string)$row->song_library_id!==(string)$row->library_id
            ||(string)$row->file_library_id!==(string)$row->library_id
            ||(string)$row->song_inventory_file_id!==(string)$row->inventory_file_id
            ||(string)$row->library_status!=='active'||(string)$row->file_status!=='available'
            ||(string)$row->metadata_status!=='ready') {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_SCOPE_STALE','对象范围已变化。');
        }
        if((int)$row->current_lyric_version!==(int)$row->expected_lyric_version
            ||(int)$row->library_version!==(int)$row->expected_library_version
            ||(string)$row->current_source_kind!==(string)$row->source_kind
            ||(string)$row->current_source_format!==(string)$row->source_format
            ||(string)$row->current_language!==(string)$row->language
            ||(string)$row->current_lyric_kind!==(string)$row->lyric_kind
            ||(string)$row->current_license_policy!==(string)$row->license_policy
            ||!in_array((string)$row->current_license_policy,['local_controlled','redistributable'],true)
            ||!hash_equals((string)$row->lyric_content_sha256,(string)$row->current_content_sha256)) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_PLAN_STALE','歌词或音乐库事实已变化。');
        }
        try {
            $parsed=$this->files->readById((string)$row->lyric_id);
            if($parsed->kind!==(string)$row->lyric_kind) throw new LyricsWritebackInvalid('歌词文件解析类型与索引不一致。');
            $content=$this->serializer->serialize($parsed->kind,$parsed->lines);
        }
        catch(Throwable $error){ throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_CONTENT_INVALID','歌词结构无效。',$error); }
        if(!hash_equals((string)$row->output_sha256,hash('sha256',$content))||strlen($content)!==(int)$row->output_size_bytes) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_CONTENT_STALE','序列化歌词已变化。');
        }
        if((string)$row->relative_path!==(string)$row->source_relative_path
            ||(int)$row->device_id!==(int)$row->source_device||(int)$row->inode!==(int)$row->source_inode
            ||(int)$row->file_size!==(int)$row->source_file_size||(int)$row->modified_at!==(int)$row->source_modified_at) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_AUDIO_STALE','音频库存身份已变化。');
        }
        $configured=rtrim((string)$row->resolved_root_path,DIRECTORY_SEPARATOR);
        $root=realpath($configured);
        $target=$configured.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,(string)$row->source_relative_path);
        clearstatcache(true,$target);
        if($root===false||$root!==$configured||realpath($target)!==$target||(string)$row->resolved_path!==$target
            ||is_link($target)||!is_file($target)||!is_readable($target)||!is_writable($target)) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_PATH_STALE','音频路径或权限已变化。');
        }
        $stat=@stat($target);
        if(!is_array($stat)||(int)$stat['dev']!==(int)$row->source_device||(int)$stat['ino']!==(int)$row->source_inode
            ||(int)$stat['size']!==(int)$row->source_file_size||(int)$stat['mtime']!==(int)$row->source_modified_at
            ||max(1,(int)($stat['nlink']??1))!==(int)$row->source_link_count) {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_AUDIO_STALE','音频真实身份已变化。');
        }
        $container=match(strtolower(pathinfo($target,PATHINFO_EXTENSION))){
            'mp3'=>'mp3','flac'=>'flac','opus'=>'opus','m4a','m4b','mp4'=>'mp4',default=>null};
        if($container===null||$container!==(string)$row->container_key||(string)$row->tag_key!=='lyrics') {
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_CONTAINER_STALE','音频容器能力已变化。');
        }
        try{$metadata=$this->probe->probe($target,pathinfo($target,PATHINFO_FILENAME));}
        catch(Throwable $error){throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_PROBE_FAILED','无法复验现有歌词标签。',$error);}
        $existing=$this->existingLyricsDigest($metadata->rawTags);
        $expectedExisting=$row->existing_tag_sha256===null?null:(string)$row->existing_tag_sha256;
        if($existing!==$expectedExisting) throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_EXISTING_STALE','现有歌词标签已变化。');
        return[$content,$target];
    }

    /** @param array<string,mixed> $rawTags 对统一键和历史别名生成不保存正文的稳定摘要。 */
    private function existingLyricsDigest(array $rawTags): ?string
    {
        $values=[];foreach($rawTags as$tags){if(!is_array($tags))continue;
            foreach(['lyrics','unsyncedlyrics','syncedlyrics']as$key)
                if(is_string($tags[$key]??null)&&$tags[$key]!=='')$values[$key]=$tags[$key];}
        if($values===[])return null;ksort($values);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** @return array{device:int,inode:int,size:int,mtime:int,sha256:string} */
    private function writtenIdentity(string $target): array
    {
        clearstatcache(true,$target);$stat=@stat($target);$sha=is_file($target)&&!is_link($target)?@hash_file('sha256',$target):false;
        if(!is_array($stat)||!is_string($sha)||strlen($sha)!==64) throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_OUTPUT_INVALID','写回结果身份无效。');
        return['device'=>(int)$stat['dev'],'inode'=>(int)$stat['ino'],'size'=>(int)$stat['size'],'mtime'=>(int)$stat['mtime'],'sha256'=>$sha];
    }

    /** 推进阶段并刷新租约心跳。 */
    private function phase(string $jobId,string $phase): void
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');
        if(Db::table('lyrics_audio_tag_writeback_jobs')->where('id',$jobId)->where('status','running')
            ->update(['phase'=>$phase,'heartbeat_at'=>$now,'updated_at'=>$now])!==1)
            throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_JOB_STATE_INVALID','任务状态已变化。');
    }

    /** 文件 I/O 前持久化无正文、无路径操作意图。 */
    private function startOperation(string $jobId,int $sequence,string $operation,bool $detached): void
    {
        Db::table('lyrics_audio_tag_writeback_operation_logs')->insert(['id'=>(string)new Ulid(),'job_id'=>$jobId,
            'sequence'=>$sequence,'operation'=>$operation,'status'=>'started','detached_hardlink'=>$detached?1:0,
            'output_sha256'=>null,'output_size_bytes'=>null,'error_code'=>null,
            'started_at'=>gmdate('Y-m-d\TH:i:s\Z'),'finished_at'=>null]);
    }

    /** @param array{device:int,inode:int,size:int,mtime:int,sha256:string}|null $identity */
    private function finishOperation(string $jobId,int $sequence,string $status,?string $errorCode,
        bool $detached=false,?array $identity=null): void
    {
        Db::table('lyrics_audio_tag_writeback_operation_logs')->where('job_id',$jobId)->where('sequence',$sequence)
            ->where('status','started')->update(['status'=>$status,'detached_hardlink'=>$detached?1:0,
                'output_sha256'=>$identity['sha256']??null,'output_size_bytes'=>$identity['size']??null,
                'error_code'=>$errorCode,'finished_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
    }

    /** 把最后一个开放步骤标记失败。 */
    private function finishOpenOperation(string $jobId,string $code): void
    {
        $op=Db::table('lyrics_audio_tag_writeback_operation_logs')->where('job_id',$jobId)->where('status','started')
            ->orderByDesc('sequence')->first(['sequence']);
        if($op instanceof stdClass)$this->finishOperation($jobId,(int)$op->sequence,'failed',$code);
    }

    /** @param array{device:int,inode:int,size:int,mtime:int,sha256:string} $identity */
    private function complete(stdClass $row,array $identity): void
    {
        Db::transaction(function()use($row,$identity):void{
            $now=gmdate('Y-m-d\TH:i:s\Z');
            $inventory=Db::table('library_file_inventory')->where('id',(string)$row->inventory_file_id)
                ->where('device_id',(int)$row->source_device)->where('inode',(int)$row->source_inode)
                ->where('file_size',(int)$row->source_file_size)->where('modified_at',(int)$row->source_modified_at)
                ->update(['device_id'=>$identity['device'],'inode'=>$identity['inode'],'file_size'=>$identity['size'],
                    'modified_at'=>$identity['mtime'],'metadata_signature'=>null,'byte_hash_status'=>'pending','byte_sha256'=>null,
                    'acoustic_fingerprint_status'=>'pending','acoustic_fingerprint_sha256'=>null,'acoustic_duration_seconds'=>null,
                    'duplicate_evidence_signature'=>null,'duplicate_evidence_error_code'=>null,'updated_at'=>$now]);
            $job=Db::table('lyrics_audio_tag_writeback_jobs')->where('id',(string)$row->job_id)->where('status','running')
                ->update(['status'=>'succeeded','phase'=>'completed','worker_id'=>null,'heartbeat_at'=>null,
                    'finished_at'=>$now,'error_code'=>null,'updated_at'=>$now]);
            $plan=Db::table('lyrics_audio_tag_writeback_plans')->where('id',(string)$row->plan_id)->where('status','running')
                ->update(['status'=>'succeeded','finished_at'=>$now,'error_code'=>null,'updated_at'=>$now]);
            if($inventory!==1||$job!==1||$plan!==1) throw new LyricsAudioTagWritebackFailed('LYRICS_AUDIO_TAG_COMMIT_CONFLICT','完成状态冲突。');
            if(Db::table('metadata_writeback_scan_requests')->where('library_id',(string)$row->library_id)->exists()){
                Db::table('metadata_writeback_scan_requests')->where('library_id',(string)$row->library_id)->update([
                    'status'=>'pending','scan_job_id'=>null,'last_requested_at'=>$now,'enqueued_at'=>null,'updated_at'=>$now]);
            }else{
                Db::table('metadata_writeback_scan_requests')->insert(['library_id'=>(string)$row->library_id,'status'=>'pending',
                    'scan_job_id'=>null,'first_requested_at'=>$now,'last_requested_at'=>$now,'enqueued_at'=>null,'updated_at'=>$now]);
            }
            $this->audit->record((string)$row->requested_by,'lyrics.audio_tag.job.complete','lyrics_audio_tag_writeback_job',
                (string)$row->job_id,'success',(string)$row->request_id,['planId'=>(string)$row->plan_id,
                    'songId'=>(string)$row->song_id,'lyricId'=>(string)$row->lyric_id,
                    'libraryId'=>(string)$row->library_id,'detachedHardlink'=>(int)$row->source_link_count>1]);
        });
    }

    /** 恢复原文件并按方案身份复验补偿结果。 */
    private function compensate(stdClass $row,AudioMetadataRewrite $rewrite): bool
    {
        $this->writer->rollback($rewrite);clearstatcache(true,$rewrite->targetPath);$stat=@stat($rewrite->targetPath);
        $ok=is_array($stat)&&(int)$stat['dev']===(int)$row->source_device&&(int)$stat['ino']===(int)$row->source_inode
            &&(int)$stat['size']===(int)$row->source_file_size&&(int)$stat['mtime']===(int)$row->source_modified_at;
        $now=gmdate('Y-m-d\TH:i:s\Z');
        Db::table('lyrics_audio_tag_writeback_operation_logs')->insert(['id'=>(string)new Ulid(),'job_id'=>(string)$row->job_id,
            'sequence'=>3,'operation'=>'compensate','status'=>$ok?'compensated':'failed',
            'detached_hardlink'=>$rewrite->detachedLink?1:0,'output_sha256'=>null,'output_size_bytes'=>null,
            'error_code'=>$ok?null:'LYRICS_AUDIO_TAG_COMPENSATION_FAILED','started_at'=>$now,'finished_at'=>$now]);
        return$ok;
    }

    /** 数据库提交后的备份清理失败只写诊断，不能回滚已提交文件。 */
    private function recordCleanupFailure(stdClass $row,bool $detached,Throwable $failure): void
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');
        try{Db::table('lyrics_audio_tag_writeback_operation_logs')->insert(['id'=>(string)new Ulid(),
            'job_id'=>(string)$row->job_id,'sequence'=>3,'operation'=>'backup','status'=>'failed',
            'detached_hardlink'=>$detached?1:0,'output_sha256'=>null,'output_size_bytes'=>null,
            'error_code'=>'LYRICS_AUDIO_TAG_BACKUP_CLEANUP_FAILED','started_at'=>$now,'finished_at'=>$now]);}
        catch(Throwable $loggingFailure){try{($this->warningLogger)('Lyrics audio tag backup cleanup and operation logging failed.',
            ['job_id'=>(string)$row->job_id,'cleanup_exception_class'=>$failure::class,'logging_exception_class'=>$loggingFailure::class]);}catch(Throwable){}return;}
        try{($this->warningLogger)('Lyrics audio tag backup cleanup failed after database commit.',
            ['job_id'=>(string)$row->job_id,'exception_class'=>$failure::class]);}catch(Throwable){}
    }

    /** 提交失败终态和无正文审计。 */
    private function fail(stdClass $row,string $code,bool $stale): void
    {
        Db::transaction(function()use($row,$code,$stale):void{$now=gmdate('Y-m-d\TH:i:s\Z');
            Db::table('lyrics_audio_tag_writeback_jobs')->where('id',(string)$row->job_id)->where('status','running')
                ->update(['status'=>'failed','phase'=>'failed','worker_id'=>null,'heartbeat_at'=>null,'finished_at'=>$now,
                    'error_code'=>$code,'updated_at'=>$now]);
            Db::table('lyrics_audio_tag_writeback_plans')->where('id',(string)$row->plan_id)->where('status','running')
                ->update(['status'=>$stale?'stale':'failed','finished_at'=>$now,'error_code'=>$code,'updated_at'=>$now]);
            $this->audit->record((string)$row->requested_by,'lyrics.audio_tag.job.complete','lyrics_audio_tag_writeback_job',
                (string)$row->job_id,'failure',(string)$row->request_id,['planId'=>(string)$row->plan_id,
                    'songId'=>(string)$row->song_id,'lyricId'=>(string)$row->lyric_id,
                    'libraryId'=>(string)$row->library_id,'errorCode'=>$code]);});
    }

    /** 执行前权威事实漂移标记 stale，容器或基础设施故障保持普通 failed。 */
    private function isStaleCode(string $code): bool
    {
        return in_array($code,['LYRICS_AUDIO_TAG_SCOPE_STALE','LYRICS_AUDIO_TAG_PLAN_STALE',
            'LYRICS_AUDIO_TAG_CONTENT_STALE','LYRICS_AUDIO_TAG_AUDIO_STALE','LYRICS_AUDIO_TAG_PATH_STALE',
            'LYRICS_AUDIO_TAG_CONTAINER_STALE','LYRICS_AUDIO_TAG_EXISTING_STALE'],true);
    }
}
