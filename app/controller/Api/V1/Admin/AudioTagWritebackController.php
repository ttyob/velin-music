<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Metadata\AudioTagWritebackAdminService;
use app\application\Metadata\AudioTagWritebackConflict;
use app\application\Metadata\AudioTagWritebackInvalid;
use app\application\Metadata\AudioTagWritebackNotFound;
use app\application\Metadata\MediaMetadataConflict;
use app\application\Metadata\MediaMetadataInvalid;
use app\application\Metadata\MediaMetadataNotFound;
use app\application\Metadata\MetadataFieldSchema;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露管理员音频描述标签 Dry Run、详情和强确认 API。
 *
 * 每个入口先校验 `edit_metadata`，应用服务再按歌曲实际关联库执行 manage 范围检查。浏览器只能提交
 * 当前全部字段版本、方案摘要和强确认材料，不能控制路径、FFmpeg 参数、标签键或任务状态。创建只
 * 读取文件身份，确认只持久化 Durable Job；任何 HTTP 请求都不会改写音频或触发扫描。
 */
final class AudioTagWritebackController
{
    /** 创建最多 50 首明确歌曲的批量描述标签预览。 */
    public function createBatch(Request$request):Response
    {
        $requestId=RequestContext::requestId();try{$actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['songIds']);
            if(!is_array($payload['songIds']??null)||!array_is_list($payload['songIds'])
                ||array_filter($payload['songIds'],static fn(mixed$id):bool=>!is_string($id))!==[])
                throw new AudioTagWritebackInvalid('批量歌曲标识无效。');
            $batch=(new AudioTagWritebackAdminService())->createBatch($payload['songIds'],$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],201,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /** 返回重新授权并从子任务事实汇总的批量投影。 */
    public function showBatch(Request$request,string$batchId):Response
    {
        $requestId=RequestContext::requestId();try{$actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $batch=(new AudioTagWritebackAdminService())->batchDetail($batchId,$actor);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],200,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /** 一次强确认并原子排队全部单曲描述标签任务。 */
    public function confirmBatch(Request$request,string$batchId):Response
    {
        $requestId=RequestContext::requestId();try{$actor=(new AuthorizationService())->requireCapability($request,'edit_metadata');
            $payload=$this->payload($request,['confirmation','expectedPlanVersion','planHash']);
            if(!is_string($payload['confirmation']??null)||!is_string($payload['planHash']??null))
                throw new AudioTagWritebackInvalid('批量确认材料无效。');
            $key=$request->header('Idempotency-Key');if(!is_string($key))throw new AudioTagWritebackInvalid('缺少 Idempotency-Key。');
            $batch=(new AudioTagWritebackAdminService())->confirmBatch($batchId,
                $this->positiveInteger($payload['expectedPlanVersion']??null,'批量方案版本无效。'),$payload['planHash'],
                $payload['confirmation'],$key,$actor,$requestId);
            return JsonResponseFactory::create(['data'=>['batch'=>$batch],
                'meta'=>['requestId'=>$requestId,'timestamp'=>gmdate('c')]],202,$requestId);
        }catch(Throwable$error){return$this->failure($error,$requestId);}
    }

    /**
     * 从终态父批次的失败项重新生成预览。
     *
     * 请求体必须为空；应用服务从旧批次事实决定目标，浏览器不能提交或扩大歌曲范围。该请求只创建
     * 新方案，不复位旧任务、不写音频，也不自动确认新方案。
     */
    public function retryBatchFailures(Request $request, string $batchId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $this->payload($request, []);
            $batch = (new AudioTagWritebackAdminService())->retryBatchFailures($batchId, $actor, $requestId);
            return JsonResponseFactory::create([
                'data' => ['batch' => $batch],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $error) {
            return $this->failure($error, $requestId);
        }
    }

    /** 创建冻结当前有效字段和音频身份、24 小时有效的不可变写回预览。 */
    public function create(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['fieldVersions']);
            $versions = $this->fieldVersions($payload['fieldVersions'] ?? null);
            $plan = (new AudioTagWritebackAdminService())->create($songId, $versions, $actor, $requestId);

            return JsonResponseFactory::create([
                'data' => ['plan' => $plan],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 返回实时重新授权后的方案、文件影响、风险和任务状态。 */
    public function show(Request $request, string $planId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $plan = (new AudioTagWritebackAdminService())->detail($planId, $actor);

            return JsonResponseFactory::create([
                'data' => ['plan' => $plan],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 强确认预览并排队，不在请求进程内调用 FFmpeg。
     *
     * `Idempotency-Key` 代表一次明确用户意图，长度和字符集由应用服务再次校验并仅保存 SHA-256。
     * 同一键重放相同方案返回首次任务；用于其他方案时返回 409，不能创建第二次文件替换。
     */
    public function confirm(Request $request, string $planId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['confirmation', 'expectedPlanVersion', 'planHash']);
            if (!is_string($payload['planHash'] ?? null) || !is_string($payload['confirmation'] ?? null)) {
                throw new AudioTagWritebackInvalid('方案摘要或确认文本无效。');
            }
            $idempotencyKey = $request->header('Idempotency-Key');
            if (!is_string($idempotencyKey)) throw new AudioTagWritebackInvalid('缺少 Idempotency-Key。');
            $job = (new AudioTagWritebackAdminService())->confirm(
                $planId,
                $this->positiveInteger($payload['expectedPlanVersion'] ?? null, '方案版本无效。'),
                $payload['planHash'],
                $payload['confirmation'],
                $idempotencyKey,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['job' => $job],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 202, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 严格比较允许字段，防止未来新增请求字段被旧 Controller 静默接受。 */
    private function payload(Request $request, array $allowed): array
    {
        $payload = $request->post();
        if (!is_array($payload)) throw new AudioTagWritebackInvalid('请求内容无效。');
        $keys = array_keys($payload);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) throw new AudioTagWritebackInvalid('请求包含未知或缺失字段。');

        return $payload;
    }

    /** @return array<string,int> */
    private function fieldVersions(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new AudioTagWritebackInvalid('字段版本必须是完整对象。');
        }
        $result = [];
        foreach ($value as $field => $version) {
            if (!is_string($field) || !in_array($field, MetadataFieldSchema::FIELDS, true)) {
                throw new AudioTagWritebackInvalid('字段版本包含未知字段。');
            }
            $result[$field] = $this->positiveInteger($version, '字段版本无效。');
        }
        $required = MetadataFieldSchema::FIELDS;
        $keys = array_keys($result);
        sort($required);
        sort($keys);
        if ($keys !== $required) throw new AudioTagWritebackInvalid('字段版本必须覆盖详情中的全部字段。');

        return $result;
    }

    /** 只接受正整数或规范十进制字符串，不接受浮点数和科学计数法。 */
    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) return (int) $value;
        throw new AudioTagWritebackInvalid($message);
    }

    /** 将领域异常映射为固定 HTTP 语义；未知异常日志只记录请求 ID 和异常类。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有编辑该音乐库元数据的权限。', 403, $requestId);
        }
        if ($throwable instanceof AudioTagWritebackInvalid || $throwable instanceof MediaMetadataInvalid) {
            return JsonResponseFactory::error('AUDIO_TAG_WRITEBACK_VALIDATION_FAILED', $throwable->getMessage(), 422, $requestId);
        }
        if ($throwable instanceof AudioTagWritebackNotFound || $throwable instanceof MediaMetadataNotFound) {
            return JsonResponseFactory::error('AUDIO_TAG_WRITEBACK_NOT_FOUND', '音频标签写回对象不存在。', 404, $requestId);
        }
        if ($throwable instanceof AudioTagWritebackConflict || $throwable instanceof MediaMetadataConflict) {
            return JsonResponseFactory::error('AUDIO_TAG_WRITEBACK_CONFLICT', $throwable->getMessage(), 409, $requestId);
        }
        if ($throwable instanceof UserRuntimeLimitExceeded) {
            return JsonResponseFactory::error(
                $throwable->reasonCode,
                '账号高成本任务已达到上限。',
                429,
                $requestId,
                ['current' => $throwable->current, 'maximum' => $throwable->maximum],
            );
        }
        Log::error('Audio tag writeback administration request failed.', [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error(
            'AUDIO_TAG_WRITEBACK_UNAVAILABLE',
            '音频标签写回服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
