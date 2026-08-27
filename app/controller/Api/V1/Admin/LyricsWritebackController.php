<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Lyrics\LyricsWritebackAdminService;
use app\application\Lyrics\LyricsWritebackConflict;
use app\application\Lyrics\LyricsWritebackInvalid;
use app\application\Lyrics\LyricsWritebackNotFound;
use app\application\User\UserRuntimeLimitExceeded;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露管理员歌词 sidecar Dry Run、详情与强确认 API。
 *
 * 每个入口先要求 `edit_metadata`，应用服务再按歌曲所属音乐库做实时对象授权校验。Controller
 * 严格限制字段：歌词正文、目标路径、文件名、许可和任务状态均不能由浏览器提交。创建预览返回
 * 201，确认仅在任务持久化后返回 202；两者都不在 HTTP Worker 写文件。
 */
final class LyricsWritebackController
{
    /**
     * 创建最多 50 首歌曲的批量 sidecar 不可变预览。
     *
     * Controller 只接受歌曲/歌词不透明 ID 与预期歌词版本；目标文件、许可、正文和任务状态均由服务端
     * 推导。每个嵌套目标也严格拒绝未知字段，防止协议扩展时旧入口静默接收越权参数。
     */
    public function createBatch(Request $request): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['replaceExisting', 'targets']);
            if (!is_array($payload['targets'] ?? null) || !array_is_list($payload['targets'])
                || !is_bool($payload['replaceExisting'] ?? null)) {
                throw new LyricsWritebackInvalid('批量写回目标无效。');
            }
            $targets = [];
            foreach ($payload['targets'] as $target) {
                if (!is_array($target)) {
                    throw new LyricsWritebackInvalid('批量写回目标结构无效。');
                }
                $keys = array_keys($target);
                sort($keys);
                if ($keys !== ['expectedLyricVersion', 'lyricId', 'songId']
                    || !is_string($target['songId'] ?? null) || !is_string($target['lyricId'] ?? null)) {
                    throw new LyricsWritebackInvalid('批量写回目标结构无效。');
                }
                $targets[] = [
                    'songId' => $target['songId'],
                    'lyricId' => $target['lyricId'],
                    'expectedLyricVersion' => $this->positiveInteger(
                        $target['expectedLyricVersion'] ?? null,
                        '批量歌词版本无效。',
                    ),
                ];
            }
            $batch = (new LyricsWritebackAdminService())->createBatchPlan(
                $targets,
                $actor,
                $requestId,
                $payload['replaceExisting'],
            );

            return JsonResponseFactory::create([
                'data' => ['batch' => $batch],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 返回一个重新授权、重新汇总且不含歌词正文的批量预览或结果。 */
    public function showBatch(Request $request, string $batchId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $batch = (new LyricsWritebackAdminService())->batchDetail($batchId, $actor);

            return JsonResponseFactory::create([
                'data' => ['batch' => $batch],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 强确认批量预览并只提交单曲 Durable 子任务。
     *
     * 幂等键只进入请求内存，持久化仅保存 SHA-256；同一键重放返回同一个父批次。服务端会在任何任务
     * 写入前复验全部可执行目标，因而确认响应 202 只表示任务已持久化，不表示文件已经写入。
     */
    public function confirmBatch(Request $request, string $batchId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['confirmation', 'expectedPlanVersion', 'planHash']);
            if (!is_string($payload['planHash'] ?? null) || !is_string($payload['confirmation'] ?? null)) {
                throw new LyricsWritebackInvalid('批量写回方案摘要或确认文本无效。');
            }
            $idempotencyKey = $request->header('Idempotency-Key');
            if (!is_string($idempotencyKey)) {
                throw new LyricsWritebackInvalid('缺少 Idempotency-Key。');
            }
            $batch = (new LyricsWritebackAdminService())->confirmBatch(
                $batchId,
                $this->positiveInteger($payload['expectedPlanVersion'] ?? null, '批量写回方案版本无效。'),
                $payload['planHash'],
                $payload['confirmation'],
                $idempotencyKey,
                $actor,
                $requestId,
            );

            return JsonResponseFactory::create([
                'data' => ['batch' => $batch],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 202, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 创建服务器生成目标的不可变歌词写回预览。
     *
     * `replaceExisting` 默认由客户端显式提交 false；只有管理员看过冲突预览后再次提交 true，服务端
     * 才冻结旧文件身份并生成要求 `REPLACE LYRICS` 的替换方案。目标路径和旧文件身份仍不能由浏览器
     * 指定。
     */
    public function create(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['expectedLyricVersion', 'lyricId', 'replaceExisting']);
            if (!is_string($payload['lyricId'] ?? null) || !is_bool($payload['replaceExisting'] ?? null)) {
                throw new LyricsWritebackInvalid('歌词标识无效。');
            }
            $plan = (new LyricsWritebackAdminService())->createPlan(
                $songId,
                $payload['lyricId'],
                $this->positiveInteger($payload['expectedLyricVersion'] ?? null, '歌词版本无效。'),
                $actor,
                $requestId,
                $payload['replaceExisting'],
            );

            return JsonResponseFactory::create([
                'data' => ['plan' => $plan],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 201, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /** 返回当前管理范围内的无正文方案和任务投影。 */
    public function show(Request $request, string $planId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $plan = (new LyricsWritebackAdminService())->detail($planId, $actor);

            return JsonResponseFactory::create([
                'data' => ['plan' => $plan],
                'meta' => ['requestId' => $requestId, 'timestamp' => gmdate('c')],
            ], 200, $requestId);
        } catch (Throwable $throwable) {
            return $this->failure($throwable, $requestId);
        }
    }

    /**
     * 强确认预览并提交 Durable Worker 任务。
     *
     * `Idempotency-Key` 必须由客户端为一次用户意图生成，服务端只保存其 SHA-256；请求体不能携带
     * 另一个键或任何歌词/路径字段。重放同一键和方案返回原任务，不创建第二个 sidecar。
     */
    public function confirm(Request $request, string $planId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            $payload = $this->payload($request, ['confirmation', 'expectedPlanVersion', 'planHash']);
            if (!is_string($payload['planHash'] ?? null) || !is_string($payload['confirmation'] ?? null)) {
                throw new LyricsWritebackInvalid('写回方案摘要或确认文本无效。');
            }
            $idempotencyKey = $request->header('Idempotency-Key');
            if (!is_string($idempotencyKey)) {
                throw new LyricsWritebackInvalid('缺少 Idempotency-Key。');
            }
            $job = (new LyricsWritebackAdminService())->confirm(
                $planId,
                $this->positiveInteger($payload['expectedPlanVersion'] ?? null, '写回方案版本无效。'),
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

    /** 读取并严格比较允许字段，防止未来新增字段被旧 Controller 静默接受。 */
    private function payload(Request $request, array $allowedKeys): array
    {
        $payload = $request->post();
        if (!is_array($payload)) {
            throw new LyricsWritebackInvalid('请求内容无效。');
        }
        $keys = array_keys($payload);
        sort($keys);
        sort($allowedKeys);
        if ($keys !== $allowedKeys) {
            throw new LyricsWritebackInvalid('请求包含未知或缺失字段。');
        }

        return $payload;
    }

    /** 只接受正整数或规范十进制字符串，不做浮点和科学计数法转换。 */
    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }
        throw new LyricsWritebackInvalid($message);
    }

    /** 映射稳定安全错误；未知异常日志只含 request ID 与异常类。 */
    private function failure(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        }
        if ($throwable instanceof AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有编辑该音乐库元数据的权限。', 403, $requestId);
        }
        if ($throwable instanceof LyricsWritebackInvalid) {
            return JsonResponseFactory::error(
                'LYRICS_WRITEBACK_VALIDATION_FAILED',
                $throwable->getMessage(),
                422,
                $requestId,
            );
        }
        if ($throwable instanceof LyricsWritebackNotFound) {
            return JsonResponseFactory::error('LYRICS_WRITEBACK_NOT_FOUND', '歌词写回对象不存在。', 404, $requestId);
        }
        if ($throwable instanceof LyricsWritebackConflict) {
            return JsonResponseFactory::error('LYRICS_WRITEBACK_CONFLICT', $throwable->getMessage(), 409, $requestId);
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
        Log::error('Lyrics writeback administration request failed.', [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
        ]);

        return JsonResponseFactory::error(
            'LYRICS_WRITEBACK_UNAVAILABLE',
            '歌词写回服务暂时不可用。',
            503,
            $requestId,
        );
    }
}
