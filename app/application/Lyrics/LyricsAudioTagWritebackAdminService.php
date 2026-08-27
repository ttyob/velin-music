<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\application\Metadata\MediaMetadataService;
use app\application\User\HighCostJobPolicy;
use app\infrastructure\Audit\AuditLogger;
use app\infrastructure\Media\FfprobeMediaProbe;
use Illuminate\Database\QueryException;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 编排歌词写入音频容器标签的独立 Dry Run、强确认和任务查询。
 *
 * 普通歌词保存、sidecar 写回、描述标签写回与本命令互不隐式触发。浏览器只能提交歌曲、歌词和版本
 * 标识；正文、路径、标签键与 FFmpeg 参数均由服务端权威事实生成。创建方案会只读探测现有标签并
 * 冻结其摘要，确认再次复验许可、歌词、库版本、文件身份和现有标签，HTTP 请求绝不修改媒体文件。
 */
final class LyricsAudioTagWritebackAdminService
{
    public const CONFIRMATION_TEXT = 'WRITE LYRICS TO AUDIO';
    private const PLAN_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly LyricsDocumentSerializer $serializer = new LyricsDocumentSerializer(),
        private readonly FfprobeMediaProbe $probe = new FfprobeMediaProbe(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly HighCostJobPolicy $highCostJobs = new HighCostJobPolicy(),
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    ) {
    }

    /**
     * 创建 24 小时有效的不可变预览；只保存歌词摘要，不复制正文。
     *
     * @param array<string,mixed> $actor 已通过全局 `edit_metadata` 的当前身份
     * @return array<string,mixed> 无正文、无物理路径、无文件身份的管理投影
     */
    public function create(string $songId, string $lyricId, int $expectedVersion, array $actor, string $requestId): array
    {
        $this->ulid($songId, '歌曲标识无效。');
        $this->ulid($lyricId, '歌词标识无效。');
        if ($expectedVersion < 1) throw new LyricsAudioTagWritebackInvalid('歌词版本无效。');
        $source = $this->source($songId, $lyricId, $actor);
        if ((int) $source->lyric_version !== $expectedVersion) {
            throw new LyricsAudioTagWritebackConflict('歌词版本已变化，请刷新后重新预览。');
        }
        [$content, $identity, $container, $existingDigest] = $this->snapshot($source);
        $planId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::PLAN_TTL_SECONDS);
        $fingerprint = [
            'planId' => $planId, 'songId' => $songId, 'lyricId' => $lyricId,
            'libraryId' => (string) $source->library_id, 'inventoryFileId' => (string) $source->inventory_file_id,
            'lyricVersion' => $expectedVersion, 'libraryVersion' => (int) $source->library_version,
            'lyricContentSha256' => (string) $source->content_sha256,
            'outputSha256' => hash('sha256', $content), 'outputSizeBytes' => strlen($content),
            'existingTagSha256' => $existingDigest, 'sourceRelativePath' => (string) $source->relative_path,
            'identity' => $identity, 'container' => $container, 'tagKey' => 'lyrics', 'expiresAt' => $expiresAt,
        ];
        $planHash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        Db::transaction(function () use ($actor, $container, $content, $existingDigest, $expiresAt, $identity, $now,
            $planHash, $planId, $requestId, $source): void {
            Db::table('lyrics_audio_tag_writeback_plans')->insert([
                'id' => $planId, 'song_id' => (string) $source->song_id, 'lyric_id' => (string) $source->lyric_id,
                'library_id' => (string) $source->library_id, 'inventory_file_id' => (string) $source->inventory_file_id,
                'created_by' => (string) $actor['id'], 'status' => 'planned',
                'expected_lyric_version' => (int) $source->lyric_version,
                'expected_library_version' => (int) $source->library_version,
                'source_kind' => (string) $source->source_kind, 'source_format' => (string) $source->source_format,
                'language' => (string) $source->language, 'lyric_kind' => (string) $source->lyric_kind,
                'license_policy' => (string) $source->license_policy,
                'lyric_content_sha256' => (string) $source->content_sha256,
                'output_sha256' => hash('sha256', $content), 'output_size_bytes' => strlen($content),
                'existing_tag_sha256' => $existingDigest, 'source_relative_path' => (string) $source->relative_path,
                'source_device' => $identity['device'], 'source_inode' => $identity['inode'],
                'source_file_size' => $identity['size'], 'source_modified_at' => $identity['mtime'],
                'source_link_count' => $identity['links'], 'container_key' => $container, 'tag_key' => 'lyrics',
                'plan_hash' => $planHash, 'version' => 1, 'expires_at' => $expiresAt,
                'confirmed_at' => null, 'finished_at' => null, 'error_code' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'lyrics.audio_tag.plan.create',
                'lyrics_audio_tag_writeback_plan', $planId, 'success', $requestId, [
                    'songId' => (string) $source->song_id, 'lyricId' => (string) $source->lyric_id,
                    'libraryId' => (string) $source->library_id, 'container' => $container,
                    'replacesExistingTag' => $existingDigest !== null, 'detachesHardlink' => $identity['links'] > 1,
                ]);
        });
        return $this->detail($planId, $actor);
    }

