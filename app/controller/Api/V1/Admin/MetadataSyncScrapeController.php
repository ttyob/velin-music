<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Metadata\MediaMetadataConflict;
use app\application\Metadata\MediaMetadataInvalid;
use app\application\Metadata\MediaMetadataNotFound;
use app\application\Metadata\MetadataSyncScrapeService;
use app\application\Metadata\ScrapeHistoryService;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 接收库内单曲候选搜索、渠道确认、批量忽略和进度查询。
 *
 * POST 只冻结歌曲 ID 并创建持久任务，绝不在 HTTP 进程访问平台。Controller 要求全局
 * `edit_metadata`，应用服务继续要求 `run_scrape` 和每个目标库实时 manage grant。
 */
final class MetadataSyncScrapeController
{
    public function __construct(
        private readonly MetadataSyncScrapeService $service = new MetadataSyncScrapeService(),
        private readonly ScrapeHistoryService $history = new ScrapeHistoryService(),
    ) {}

    /** 创建明确歌曲的候选搜索任务；新版管理端每次只提交一首。 */
    public function create(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['songIds']) {
                throw new MediaMetadataInvalid('请求结构无效。');
            }
            return ['job' => $this->service->create($actor, $payload['songIds'], $requestId)];
        }, 202);
    }

    /** 返回脱敏父进度和逐首终态，不返回查询响应或歌词正文。 */
    public function show(Request $request, string $jobId): Response
    {
        return $this->execute($request,
            fn (array $actor): array => ['job' => $this->service->show($actor, $jobId)]);
    }

    /** 返回当前管理员可管理音乐库内的逐曲历史；列表不读取平台响应、正文、图片或路径。 */
    public function history(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            $value = static function (mixed $input): ?string {
                if (!is_string($input)) return null;
                $input = trim($input);
                return $input === '' ? null : $input;
            };
            return ['history' => $this->history->page(
                $actor,
                $value($request->get('libraryId')),
                $value($request->get('status')),
                $value($request->get('mode')),
                $value($request->get('q')),
                max(1, min(100, (int) $request->get('limit', 20))),
                max(0, min(100_000, (int) $request->get('offset', 0))),
            )];
        });
    }

    /**
     * 将明确选择的历史 target 重新加入自动整体刮削。
     *
     * 浏览器只提交 opaque target ID 和随机幂等键；服务端重新解析当前歌曲、库授权和终态，不接受歌曲
     * ID、旧候选、渠道、路径或资源状态。成功返回 202，仅表示新任务已经持久入队。
     */
    public function requeueHistory(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['targetIds', 'idempotencyKey']) {
                throw new MediaMetadataInvalid('刮削历史重入队结构无效。');
            }
            return ['result' => $this->history->requeue(
                $actor, $payload['targetIds'], $payload['idempotencyKey'], $requestId,
            )];
        }, 202);
    }

    /**
     * 清空尚未领取的逐曲等待队列。
     *
     * 请求必须为空对象且受 CSRF 保护；运行中或资源阶段目标不会被强制终止，响应明确返回仍活动数量。
     */
    public function clearHistoryQueue(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) {
                throw new MediaMetadataInvalid('清空刮削队列请求结构无效。');
            }
            return ['result' => $this->history->clearQueue($actor, $requestId)];
        });
    }

    /**
     * 删除当前管理范围内的终态刮削记录。
     *
     * 请求必须为空对象且受 CSRF 保护；服务层不会删除媒体、元数据、歌词、封面文件或审计日志。
     */
    public function clearHistoryRecords(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) {
                throw new MediaMetadataInvalid('清空刮削记录请求结构无效。');
            }
            return ['result' => $this->history->clearRecords($actor, $requestId)];
        });
    }

    /**
     * 确认逐字段和资源渠道选择并进入异步应用阶段。
     *
     * 请求只接收字段到渠道的映射、歌词/歌曲封面渠道和已读取的任务版本，不接收候选字段值、歌词、
     * 图片或路径。专辑封面和艺人图只提交逐曲任务投影给出的 opaque candidate ID；应用服务会从
     * 服务端快照恢复各渠道候选、创建关联图片子任务并执行 CAS。成功返回 202，表示整首歌已进入同一
     * 异步应用状态机，而不是已经修改完成。
     */
    public function confirm(Request $request, string $jobId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $jobId): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['selections', 'expectedVersion']) {
                throw new MediaMetadataInvalid('候选确认结构无效。');
            }
            return ['job' => $this->service->confirm(
                $actor, $jobId, $payload['selections'], $payload['expectedVersion'], $requestId,
            )];
        }, 202);
    }

    /**
     * 忽略当前管理员可见的全部待确认任务。
     *
     * 请求正文必须为空对象且路由强制 CSRF。Controller 只完成认证和结构映射；服务层会在事务内复验
     * `run_scrape`、发起者和全部目标库 manage 范围。响应计数表示本次真正发生状态转换的父任务与逐曲
     * 目标数，重复调用成功返回零，不代表删除任务或候选历史。
     */
    public function ignoreAllAwaiting(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) {
                throw new MediaMetadataInvalid('批量忽略请求结构无效。');
            }
            return ['result' => $this->service->ignoreAllAwaitingConfirmations($actor, $requestId)];
        });
    }

    /** 统一认证和稳定错误映射；未知异常只记录类型和 request ID。 */
    private function execute(Request $request, callable $operation, int $status = 200): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'edit_metadata');
            return JsonResponseFactory::create(['data' => $operation($actor, $requestId), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], $status, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有同步刮削权限。', 403, $requestId);
        } catch (MediaMetadataInvalid) {
            return JsonResponseFactory::error('METADATA_SYNC_INVALID', '请选择 1 到 50 首可管理歌曲。', 422, $requestId);
        } catch (MediaMetadataNotFound) {
            return JsonResponseFactory::error('METADATA_SYNC_NOT_FOUND', '歌曲或同步任务不存在或不可管理。', 404, $requestId);
        } catch (MediaMetadataConflict) {
            return JsonResponseFactory::error('METADATA_SYNC_CONFLICT', '歌曲或任务已经变化，请刷新后重试。', 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Metadata sync scrape request failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('METADATA_SYNC_UNAVAILABLE', '同步刮削服务暂时不可用。', 503, $requestId);
        }
    }
}
