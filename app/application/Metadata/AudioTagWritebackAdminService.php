<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Scrape\ScrapeMetadataCandidate;
use app\application\User\HighCostJobPolicy;
use app\infrastructure\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use JsonException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use support\Db;

/**
 * 编排音频描述标签写回的不可变 Dry Run、强确认和任务查询。
 *
 * Controller 负责全局 `edit_metadata`，本服务仍复用歌曲元数据服务的全部库 manage 授权。浏览器只能
 * 提交字段版本、方案版本、摘要和幂等键，不能提交路径、FFmpeg 参数或任意标签键。预览读取文件 stat
 * 但不写文件；确认只排 Durable Job，真正重封装由单消费者 Worker 完成。
 */
final class AudioTagWritebackAdminService
{
    public const CONFIRMATION_TEXT = 'WRITE AUDIO TAGS';
    private const PLAN_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly HighCostJobPolicy $highCostJobs = new HighCostJobPolicy(),
    ) {
    }

    /**
     * 冻结当前歌曲有效字段和文件身份，生成 24 小时有效且无写盘副作用的方案。
     *
     * expectedVersions 必须覆盖详情返回的全部字段且完全匹配，避免页面遗漏某个并发更新。方案保存
     * 白名单候选和安全相对位置，不保存物理根；硬链接数来自当前 stat 并作为执行前复验条件。
     *
     * @param array<string,int> $expectedVersions
     * @return array<string,mixed>
     */
    public function create(string $songId, array $expectedVersions, array $actor, string $requestId): array
    {
        $this->ulid($songId, '歌曲标识无效。');
        $detail = (new MediaMetadataService())->song($actor, $songId);
        $states = array_column($detail['fields'], null, 'field');
        $currentVersions = [];
        foreach ($states as $field => $state) {
            $currentVersions[$field] = (int) $state['version'];
        }
        ksort($currentVersions);
        ksort($expectedVersions);
        if ($expectedVersions !== $currentVersions) {
            throw new AudioTagWritebackConflict('字段版本已变化，请刷新后重新预览。');
        }

        $source = $this->source($songId);
        $identity = $this->preflight($source);
        $candidate = $this->candidate($states, (int) $source->duration_ms);
        $metadataJson = $candidate->toJson();
        $planId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + self::PLAN_TTL_SECONDS);
        $fingerprint = [
            'planId' => $planId, 'songId' => $songId, 'libraryId' => (string) $source->library_id,
            'inventoryFileId' => (string) $source->inventory_file_id,
            'songUpdatedAt' => (string) $source->song_updated_at, 'libraryVersion' => (int) $source->library_version,
            'fieldVersions' => $currentVersions, 'metadataSha256' => hash('sha256', $metadataJson),
            'sourceRelativePath' => (string) $source->relative_path, 'identity' => $identity, 'expiresAt' => $expiresAt,
        ];
        $planHash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        Db::transaction(function () use ($actor, $currentVersions, $expiresAt, $identity, $metadataJson, $now,
            $planHash, $planId, $requestId, $songId, $source): void {
            Db::table('audio_tag_writeback_plans')->insert([
                'id' => $planId, 'song_id' => $songId, 'library_id' => (string) $source->library_id,
                'inventory_file_id' => (string) $source->inventory_file_id, 'created_by' => (string) $actor['id'],
                'status' => 'planned', 'expected_song_updated_at' => (string) $source->song_updated_at,
                'expected_library_version' => (int) $source->library_version,
                'field_versions_json' => $this->json($currentVersions), 'metadata_snapshot_json' => $metadataJson,
                'metadata_sha256' => hash('sha256', $metadataJson), 'source_relative_path' => (string) $source->relative_path,
                'source_device' => $identity['device'], 'source_inode' => $identity['inode'],
                'source_file_size' => $identity['size'], 'source_modified_at' => $identity['mtime'],
                'source_link_count' => $identity['links'], 'plan_hash' => $planHash, 'version' => 1,
                'expires_at' => $expiresAt, 'confirmed_at' => null, 'finished_at' => null, 'error_code' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record((string) $actor['id'], 'metadata.audio_tags.plan.create',
                'audio_tag_writeback_plan', $planId, 'success', $requestId,
                ['songId' => $songId, 'libraryId' => (string) $source->library_id,
                    'fieldCount' => count($currentVersions), 'detachesHardlink' => $identity['links'] > 1]);
        });
        return $this->detail($planId, $actor);
    }

    /** 返回实时授权后的方案、风险和任务状态，不读取文件或暴露物理根。 */
    public function detail(string $planId, array $actor): array
    {
        $plan = $this->plan($planId, $actor);
        $job = Db::table('audio_tag_writeback_jobs')->where('plan_id', $planId)->first([
            'id','status','phase','attempt','error_code','created_at','started_at','finished_at',
        ]);
        return $this->map($plan, $job instanceof stdClass ? $job : null);
    }

    /**
     * 为最多 50 首明确歌曲创建批量描述标签预览。
     *
     * 浏览器只表达歌曲选择，完整字段版本由服务端当前详情生成。外层事务包含全部单曲方案、父方案和
     * 审计；任一授权、字段或文件预检失败时整批回滚，不留下孤儿方案或缩小用户确认范围。
     *
     * @param list<string> $songIds
     * @param array<string,mixed> $actor
     */
    public function createBatch(array$songIds,array$actor,string$requestId):array
    {
        if($songIds===[]||count($songIds)>50||!array_is_list($songIds))
            throw new AudioTagWritebackInvalid('批量写回目标必须为 1 到 50 首歌曲。');
        $seen=[];foreach($songIds as$id){$this->ulid(is_string($id)?$id:'','歌曲标识无效。');
            if(isset($seen[$id]))throw new AudioTagWritebackInvalid('同一歌曲不能重复写入。');$seen[$id]=true;}
        $batchId=(string)new Ulid();$plans=[];
        Db::transaction(function()use($actor,$batchId,$requestId,$songIds,&$plans):void{
            foreach($songIds as$songId){$detail=(new MediaMetadataService())->song($actor,$songId);$versions=[];
                foreach($detail['fields']as$field)$versions[(string)$field['field']]=(int)$field['version'];
                $plans[]=$this->create($songId,$versions,$actor,$requestId);}
            $expiresAt=min(array_column($plans,'expiresAt'));
            $hash=hash('sha256',json_encode(['batchId'=>$batchId,'expiresAt'=>$expiresAt,'targets'=>array_map(
                static fn(array$plan,int$position):array=>['position'=>$position,'planId'=>$plan['id'],
                    'planHash'=>$plan['planHash']],$plans,array_keys($plans))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $now=gmdate('Y-m-d\TH:i:s\Z');
            Db::table('audio_tag_writeback_batch_plans')->insert(['id'=>$batchId,'requested_by'=>(string)$actor['id'],
                'request_id'=>$requestId,'plan_hash'=>$hash,'idempotency_key_sha256'=>null,'target_count'=>count($plans),
                'processed_count'=>0,'succeeded_count'=>0,'failed_count'=>0,'status'=>'draft','version'=>1,
                'expires_at'=>$expiresAt,'confirmed_at'=>null,'started_at'=>null,'finished_at'=>null,
                'created_at'=>$now,'updated_at'=>$now]);
            foreach($plans as$position=>$plan)Db::table('audio_tag_writeback_batch_targets')->insert([
                'batch_plan_id'=>$batchId,'position'=>$position,'writeback_plan_id'=>$plan['id'],
                'song_id'=>$plan['song']['id'],'library_id'=>$plan['library']['id'],'status'=>'planned',
                'error_code'=>null,'updated_at'=>$now]);
            $this->audit->record((string)$actor['id'],'metadata.audio_tags.batch.create',
                'audio_tag_writeback_batch_plan',$batchId,'success',$requestId,['targetCount'=>count($plans)]);
        });
        return$this->batchDetail($batchId,$actor);
    }

    /**
     * 从终态批次的失败目标创建全新的待确认方案。
     *
     * 本方法先重新授权并从子任务事实收敛父状态，只接受 partial/failed 且至少有一个 failed/stale
     * 目标。新方案重新读取当前完整字段版本与文件身份；旧批次、旧任务和操作日志保持不可变。任一
     * 目标当前已失权或文件预检失败时，新方案整批不创建。
     *
     * @return array<string,mixed>
     */
    public function retryBatchFailures(string $batchId, array $actor, string $requestId): array
    {
        $current = $this->batchDetail($batchId, $actor);
        if (!in_array($current['status'], ['partial', 'failed'], true) || $current['failedCount'] < 1) {
            throw new AudioTagWritebackConflict('当前批次没有可重试的失败目标。');
        }
        $songIds = [];
        foreach ($current['targets'] as $target) {
            if (in_array($target['status'], ['failed', 'stale'], true)) {
                $songIds[] = (string) $target['plan']['song']['id'];
            }
        }
        if ($songIds === []) throw new AudioTagWritebackConflict('当前批次没有可重试的失败目标。');

        $retry = $this->createBatch($songIds, $actor, $requestId);
        $this->audit->record((string) $actor['id'], 'metadata.audio_tags.batch.retry_failures',
            'audio_tag_writeback_batch_plan', (string) $retry['id'], 'success', $requestId,
            ['sourceBatchId' => $batchId, 'targetCount' => count($songIds)]);

        return $retry;
    }

    /** 返回实时完整授权并从子任务事实重建的批量安全投影。 */
    public function batchDetail(string$batchId,array$actor):array
    {
        $batch=$this->batch($batchId,$actor);if((string)$batch->status!=='draft'){
            (new AudioTagWritebackBatchStateService())->synchronize($batchId);$batch=$this->batch($batchId,$actor);}
        $rows=Db::table('audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->orderBy('position')->get(['position','writeback_plan_id','status','error_code'])->all();$targets=[];
        foreach($rows as$row)$targets[]=['position'=>(int)$row->position,'status'=>(string)$row->status,
            'errorCode'=>$row->error_code===null?null:(string)$row->error_code,
            'plan'=>$this->detail((string)$row->writeback_plan_id,$actor)];
        return['id'=>(string)$batch->id,'status'=>(string)$batch->status,'version'=>(int)$batch->version,
            'planHash'=>(string)$batch->plan_hash,'targetCount'=>(int)$batch->target_count,
            'processedCount'=>(int)$batch->processed_count,'succeededCount'=>(int)$batch->succeeded_count,
            'failedCount'=>(int)$batch->failed_count,'expiresAt'=>(string)$batch->expires_at,
            'confirmation'=>self::CONFIRMATION_TEXT,'confirmable'=>(string)$batch->status==='draft'
                &&(string)$batch->expires_at>gmdate('Y-m-d\TH:i:s\Z'),'targets'=>$targets,
            'createdAt'=>(string)$batch->created_at,'confirmedAt'=>$batch->confirmed_at===null?null:(string)$batch->confirmed_at,
            'startedAt'=>$batch->started_at===null?null:(string)$batch->started_at,
            'finishedAt'=>$batch->finished_at===null?null:(string)$batch->finished_at];
    }

    /** 强确认批次并在短事务中原子排队全部既有单曲任务。 */
    public function confirmBatch(string$batchId,int$version,string$planHash,string$confirmation,string$idempotencyKey,
        array$actor,string$requestId):array
    {
        $this->ulid($batchId,'批量方案标识无效。');
        if($version<1||preg_match('/^[a-f0-9]{64}$/',$planHash)!==1||$confirmation!==self::CONFIRMATION_TEXT
            ||strlen($idempotencyKey)<16||strlen($idempotencyKey)>128||preg_match('/^[\x21-\x7E]+$/',$idempotencyKey)!==1)
            throw new AudioTagWritebackInvalid('批量方案确认材料无效。');
        $keyHash=hash('sha256',$idempotencyKey);$batch=$this->batch($batchId,$actor);
        if($batch->idempotency_key_sha256!==null){if((string)$batch->requested_by===(string)$actor['id']
            &&hash_equals((string)$batch->idempotency_key_sha256,$keyHash))return$this->batchDetail($batchId,$actor);
            throw new AudioTagWritebackConflict('批量方案已经确认或幂等键不匹配。');}
        if((string)$batch->status!=='draft'||(int)$batch->version!==$version
            ||!hash_equals((string)$batch->plan_hash,$planHash)||(string)$batch->expires_at<=gmdate('Y-m-d\TH:i:s\Z'))
            throw new AudioTagWritebackConflict('批量方案已变化或过期，请重新预览。');
        $rows=Db::table('audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->where('status','planned')->orderBy('position')->get(['writeback_plan_id'])->all();
        if(count($rows)!==(int)$batch->target_count)throw new AudioTagWritebackConflict('批量目标已变化。');
        foreach($rows as$row)if(!$this->detail((string)$row->writeback_plan_id,$actor)['confirmable'])
            throw new AudioTagWritebackConflict('批量中的单曲方案已变化。');
        try{Db::transaction(function()use($actor,$batch,$batchId,$idempotencyKey,$keyHash,$requestId,$rows,$version):void{
            $this->highCostJobs->assertCanQueue((string)$actor['id'],count($rows));$now=gmdate('Y-m-d\TH:i:s\Z');
            if(Db::table('audio_tag_writeback_batch_plans')->where('id',$batchId)->where('status','draft')
                ->where('version',$version)->whereNull('idempotency_key_sha256')->update(['status'=>'queued',
                    'idempotency_key_sha256'=>$keyHash,'version'=>Db::raw('version + 1'),'confirmed_at'=>$now,'updated_at'=>$now])!==1)
                throw new AudioTagWritebackConflict('批量方案已变化。');
            foreach($rows as$row){$plan=$this->detail((string)$row->writeback_plan_id,$actor);
                $this->confirm($plan['id'],$plan['version'],$plan['planHash'],self::CONFIRMATION_TEXT,
                    hash_hmac('sha256',(string)$row->writeback_plan_id,$idempotencyKey),$actor,$requestId);
                Db::table('audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
                    ->where('writeback_plan_id',(string)$row->writeback_plan_id)->where('status','planned')
                    ->update(['status'=>'queued','updated_at'=>$now]);}
            $this->audit->record((string)$actor['id'],'metadata.audio_tags.batch.confirm',
                'audio_tag_writeback_batch_plan',$batchId,'success',$requestId,
                ['targetCount'=>(int)$batch->target_count,'planVersion'=>$version]);
        });}catch(QueryException$error){$current=$this->batch($batchId,$actor);
            if($current->idempotency_key_sha256!==null&&hash_equals((string)$current->idempotency_key_sha256,$keyHash))
                return$this->batchDetail($batchId,$actor);
            if(str_contains(strtolower($error->getMessage()),'unique'))
                throw new AudioTagWritebackConflict('批量方案已有任务或幂等键已占用。',previous:$error);throw$error;}
        return$this->batchDetail($batchId,$actor);
    }

    /**
     * 强确认仍为当前版本的方案并幂等排队。
     *
     * 确认前再次复验字段版本、库版本、歌曲时间和文件身份。事务只写方案、任务和审计，不运行 FFmpeg；
     * 同一操作者的原始幂等键只保留 SHA-256，重复请求返回首次任务。
     */
    public function confirm(string $planId, int $version, string $planHash, string $confirmation,
        string $idempotencyKey, array $actor, string $requestId): array
    {
        $this->ulid($planId, '方案标识无效。');
        if ($version < 1 || preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1
            || $confirmation !== self::CONFIRMATION_TEXT
            || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128
            || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1) {
            throw new AudioTagWritebackInvalid('方案确认材料无效。');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $replay = $this->replay((string) $actor['id'], $keyHash, $planId);
        if ($replay !== null) {
            return $replay;
        }
        $plan = $this->plan($planId, $actor);
        if ((string) $plan->status !== 'planned' || (int) $plan->version !== $version
            || !hash_equals((string) $plan->plan_hash, $planHash)
            || (string) $plan->expires_at <= gmdate('Y-m-d\TH:i:s\Z')) {
            throw new AudioTagWritebackConflict('方案已变化或过期，请重新预览。');
        }
        $this->assertCurrent($plan, $actor);
        $jobId = (string) new Ulid();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            Db::transaction(function () use ($actor, $jobId, $keyHash, $now, $plan, $planId, $requestId, $version): void {
                $this->highCostJobs->assertCanQueue((string) $actor['id']);
                $changed = Db::table('audio_tag_writeback_plans')->where('id', $planId)
                    ->where('status', 'planned')->where('version', $version)->update([
                        'status' => 'queued', 'version' => Db::raw('version + 1'),
                        'confirmed_at' => $now, 'updated_at' => $now,
                    ]);
                if ($changed !== 1) {
                    throw new AudioTagWritebackConflict('方案已变化，请重新预览。');
                }
                Db::table('audio_tag_writeback_jobs')->insert([
                    'id' => $jobId, 'plan_id' => $planId, 'library_id' => (string) $plan->library_id,
                    'song_id' => (string) $plan->song_id, 'requested_by' => (string) $actor['id'],
                    'request_id' => $requestId, 'idempotency_key_sha256' => $keyHash,
                    'status' => 'queued', 'phase' => 'queued', 'attempt' => 0, 'worker_id' => null,
                    'heartbeat_at' => null, 'started_at' => null, 'finished_at' => null, 'error_code' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record((string) $actor['id'], 'metadata.audio_tags.plan.confirm',
                    'audio_tag_writeback_plan', $planId, 'success', $requestId,
                    ['jobId' => $jobId, 'songId' => (string) $plan->song_id, 'libraryId' => (string) $plan->library_id]);
            });
        } catch (QueryException $error) {
            $replay = $this->replay((string) $actor['id'], $keyHash, $planId);
            if ($replay !== null) {
                return $replay;
            }
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new AudioTagWritebackConflict('方案已有任务或幂等键已占用。', previous: $error);
            }
            throw $error;
        }
        return ['id' => $jobId, 'planId' => $planId, 'status' => 'queued', 'phase' => 'queued', 'createdAt' => $now];
    }

    /**
     * 重新读取字段、库和音频身份，确认阶段不信任冻结事实本身。
     *
     * 方案中的版本与 stat 只代表预览时事实；确认与预览之间可能发生元数据编辑、库配置变化或文件
     * 原地替换，因此这里必须再次通过实时授权读取字段，并逐项比较冻结身份。任一差异均使整个方案
     * 失效，调用方只能重新创建方案，不能在旧快照上局部修补或继续排队。
     */
    private function assertCurrent(stdClass $plan, array $actor): void
    {
        $detail = (new MediaMetadataService())->song($actor, (string) $plan->song_id);
        $versions = [];
        foreach ($detail['fields'] as $field) {
            $versions[$field['field']] = (int) $field['version'];
        }
        ksort($versions);
        try {
            $expected = json_decode((string) $plan->field_versions_json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AudioTagWritebackConflict('方案字段快照损坏。', previous: $error);
        }
        if ($versions !== $expected) {
            throw new AudioTagWritebackConflict('字段版本已变化，请重新预览。');
        }
        $source = $this->source((string) $plan->song_id);
        $identity = $this->preflight($source);
        if ((string) $source->song_updated_at !== (string) $plan->expected_song_updated_at
            || (int) $source->library_version !== (int) $plan->expected_library_version
            || $identity !== [
                'device' => (int) $plan->source_device,
                'inode' => (int) $plan->source_inode,
                'size' => (int) $plan->source_file_size,
                'mtime' => (int) $plan->source_modified_at,
                'links' => (int) $plan->source_link_count,
            ]) {
            throw new AudioTagWritebackConflict('歌曲、音乐库或文件身份已变化，请重新预览。');
        }
    }

    /**
     * 把管理端当前有效字段转换成写入器唯一接受的白名单候选。
     *
     * 原始标签与覆盖层的差异只用于页面说明，不会允许浏览器注入任意 FFmpeg 标签键。缺失值保持空值，
     * 由统一写入器决定是否省略；本方法不读取文件、不访问第三方渠道，也不产生任何写盘副作用。
     *
     * @param array<string,array<string,mixed>> $states
     */
    private function candidate(array $states, int $durationMs): ScrapeMetadataCandidate
    {
        $v = static fn (string $field): mixed => $states[$field]['effective'] ?? null;
        $raw = static fn (string $field): mixed => $states[$field]['raw'] ?? null;
        $external = is_array($v('externalIds')) ? $v('externalIds') : [];
        $contributors = is_array($v('contributors')) ? $v('contributors') : [];
        $metadata = [
            'title' => (string) $v('title'),
            'artists' => (array) $v('artists'),
            'albumArtists' => (array) $v('albumArtists'),
            'albumTitle' => (string) $v('album'),
            'trackNumber' => $v('trackNumber'),
            'trackTotal' => $v('trackTotal'),
            'discNumber' => $v('discNumber'),
            'discTotal' => $v('discTotal'),
            'genres' => (array) $v('genres'),
            'releaseDate' => $v('releaseDate'),
            'releaseYear' => is_string($v('releaseDate')) ? (int) substr($v('releaseDate'), 0, 4) : null,
            'composer' => $contributors[0] ?? null,
            'isrc' => $external['isrc'] ?? null,
            'musicbrainzTrackId' => $external['musicbrainzTrackId'] ?? null,
            'musicbrainzArtistId' => $external['musicbrainzArtistId'] ?? null,
            'musicbrainzReleaseId' => $external['musicbrainzReleaseId'] ?? null,
            'musicbrainzReleaseGroupId' => $external['musicbrainzReleaseGroupId'] ?? null,
            'durationMs' => $durationMs,
        ];
        $evidence = ['admin_effective_metadata'];
        foreach (['title', 'artists', 'albumArtists', 'album', 'trackNumber', 'trackTotal', 'discNumber', 'discTotal',
            'releaseDate', 'genres', 'contributors', 'externalIds'] as $field) {
            if ($raw($field) !== $v($field)) {
                $evidence[] = 'changed:' . $field;
            }
        }
        return new ScrapeMetadataCandidate($metadata, 100, 'admin_metadata', $evidence);
    }

    /** 读取可用歌曲、库存和活动库的文件事实；授权已由同次调用的 MediaMetadataService 完成。 */
    private function source(string $songId): stdClass
    {
        $row = Db::table('media_songs as songs')->join('library_file_inventory as files','files.id','=','songs.inventory_file_id')
            ->join('music_libraries as libraries','libraries.id','=','songs.library_id')->where('songs.id',$songId)
            ->where('files.status','available')->where('files.metadata_status','ready')->where('libraries.status','active')
            ->where('libraries.source_type','local')
            ->first(['songs.id','songs.library_id','songs.inventory_file_id','songs.duration_ms','songs.updated_at as song_updated_at',
                'files.relative_path','files.resolved_path','files.device_id','files.inode','files.file_size','files.modified_at',
                'libraries.name as library_name','libraries.resolved_root_path','libraries.version as library_version']);
        if (!$row instanceof stdClass) throw new AudioTagWritebackNotFound('歌曲不存在或不可写回。');
        return $row;
    }

    /**
     * 在创建或确认方案时验证最终库音频仍是受管理的普通可写文件。
     *
     * 配置根必须已经规范化且真实路径不能经过符号链接；文件路径、库存解析路径和实时 stat 必须一致。
     * 长驻进程会缓存 stat，因此读取前显式清缓存。失败时只报告稳定冲突，不把物理路径或 inode 暴露给
     * 客户端；本方法只读文件系统，不修复库存，也不触发扫描。
     *
     * @return array{device:int,inode:int,size:int,mtime:int,links:int}
     */
    private function preflight(stdClass $source): array
    {
        $root = realpath((string) $source->resolved_root_path);
        $configured = rtrim((string) $source->resolved_root_path, DIRECTORY_SEPARATOR);
        $audio = $configured . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, (string) $source->relative_path);
        // 长驻 Webman 进程可能缓存同 inode 的旧 stat；每次方案预检必须读取当前内核事实。
        clearstatcache(true, $audio);
        if ($root === false || $root !== $configured || realpath($audio) !== $audio
            || (string) $source->resolved_path !== $audio || is_link($audio) || !is_file($audio) || !is_writable($audio)) {
            throw new AudioTagWritebackConflict('音频路径、类型或写权限已变化。');
        }
        $stat = @stat($audio);
        if (!is_array($stat)
            || (int) $stat['dev'] !== (int) $source->device_id
            || (int) $stat['ino'] !== (int) $source->inode
            || (int) $stat['size'] !== (int) $source->file_size
            || (int) $stat['mtime'] !== (int) $source->modified_at) {
            throw new AudioTagWritebackConflict('音频文件身份已变化，请先扫描。');
        }
        return [
            'device' => (int) $stat['dev'],
            'inode' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'links' => max(1, (int) ($stat['nlink'] ?? 1)),
        ];
    }

    /**
     * 按方案读取展示事实，并通过歌曲服务重新执行对象级管理授权。
     *
     * 方案自身不能充当授权凭据；即使操作者创建过方案，库授权被撤销后也必须返回不可访问。查询不返回
     * 物理根或库存身份，且不会延长方案有效期。
     */
    private function plan(string $planId, array $actor): stdClass
    {
        $this->ulid($planId, '方案标识无效。');
        $row = Db::table('audio_tag_writeback_plans as plans')->join('media_songs as songs','songs.id','=','plans.song_id')
            ->join('music_libraries as libraries','libraries.id','=','plans.library_id')->where('plans.id',$planId)
            ->first(['plans.*','songs.title as song_title','libraries.name as library_name']);
        if (!$row instanceof stdClass) {
            throw new AudioTagWritebackNotFound('标签写回方案不存在。');
        }
        // 复用歌曲详情执行完整 manage 授权；返回值不使用，避免方案记录自行授予对象访问。
        (new MediaMetadataService())->song($actor, (string) $row->song_id);
        return $row;
    }

    /** 任一目标库失权时整个批次按不存在处理，避免部分计数泄露其他库信息。 */
    private function batch(string$batchId,array$actor):stdClass
    {
        $this->ulid($batchId,'批量方案标识无效。');
        $batch=Db::table('audio_tag_writeback_batch_plans')->where('id',$batchId)->first();
        if(!$batch instanceof stdClass)throw new AudioTagWritebackNotFound('批量音频标签方案不存在。');
        $ids=Db::table('audio_tag_writeback_batch_targets')->where('batch_plan_id',$batchId)
            ->orderBy('position')->pluck('writeback_plan_id')->all();
        if(count($ids)!==(int)$batch->target_count)throw new AudioTagWritebackNotFound('批量音频标签方案不存在。');
        foreach($ids as$id)$this->detail((string)$id,$actor);return$batch;
    }

    /**
     * 把数据库方案映射成不含敏感文件事实的管理端响应。
     *
     * 响应只提供库内相对位置和用户理解风险所需的链接数；设备号、inode、摘要和备份名始终留在服务端。
     * 损坏的不可变快照按冲突失败关闭，不能回退到当前数据库值冒充原方案。
     *
     * @return array<string,mixed>
     */
    private function map(stdClass $plan, ?stdClass $job): array
    {
        try {
            $candidate = ScrapeMetadataCandidate::fromJson((string) $plan->metadata_snapshot_json);
        } catch (\Throwable $error) {
            throw new AudioTagWritebackConflict('方案元数据快照损坏。', previous: $error);
        }
        $changed = array_values(array_map(
            static fn (string $item): string => substr($item, 8),
            array_filter($candidate->evidence, static fn (string $item): bool => str_starts_with($item, 'changed:')),
        ));
        return ['id'=>(string)$plan->id,'song'=>['id'=>(string)$plan->song_id,'title'=>(string)$plan->song_title],
            'library'=>['id'=>(string)$plan->library_id,'name'=>(string)$plan->library_name],
            'sourcePreview'=>(string)$plan->source_relative_path,'fileSizeBytes'=>(int)$plan->source_file_size,
            'linkCount'=>(int)$plan->source_link_count,'detachesHardlink'=>(int)$plan->source_link_count>1,
            'fields'=>$candidate->metadata,'changedFields'=>$changed,
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

    /**
     * 在并发确认或网络重试时读取原任务，保证同一操作者的幂等键只表达一个方案。
     *
     * 原始幂等键不会落库；调用方复用键到另一方案时必须冲突，不能泄露另一任务或创建第二次写盘操作。
     *
     * @return array<string,mixed>|null
     */
    private function replay(string $actorId, string $hash, string $planId): ?array
    {
        $row = Db::table('audio_tag_writeback_jobs')->where('requested_by', $actorId)
            ->where('idempotency_key_sha256', $hash)->first(['id', 'plan_id', 'status', 'phase', 'created_at']);
        if (!$row instanceof stdClass) {
            return null;
        }
        if ((string) $row->plan_id !== $planId) {
            throw new AudioTagWritebackConflict('幂等键已用于另一方案。');
        }
        return [
            'id' => (string) $row->id,
            'planId' => (string) $row->plan_id,
            'status' => (string) $row->status,
            'phase' => (string) $row->phase,
            'createdAt' => (string) $row->created_at,
        ];
    }

    /** 把仅含受控值的快照编码为稳定 JSON；编码错误直接中止事务，不保存不完整方案。 */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 验证外部对象标识为规范 ULID；错误消息由调用点提供且不得包含原始输入。 */
    private function ulid(string $value, string $message): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new AudioTagWritebackInvalid($message);
        }
    }
}