    /** 返回实时重新授权的预览和任务状态，不再次读取歌词正文或文件。 */
    public function detail(string $planId, array $actor): array
    {
        $plan = $this->plan($planId, $actor);
        $job = Db::table('lyrics_audio_tag_writeback_jobs')->where('plan_id', $planId)->first([
            'id','status','phase','attempt','error_code','created_at','started_at','finished_at',
        ]);
        return $this->map($plan, $job instanceof stdClass ? $job : null);
    }

    /**
     * 为 1-50 首歌曲创建批量音频歌词标签预览。
     *
     * 每个目标先复用单曲 `create()` 完成许可、容器、标签摘要和文件身份预检；外层事务保证任一目标
     * 失败时已准备的单曲方案和审计一并回滚，不留下不可见孤儿方案。批量层不复制正文或文件证据。
     *
     * @param list<array{songId:string,lyricId:string,expectedLyricVersion:int}> $targets
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function createBatch(array $targets,array $actor,string $requestId): array
    {
        if($targets===[]||count($targets)>50||!array_is_list($targets))
            throw new LyricsAudioTagWritebackInvalid('批量写回目标必须为 1 到 50 首歌曲。');
        $seen=[];foreach($targets as$target){
            if(!is_array($target)||array_keys($target)!==['songId','lyricId','expectedLyricVersion']
                ||!is_string($target['songId'])||!is_string($target['lyricId'])
                ||!is_int($target['expectedLyricVersion'])||$target['expectedLyricVersion']<1)
                throw new LyricsAudioTagWritebackInvalid('批量写回目标结构无效。');
            if(isset($seen[$target['songId']]))throw new LyricsAudioTagWritebackInvalid('同一歌曲不能重复写入。');
            $seen[$target['songId']]=true;
        }
        $batchId=(string)new Ulid();$plans=[];
        Db::transaction(function()use($actor,$batchId,$requestId,$targets,&$plans):void{
            foreach($targets as$target)$plans[]=$this->create($target['songId'],$target['lyricId'],
                $target['expectedLyricVersion'],$actor,$requestId);
            $expiresAt=min(array_column($plans,'expiresAt'));
            $hash=hash('sha256',json_encode(['batchId'=>$batchId,'expiresAt'=>$expiresAt,'targets'=>array_map(
                static fn(array$plan,int$position):array=>['position'=>$position,'planId'=>$plan['id'],
                    'planHash'=>$plan['planHash']],$plans,array_keys($plans))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $now=gmdate('Y-m-d\TH:i:s\Z');
            Db::table('lyrics_audio_tag_writeback_batch_plans')->insert(['id'=>$batchId,
                'requested_by'=>(string)$actor['id'],'request_id'=>$requestId,'plan_hash'=>$hash,
                'idempotency_key_sha256'=>null,'target_count'=>count($plans),'processed_count'=>0,
                'succeeded_count'=>0,'failed_count'=>0,'status'=>'draft','version'=>1,'expires_at'=>$expiresAt,
                'confirmed_at'=>null,'started_at'=>null,'finished_at'=>null,'created_at'=>$now,'updated_at'=>$now]);
            foreach($plans as$position=>$plan)Db::table('lyrics_audio_tag_writeback_batch_targets')->insert([
                'batch_plan_id'=>$batchId,'position'=>$position,'writeback_plan_id'=>$plan['id'],
                'song_id'=>$plan['song']['id'],'lyric_id'=>$plan['lyricId'],'library_id'=>$plan['library']['id'],
                'status'=>'planned','error_code'=>null,'updated_at'=>$now]);
            $this->audit->record((string)$actor['id'],'lyrics.audio_tag.batch.create',
                'lyrics_audio_tag_writeback_batch_plan',$batchId,'success',$requestId,['targetCount'=>count($plans)]);
        });
        return$this->batchDetail($batchId,$actor);
    }

    /** 返回完整实时授权且从子任务事实重建的无正文批量投影。 */
    public function batchDetail(string$batchId,array$actor):array
    {
        $batch=$this->batch($batchId,$actor);
        if((string)$batch->status!=='draft'){
            (new LyricsAudioTagWritebackBatchStateService())->synchronize($batchId);$batch=$this->batch($batchId,$actor);}
        $rows=Db::table('lyrics_audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->orderBy('position')->get(['position','writeback_plan_id','status','error_code'])->all();
        $targets=[];foreach($rows as$row){$plan=$this->detail((string)$row->writeback_plan_id,$actor);
            $targets[]=['position'=>(int)$row->position,'status'=>(string)$row->status,
                'errorCode'=>$row->error_code===null?null:(string)$row->error_code,'plan'=>$plan];}
        return['id'=>(string)$batch->id,'status'=>(string)$batch->status,'version'=>(int)$batch->version,
            'planHash'=>(string)$batch->plan_hash,'targetCount'=>(int)$batch->target_count,
            'processedCount'=>(int)$batch->processed_count,'succeededCount'=>(int)$batch->succeeded_count,
            'failedCount'=>(int)$batch->failed_count,'expiresAt'=>(string)$batch->expires_at,
            'confirmation'=>self::CONFIRMATION_TEXT,'confirmable'=>(string)$batch->status==='draft'
                &&(string)$batch->expires_at>gmdate('Y-m-d\TH:i:s\Z'),'targets'=>$targets,
            'createdAt'=>(string)$batch->created_at,
            'confirmedAt'=>$batch->confirmed_at===null?null:(string)$batch->confirmed_at,
            'startedAt'=>$batch->started_at===null?null:(string)$batch->started_at,
            'finishedAt'=>$batch->finished_at===null?null:(string)$batch->finished_at];
    }

    /** 强确认批次并在一个短事务中排队全部单曲 Durable 任务。 */
    public function confirmBatch(string$batchId,int$version,string$planHash,string$confirmation,string$idempotencyKey,
        array$actor,string$requestId):array
    {
        $this->ulid($batchId,'批量方案标识无效。');
        if($version<1||preg_match('/^[a-f0-9]{64}$/',$planHash)!==1||$confirmation!==self::CONFIRMATION_TEXT
            ||strlen($idempotencyKey)<16||strlen($idempotencyKey)>128
            ||preg_match('/^[\x21-\x7E]+$/',$idempotencyKey)!==1)
            throw new LyricsAudioTagWritebackInvalid('批量方案确认材料无效。');
        $keyHash=hash('sha256',$idempotencyKey);$batch=$this->batch($batchId,$actor);
        if($batch->idempotency_key_sha256!==null){
            if((string)$batch->requested_by===(string)$actor['id']
                &&hash_equals((string)$batch->idempotency_key_sha256,$keyHash))return$this->batchDetail($batchId,$actor);
            throw new LyricsAudioTagWritebackConflict('批量方案已经确认或幂等键不匹配。');}
        if((string)$batch->status!=='draft'||(int)$batch->version!==$version
            ||!hash_equals((string)$batch->plan_hash,$planHash)||(string)$batch->expires_at<=gmdate('Y-m-d\TH:i:s\Z'))
            throw new LyricsAudioTagWritebackConflict('批量方案已变化或过期，请重新预览。');
        $rows=Db::table('lyrics_audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->where('status','planned')->orderBy('position')->get(['writeback_plan_id'])->all();
        if(count($rows)!==(int)$batch->target_count)throw new LyricsAudioTagWritebackConflict('批量目标已变化。');
        foreach($rows as$row){$plan=$this->detail((string)$row->writeback_plan_id,$actor);
            if(!$plan['confirmable'])throw new LyricsAudioTagWritebackConflict('批量中的单曲方案已变化。');}
        try{Db::transaction(function()use($actor,$batch,$batchId,$idempotencyKey,$keyHash,$requestId,$rows,$version):void{
            $this->highCostJobs->assertCanQueue((string)$actor['id'],count($rows));$now=gmdate('Y-m-d\TH:i:s\Z');
            if(Db::table('lyrics_audio_tag_writeback_batch_plans')->where('id',$batchId)->where('status','draft')
                ->where('version',$version)->whereNull('idempotency_key_sha256')->update(['status'=>'queued',
                    'idempotency_key_sha256'=>$keyHash,'version'=>Db::raw('version + 1'),'confirmed_at'=>$now,'updated_at'=>$now])!==1)
                throw new LyricsAudioTagWritebackConflict('批量方案已变化。');
            foreach($rows as$row){$plan=$this->detail((string)$row->writeback_plan_id,$actor);
                $this->confirm($plan['id'],$plan['version'],$plan['planHash'],self::CONFIRMATION_TEXT,
                    hash_hmac('sha256',(string)$row->writeback_plan_id,$idempotencyKey),$actor,$requestId);
                Db::table('lyrics_audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
                    ->where('writeback_plan_id',(string)$row->writeback_plan_id)->where('status','planned')
                    ->update(['status'=>'queued','updated_at'=>$now]);}
            $this->audit->record((string)$actor['id'],'lyrics.audio_tag.batch.confirm',
                'lyrics_audio_tag_writeback_batch_plan',$batchId,'success',$requestId,
                ['targetCount'=>(int)$batch->target_count,'planVersion'=>$version]);
        });}catch(QueryException$error){$current=$this->batch($batchId,$actor);
            if($current->idempotency_key_sha256!==null&&hash_equals((string)$current->idempotency_key_sha256,$keyHash))
                return$this->batchDetail($batchId,$actor);
            if(str_contains(strtolower($error->getMessage()),'unique'))
                throw new LyricsAudioTagWritebackConflict('批量方案已有任务或幂等键已占用。',previous:$error);
            throw$error;}
        return$this->batchDetail($batchId,$actor);
    }

    /** 强确认当前方案并幂等排队；事务内只写数据库，不运行 FFmpeg。 */
    public function confirm(string $planId, int $version, string $planHash, string $confirmation,
        string $idempotencyKey, array $actor, string $requestId): array
    {
        $this->ulid($planId, '方案标识无效。');
        if ($version < 1 || preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1
            || $confirmation !== self::CONFIRMATION_TEXT || strlen($idempotencyKey) < 16
            || strlen($idempotencyKey) > 128 || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1) {
            throw new LyricsAudioTagWritebackInvalid('方案确认材料无效。');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $replay = $this->replay((string) $actor['id'], $keyHash, $planId);
        if ($replay !== null) return $replay;
        $plan = $this->plan($planId, $actor);
        if ((string) $plan->status !== 'planned' || (int) $plan->version !== $version
            || !hash_equals((string) $plan->plan_hash, $planHash)
            || (string) $plan->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new LyricsAudioTagWritebackConflict('方案已变化或过期，请重新预览。');
        }
        $this->assertCurrent($plan, $actor);
        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actor, $jobId, $keyHash, $now, $plan, $planId, $requestId, $version): void {
                $this->highCostJobs->assertCanQueue((string) $actor['id']);
                $changed = Db::table('lyrics_audio_tag_writeback_plans')->where('id', $planId)
                    ->where('status', 'planned')->where('version', $version)->update([
                        'status' => 'queued', 'version' => Db::raw('version + 1'),
                        'confirmed_at' => $now, 'updated_at' => $now,
                    ]);
                if ($changed !== 1) throw new LyricsAudioTagWritebackConflict('方案已变化，请重新预览。');
                Db::table('lyrics_audio_tag_writeback_jobs')->insert([
                    'id' => $jobId, 'plan_id' => $planId, 'library_id' => (string) $plan->library_id,
                    'song_id' => (string) $plan->song_id, 'lyric_id' => (string) $plan->lyric_id,
                    'requested_by' => (string) $actor['id'], 'request_id' => $requestId,
                    'idempotency_key_sha256' => $keyHash, 'status' => 'queued', 'phase' => 'queued',
                    'attempt' => 0, 'worker_id' => null, 'heartbeat_at' => null, 'started_at' => null,
                    'finished_at' => null, 'error_code' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record((string) $actor['id'], 'lyrics.audio_tag.plan.confirm',
                    'lyrics_audio_tag_writeback_plan', $planId, 'success', $requestId, [
                        'jobId' => $jobId, 'songId' => (string) $plan->song_id,
                        'lyricId' => (string) $plan->lyric_id, 'libraryId' => (string) $plan->library_id,
                    ]);
            });
        } catch (QueryException $error) {
            $replay = $this->replay((string) $actor['id'], $keyHash, $planId);
            if ($replay !== null) return $replay;
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new LyricsAudioTagWritebackConflict('方案已有任务或幂等键已占用。', previous: $error);
            }
            throw $error;
        }
        return ['id' => $jobId, 'planId' => $planId, 'status' => 'queued', 'phase' => 'queued', 'createdAt' => $now];
    }

    /** 确认阶段重新序列化歌词、探测现有标签并逐项比较不可变方案。 */
    private function assertCurrent(stdClass $plan, array $actor): void
    {
        $source = $this->source((string) $plan->song_id, (string) $plan->lyric_id, $actor);
        [$content, $identity, $container, $existingDigest] = $this->snapshot($source);
        if ((int) $source->lyric_version !== (int) $plan->expected_lyric_version
            || (int) $source->library_version !== (int) $plan->expected_library_version
            || (string) $source->source_kind !== (string) $plan->source_kind
            || (string) $source->source_format !== (string) $plan->source_format
            || (string) $source->language !== (string) $plan->language
            || (string) $source->lyric_kind !== (string) $plan->lyric_kind
            || (string) $source->license_policy !== (string) $plan->license_policy
            || !hash_equals((string) $plan->lyric_content_sha256, (string) $source->content_sha256)
            || !hash_equals((string) $plan->output_sha256, hash('sha256', $content))
            || strlen($content) !== (int) $plan->output_size_bytes
            || $container !== (string) $plan->container_key
            || $existingDigest !== ($plan->existing_tag_sha256 === null ? null : (string) $plan->existing_tag_sha256)
            || $identity !== ['device'=>(int)$plan->source_device,'inode'=>(int)$plan->source_inode,
                'size'=>(int)$plan->source_file_size,'mtime'=>(int)$plan->source_modified_at,
                'links'=>(int)$plan->source_link_count]) {
            throw new LyricsAudioTagWritebackConflict('歌词、音乐库、容器标签或文件身份已变化，请重新预览。');
        }
    }

    /** 读取歌曲、歌词、库存和库事实，并通过歌曲详情重复执行 manage 范围授权。 */
    private function source(string $songId, string $lyricId, array $actor): stdClass
    {
        try {
            (new MediaMetadataService())->song($actor, $songId);
        } catch (\Throwable $error) {
            throw new LyricsAudioTagWritebackNotFound('歌曲或歌词不存在。', previous: $error);
        }
        $row = Db::table('media_lyrics as lyrics')->join('media_songs as songs','songs.id','=','lyrics.song_id')
            ->join('library_file_inventory as files','files.id','=','songs.inventory_file_id')
            ->join('music_libraries as libraries','libraries.id','=','songs.library_id')
            ->where('songs.id',$songId)->where('lyrics.id',$lyricId)->where('files.status','available')
            ->where('files.metadata_status','ready')->where('libraries.status','active')
            ->where('libraries.source_type','local')->first([
                'songs.id as song_id','songs.title as song_title','songs.library_id','songs.inventory_file_id',
                'lyrics.id as lyric_id','lyrics.source_kind','lyrics.source_format','lyrics.language','lyrics.lyric_kind',
                'lyrics.content_sha256','lyrics.license_policy','lyrics.version as lyric_version',
                'files.relative_path','files.resolved_path','files.device_id','files.inode','files.file_size','files.modified_at',
                'libraries.name as library_name','libraries.resolved_root_path','libraries.version as library_version',
            ]);
        if (!$row instanceof stdClass) throw new LyricsAudioTagWritebackNotFound('歌曲或歌词不存在。');
        if (!in_array((string) $row->license_policy, ['local_controlled','redistributable'], true)) {
            throw new LyricsAudioTagWritebackInvalid('该歌词许可不允许写入音频文件。');
        }
        // 逐字歌词使用与 sidecar 相同的 Velin Enhanced LRC 严格 profile。具体的
        // 行/词时间边界和无损拼接条件由确定性序列化器复验，无法表示的结构
        // 会在创建方案时失败关闭，不会降级成逐行歌词。
        if (!in_array((string) $row->lyric_kind, ['plain','line','word'], true)) {
            throw new LyricsAudioTagWritebackInvalid('歌词同步类型不支持写入音频标签。');
        }
        return $row;
    }

    /**
     * 生成正文、文件身份、容器能力和现有标签摘要；返回正文仅存在于当前调用栈。
     *
     * @return array{0:string,1:array{device:int,inode:int,size:int,mtime:int,links:int},2:string,3:?string}
     */
    private function snapshot(stdClass $source): array
    {
        try {
            $parsed = $this->files->readById((string) $source->lyric_id);
            if ($parsed->kind !== (string) $source->lyric_kind) {
                throw new LyricsWritebackInvalid('歌词文件解析类型与索引不一致。');
            }
            $content = $this->serializer->serialize($parsed->kind, $parsed->lines);
        } catch (LyricsFileUnavailable|LyricsWritebackInvalid $error) {
            throw new LyricsAudioTagWritebackInvalid($error->getMessage(), previous: $error);
        }
        $configured = rtrim((string) $source->resolved_root_path, DIRECTORY_SEPARATOR);
        $root = realpath($configured);
        $audio = $configured . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $source->relative_path);
        clearstatcache(true, $audio);
        if ($root === false || $root !== $configured || realpath($audio) !== $audio
            || (string) $source->resolved_path !== $audio || is_link($audio) || !is_file($audio)
            || !is_readable($audio) || !is_writable($audio)) {
            throw new LyricsAudioTagWritebackConflict('音频路径、类型或写权限已变化。');
        }
        $stat = @stat($audio);
        if (!is_array($stat) || (int)$stat['dev'] !== (int)$source->device_id
            || (int)$stat['ino'] !== (int)$source->inode || (int)$stat['size'] !== (int)$source->file_size
            || (int)$stat['mtime'] !== (int)$source->modified_at) {
            throw new LyricsAudioTagWritebackConflict('音频文件身份已变化，请先扫描。');
        }
        $extension = strtolower(pathinfo($audio, PATHINFO_EXTENSION));
        $container = match ($extension) {
            'mp3' => 'mp3', 'flac' => 'flac', 'opus' => 'opus',
            'm4a', 'm4b', 'mp4' => 'mp4', default => throw new LyricsAudioTagWritebackInvalid('该音频容器暂不支持歌词标签写入。'),
        };
        try {
            $metadata = $this->probe->probe($audio, pathinfo($audio, PATHINFO_FILENAME));
        } catch (\Throwable $error) {
            throw new LyricsAudioTagWritebackConflict('无法读取当前音频标签。', previous: $error);
        }
        $existing = $this->existingLyricsDigest($metadata->rawTags);
        return [$content, ['device'=>(int)$stat['dev'],'inode'=>(int)$stat['ino'],'size'=>(int)$stat['size'],
            'mtime'=>(int)$stat['mtime'],'links'=>max(1,(int)($stat['nlink']??1))], $container, $existing];
    }

    /**
     * 对统一键及历史同步/非同步别名生成有序摘要。
     *
     * 摘要包含键名，能够区分相同正文位于不同标签的情况；正文只在当前调用栈进入 hash，不写方案或
     * 日志。多个 format/stream 层的重复键按稳定排序去重，确认阶段可准确发现外部标签变化。
     *
     * @param array<string,mixed> $rawTags
     */
    private function existingLyricsDigest(array $rawTags): ?string
    {
        $values=[];
        foreach($rawTags as$tags){if(!is_array($tags))continue;
            foreach(['lyrics','unsyncedlyrics','syncedlyrics']as$key){
                if(is_string($tags[$key]??null)&&$tags[$key]!=='')$values[$key]=$tags[$key];
            }}
        if($values===[])return null;ksort($values);
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    /** 方案自身不授予访问；每次读取都通过当前歌曲授权重新判定。 */
    private function plan(string $planId, array $actor): stdClass
    {
        $this->ulid($planId, '方案标识无效。');
        $row = Db::table('lyrics_audio_tag_writeback_plans as plans')
            ->join('media_songs as songs','songs.id','=','plans.song_id')
            ->join('music_libraries as libraries','libraries.id','=','plans.library_id')
            ->where('plans.id',$planId)->first(['plans.*','songs.title as song_title','libraries.name as library_name']);
        if (!$row instanceof stdClass) throw new LyricsAudioTagWritebackNotFound('歌词音频标签方案不存在。');
        try { (new MediaMetadataService())->song($actor, (string)$row->song_id); }
        catch (\Throwable $error) { throw new LyricsAudioTagWritebackNotFound('歌词音频标签方案不存在。', previous: $error); }
        return $row;
    }

    /**
     * 读取一个当前身份仍可访问全部目标库的批量方案。
     *
     * 每个目标继续通过单曲 `detail()` 执行对象级授权；任一目标失权时整个批次统一按不存在处理，
     * 防止调用者从部分列表和父计数推断其他音乐库信息。
     */
    private function batch(string$batchId,array$actor):stdClass
    {
        $this->ulid($batchId,'批量方案标识无效。');
        $batch=Db::table('lyrics_audio_tag_writeback_batch_plans')->where('id',$batchId)->first();
        if(!$batch instanceof stdClass)throw new LyricsAudioTagWritebackNotFound('批量歌词音频标签方案不存在。');
        $planIds=Db::table('lyrics_audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->orderBy('position')->pluck('writeback_plan_id')->all();
        if(count($planIds)!==(int)$batch->target_count)throw new LyricsAudioTagWritebackNotFound('批量歌词音频标签方案不存在。');
        foreach($planIds as$planId)$this->detail((string)$planId,$actor);
        return$batch;
    }

    /** @return array<string,mixed> 安全管理投影，不含正文、摘要、物理路径和文件身份。 */
    private function map(stdClass $plan, ?stdClass $job): array
    {
        return ['id'=>(string)$plan->id,'song'=>['id'=>(string)$plan->song_id,'title'=>(string)$plan->song_title],
            'lyricId'=>(string)$plan->lyric_id,'library'=>['id'=>(string)$plan->library_id,'name'=>(string)$plan->library_name],
            'source'=>(string)$plan->source_kind,'format'=>(string)$plan->source_format,
            'language'=>(string)$plan->language,'kind'=>(string)$plan->lyric_kind,
            'licensePolicy'=>(string)$plan->license_policy,'container'=>(string)$plan->container_key,
            'tagKey'=>(string)$plan->tag_key,'sourcePreview'=>(string)$plan->source_relative_path,
            'fileSizeBytes'=>(int)$plan->source_file_size,'linkCount'=>(int)$plan->source_link_count,
            'replacesExistingTag'=>$plan->existing_tag_sha256!==null,'detachesHardlink'=>(int)$plan->source_link_count>1,
            'outputSizeBytes'=>(int)$plan->output_size_bytes,
            'risks'=>['temporaryFullFileBackup'=>true,'backupRemovedAfterCommit'=>true,'fullyReversible'=>false,
                'audioReencoded'=>false,'requiresIncrementalScan'=>true],
            'status'=>(string)$plan->status,'planHash'=>(string)$plan->plan_hash,'version'=>(int)$plan->version,
            'expiresAt'=>(string)$plan->expires_at,'confirmation'=>self::CONFIRMATION_TEXT,
            'confirmable'=>(string)$plan->status==='planned'&&(string)$plan->expires_at>gmdate('Y-m-d\TH:i:s\Z'),
            'job'=>$job===null?null:['id'=>(string)$job->id,'status'=>(string)$job->status,'phase'=>(string)$job->phase,
                'attempt'=>(int)$job->attempt,'errorCode'=>$job->error_code===null?null:(string)$job->error_code,
                'createdAt'=>(string)$job->created_at,'startedAt'=>$job->started_at===null?null:(string)$job->started_at,
                'finishedAt'=>$job->finished_at===null?null:(string)$job->finished_at],
            'createdAt'=>(string)$plan->created_at,'finishedAt'=>$plan->finished_at===null?null:(string)$plan->finished_at];
    }

    /** 重放相同方案的同一幂等意图，跨方案复用固定冲突。 */
    private function replay(string $actorId, string $hash, string $planId): ?array
    {
        $row = Db::table('lyrics_audio_tag_writeback_jobs')->where('requested_by',$actorId)
            ->where('idempotency_key_sha256',$hash)->first(['id','plan_id','status','phase','created_at']);
        if (!$row instanceof stdClass) return null;
        if ((string)$row->plan_id !== $planId) throw new LyricsAudioTagWritebackConflict('幂等键已用于另一方案。');
        return ['id'=>(string)$row->id,'planId'=>(string)$row->plan_id,'status'=>(string)$row->status,
            'phase'=>(string)$row->phase,'createdAt'=>(string)$row->created_at];
    }

    /** 拒绝畸形对象标识，防止查询条件缺失导致越权。 */
    private function ulid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/',$value)!==1) throw new LyricsAudioTagWritebackInvalid($message);
    }
}
