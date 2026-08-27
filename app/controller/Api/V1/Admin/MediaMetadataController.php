<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
use app\application\Metadata\MediaMetadataConflict;
use app\application\Metadata\MediaMetadataInvalid;
use app\application\Metadata\MediaMetadataNotFound;
use app\application\Metadata\MediaMetadataService;
use app\application\Metadata\EntityMediaMetadataService;
use app\application\Metadata\MetadataBatchService;
use app\application\Media\MediaDeletionConflict;
use app\application\Media\MediaDeletionInvalid;
use app\application\Media\MediaDeletionNotFound;
use app\application\Media\MediaDeletionService;
use app\application\Media\MediaDeletionUnavailable;
use app\http\JsonResponseFactory;
use app\http\RequestContext;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 暴露 ADMIN-META-001..004/012 的歌曲、艺术家与专辑通用元数据 API。
 *
 * 所有入口先校验 `edit_metadata`，领域服务再要求对象音乐库 manage。命令只接受对象 ID、字段值、锁定
 * 和版本，不接受路径、文件写回、扫描、封面二进制或歌词正文。普通保存始终写数据库覆盖层；音频标签
 * 写回必须使用后续独立 Dry Run API，不能在这里增加布尔开关绕过风险确认。
 */
final class MediaMetadataController
{
    /** 返回有界歌曲元数据页和当前可管理筛选项。 */
    public function index(Request $request): Response
    {
        return $this->execute($request, function (array $actor) use ($request): array {
            return (new MediaMetadataService())->page(
                $actor,
                $this->optionalString($request->get('libraryId')),
                $this->optionalString($request->get('missingField')),
                $this->optionalString($request->get('state')),
                $this->optionalString($request->get('q')),
                $this->integer($request->get('limit'), 50),
                $this->integer($request->get('offset'), 0),
            );
        });
    }

    /** 返回歌曲或共享实体逐字段原始/刮削/手工/有效值与近期审计。 */
    public function show(Request $request, string $type, string $mediaId): Response
    {
        return $this->execute($request, static function (array $actor) use ($type, $mediaId): array {
            return ['media' => $type === 'song'
                ? (new MediaMetadataService())->song($actor, $mediaId)
                : (new EntityMediaMetadataService())->detail($actor, $type, $mediaId)];
        });
    }

