<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Lyrics\LyricsAudioTagWritebackAdminService;
use app\application\Lyrics\LyricsAudioTagWritebackConflict;
use app\application\Lyrics\LyricsAudioTagWritebackInvalid;
use app\application\Lyrics\LyricsAudioTagWritebackNotFound;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露歌词写入音频容器标签的独立 Dry Run、详情和强确认 API。
 *
 * Controller 先校验 `edit_metadata`，应用服务再按歌曲目标库实时 manage grant 授权。HTTP 只能创建
 * 方案或 Durable Job，不运行 FFmpeg、不修改音频、不触发扫描，也不接受正文、路径或标签键。
 */
final class LyricsAudioTagWritebackController
{
    /** 创建最多 50 首且歌曲不重复的批量不可变预览。 */
    public function createBatch(Request$request):Response
    {
        $requestId=RequestContext::requestId();try{
            $actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['targets']);
            if(!is_array($payload['targets']??null)||!array_is_list($payload['targets']))
                throw new LyricsAudioTagWritebackInvalid('批量目标无效。');
            $targets=[];foreach($payload['targets']as$target){if(!is_array($target))
                throw new LyricsAudioTagWritebackInvalid('批量目标结构无效。');
                $keys=array_keys($target);sort($keys);
                if($keys!==['expectedLyricVersion','lyricId','songId']||!is_string($target['songId']??null)
                    ||!is_string($target['lyricId']??null))throw new LyricsAudioTagWritebackInvalid('批量目标结构无效。');
                $targets[]=['songId'=>$target['songId'],'lyricId'=>$target['lyricId'],
                    'expectedLyricVersion'=>$this->positive($target['expectedLyricVersion']??null,'歌词版本无效。')];}
            $batch=(new LyricsAudioTagWritebackAdminService())->createBatch($targets,$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],201,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /** 返回重新授权并从单曲任务事实汇总的批量投影。 */
    public function showBatch(Request$request,string$batchId):Response
    {
        $requestId=RequestContext::requestId();try{
            $actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $batch=(new LyricsAudioTagWritebackAdminService())->batchDetail($batchId,$actor);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],200,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /** 一次强确认并原子提交全部单曲 Durable 子任务。 */
    public function confirmBatch(Request$request,string$batchId):Response
    {
        $requestId=RequestContext::requestId();try{
            $actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['confirmation','expectedPlanVersion','planHash']);
            if(!is_string($payload['confirmation']??null)||!is_string($payload['planHash']??null))
                throw new LyricsAudioTagWritebackInvalid('批量方案确认材料无效。');
            $key=$request->header('Idempotency-Key');if(!is_string($key))
                throw new LyricsAudioTagWritebackInvalid('缺少 Idempotency-Key。');
            $batch=(new LyricsAudioTagWritebackAdminService())->confirmBatch($batchId,
                $this->positive($payload['expectedPlanVersion']??null,'批量方案版本无效。'),
                $payload['planHash'],$payload['confirmation'],$key,$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],202,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /** 从服务端当前歌词与音频事实生成 24 小时不可变预览。 */
    public function create(Request $request, string $songId): Response
    {
        $requestId=RequestContext::requestId();
        try{
            $actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['lyricId','expectedLyricVersion']);
            if(!is_string($payload['lyricId']??null))throw new LyricsAudioTagWritebackInvalid('歌词标识无效。');
            $plan=(new LyricsAudioTagWritebackAdminService())->create($songId,$payload['lyricId'],
                $this->positive($payload['expectedLyricVersion']??null,'歌词版本无效。'),$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['plan'=>$plan],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],201,$requestId);
        }catch(Throwable $error){return $this->failure($error,$requestId);}
    }

    /** 返回实时授权后的无正文方案投影。 */
    public function show(Request $request,string $planId): Response
    {
        $requestId=RequestContext::requestId();
        try{$actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $plan=(new LyricsAudioTagWritebackAdminService())->detail($planId,$actor);
            return JsonResponseFactory::create(['data'=>['plan'=>$plan],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],200,$requestId);
        }catch(Throwable $error){return $this->failure($error,$requestId);}
    }

    /** 强确认并排队；原始幂等键只在请求内存存在。 */
    public function confirm(Request $request,string $planId): Response
    {
        $requestId=RequestContext::requestId();
        try{$actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['confirmation','expectedPlanVersion','planHash']);
            if(!is_string($payload['confirmation']??null)||!is_string($payload['planHash']??null))
                throw new LyricsAudioTagWritebackInvalid('方案确认材料无效。');
            $key=$request->header('Idempotency-Key');
            if(!is_string($key))throw new LyricsAudioTagWritebackInvalid('缺少 Idempotency-Key。');
            $job=(new LyricsAudioTagWritebackAdminService())->confirm($planId,
                $this->positive($payload['expectedPlanVersion']??null,'方案版本无效。'),$payload['planHash'],
                $payload['confirmation'],$key,$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['job'=>$job],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],202,$requestId);
        }catch(Throwable $error){return $this->failure($error,$requestId);}
    }

    /** 只接受精确字段集合，未来新增输入不会被旧服务静默接受。 */
    private function payload(Request $request,array $allowed): array
    {
        $payload=$request->post();if(!is_array($payload))throw new LyricsAudioTagWritebackInvalid('请求内容无效。');
        $keys=array_keys($payload);sort($keys);sort($allowed);
        if($keys!==$allowed)throw new LyricsAudioTagWritebackInvalid('请求包含未知或缺失字段。');
        return$payload;
    }

    /** 只接受正整数或规范十进制字符串。 */
    private function positive(mixed $value,string $message): int
    {
        if(is_int($value)&&$value>0)return$value;
        if(is_string($value)&&preg_match('/^[1-9][0-9]{0,9}$/',$value)===1)return(int)$value;
        throw new LyricsAudioTagWritebackInvalid($message);
    }

    /** 领域异常映射为稳定 HTTP 语义，未知异常日志不带正文或路径。 */
    private function failure(Throwable $error,string $requestId): Response
    {
        if($error instanceof AuthenticationRequired)return JsonResponseFactory::error('AUTHENTICATION_REQUIRED','请先登录。',401,$requestId);
        if($error instanceof AuthorizationDenied)return JsonResponseFactory::error('PERMISSION_DENIED','没有编辑该音乐库元数据的权限。',403,$requestId);
        if($error instanceof LyricsAudioTagWritebackInvalid)return JsonResponseFactory::error('LYRICS_AUDIO_TAG_VALIDATION_FAILED',$error->getMessage(),422,$requestId);
        if($error instanceof LyricsAudioTagWritebackNotFound)return JsonResponseFactory::error('LYRICS_AUDIO_TAG_NOT_FOUND','歌词音频标签对象不存在。',404,$requestId);
        if($error instanceof LyricsAudioTagWritebackConflict)return JsonResponseFactory::error('LYRICS_AUDIO_TAG_CONFLICT',$error->getMessage(),409,$requestId);
        if($error instanceof UserRuntimeLimitExceeded)return JsonResponseFactory::error($error->reasonCode,'账号高成本任务已达到上限。',429,$requestId,
            ['current'=>$error->current,'maximum'=>$error->maximum]);
        Log::error('Lyrics audio tag writeback administration request failed.',
            ['request_id'=>$requestId,'exception_class'=>$error::class]);
        return JsonResponseFactory::error('LYRICS_AUDIO_TAG_UNAVAILABLE','歌词音频标签写回服务暂时不可用。',503,$requestId);
    }
}