    /** 按每字段版本原子保存手工覆盖及锁定。 */
    public function save(Request $request, string $type, string $mediaId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $type, $mediaId): array {
            $payload = $this->payload($request);
            return ['media' => $type === 'song'
                ? (new MediaMetadataService())->save($actor, $mediaId, $payload['changes'], $requestId)
                : (new EntityMediaMetadataService())->save($actor, $type, $mediaId, $payload['changes'], $requestId)];
        });
    }

    /** 清除指定手工值并按来源优先级回退，不删除来源快照。 */
    public function clear(Request $request, string $type, string $mediaId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $type, $mediaId): array {
            $payload = $this->payload($request);
            return ['media' => $type === 'song'
                ? (new MediaMetadataService())->clear($actor, $mediaId, $payload['changes'], $requestId)
                : (new EntityMediaMetadataService())->clear($actor, $type, $mediaId, $payload['changes'], $requestId)];
        });
    }

    /**
     * 删除受管本地歌曲并把文件移入隔离回收区。
     *
     * 本入口只接受空 JSON 命令，要求全局 `manage_library`；路径、文件身份和外部库限制全部由领域服务
     * 重新校验。成功响应只返回不透明删除 ID 和歌曲 ID，不暴露原路径或回收区路径。
     */
    public function deleteSong(Request $request, string $songId): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) {
                throw new MediaDeletionInvalid('删除请求必须为空对象。');
            }
            $result = (new MediaDeletionService())->delete($actor, $songId, $requestId);
            return JsonResponseFactory::create(['data' => ['deletion' => $result], 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有管理音乐库的权限。', 403, $requestId);
        } catch (MediaDeletionInvalid) {
            return JsonResponseFactory::error('MEDIA_DELETION_INVALID', '删除请求或媒体库配置无效。', 422, $requestId);
        } catch (MediaDeletionNotFound) {
            return JsonResponseFactory::error('MEDIA_DELETION_NOT_FOUND', '歌曲不存在或不可删除。', 404, $requestId);
        } catch (MediaDeletionConflict) {
            return JsonResponseFactory::error('MEDIA_DELETION_CONFLICT', '歌曲或文件已经变化，请刷新后重试。', 409, $requestId);
        } catch (MediaDeletionUnavailable) {
            return JsonResponseFactory::error('MEDIA_DELETION_UNAVAILABLE', '歌曲删除未完成，请检查媒体存储状态。', 503, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Media deletion request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('MEDIA_DELETION_UNAVAILABLE', '歌曲删除服务暂时不可用。', 503, $requestId);
        }
    }

    /** 创建冻结目标和差异样本的 24 小时批量预览，不在 HTTP 内执行字段更新。 */
    public function createBatchPlan(Request $request): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request): array {
            $payload = $this->exactPayload($request, ['filter', 'operations']);
            if (!is_array($payload['filter']) || !is_array($payload['operations'])) throw new MediaMetadataInvalid('批量方案请求无效。');
            return ['plan' => (new MetadataBatchService())->create($actor, $payload['filter'], $payload['operations'], $requestId)];
        }, 201);
    }

    /** 返回重新授权后的不可变方案、进度和逐对象失败样本。 */
    public function showBatchPlan(Request $request, string $planId): Response
    {
        return $this->execute($request, static fn (array $actor): array => [
            'plan' => (new MetadataBatchService())->show($actor, $planId),
        ]);
    }

    /** 以方案版本和完整摘要排队异步执行。 */
    public function confirmBatchPlan(Request $request, string $planId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $planId): array {
            $payload = $this->exactPayload($request, ['snapshotSha256', 'version']);
            if (!is_int($payload['version']) || !is_string($payload['snapshotSha256'])) throw new MediaMetadataInvalid('方案确认请求无效。');
            return ['plan' => (new MetadataBatchService())->confirm(
                $actor, $planId, $payload['version'], $payload['snapshotSha256'], $requestId,
            )];
        });
    }

    /** 只从终态失败对象建立新预览，旧方案和成功结果保持不可变。 */
    public function retryBatchFailures(Request $request, string $planId): Response
    {
        return $this->execute($request, function (array $actor, string $requestId) use ($request, $planId): array {
            $this->exactPayload($request, []);
            return ['plan' => (new MetadataBatchService())->retryFailures($actor, $planId, $requestId)];
        }, 201);
    }

    /** @param callable(array<string,mixed>,string):array<string,mixed> $operation */
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
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有维护元数据的权限。', 403, $requestId);
        } catch (MediaMetadataInvalid) {
            return JsonResponseFactory::error('MEDIA_METADATA_INVALID', '请检查筛选、字段和值。', 422, $requestId);
        } catch (MediaMetadataNotFound) {
            return JsonResponseFactory::error('MEDIA_METADATA_NOT_FOUND', '媒体不存在或不可管理。', 404, $requestId);
        } catch (MediaMetadataConflict) {
            return JsonResponseFactory::error('MEDIA_METADATA_CONFLICT', '元数据已经变化，请刷新后重试。', 409, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Media metadata request failed.', ['request_id' => $requestId, 'exception_class' => $throwable::class]);
            return JsonResponseFactory::error('MEDIA_METADATA_UNAVAILABLE', '元数据服务暂时不可用。', 503, $requestId);
        }
    }

    /** 严格读取唯一顶层 `changes` 列表，防止未来危险字段被旧服务静默忽略。 */
    private function payload(Request $request): array
    {
        $payload = $request->post();
        if (!is_array($payload) || array_keys($payload) !== ['changes'] || !is_array($payload['changes'])) {
            throw new MediaMetadataInvalid('请求结构无效。');
        }
        return $payload;
    }

    /** 严格比较顶层键集合；空命令也必须提交空 JSON 对象而不是额外参数。 */
    private function exactPayload(Request $request, array $allowed): array
    {
        $payload = $request->post();
        if (!is_array($payload)) throw new MediaMetadataInvalid('请求结构无效。');
        $keys = array_keys($payload); sort($keys); sort($allowed);
        if ($keys !== $allowed) throw new MediaMetadataInvalid('请求包含未知或缺失字段。');
        return $payload;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) throw new MediaMetadataInvalid('查询参数无效。');
        return $value;
    }

    /** 查询分页只接受规范非负整数；业务上限继续由领域服务校验。 */
    private function integer(mixed $value, int $default): int
    {
        if ($value === null || $value === '') return $default;
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/', $value) === 1) return (int) $value;
        throw new MediaMetadataInvalid('分页参数无效。');
    }
}
