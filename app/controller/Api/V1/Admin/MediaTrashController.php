<?php

declare(strict_types=1);

namespace app\controller\Api\V1\Admin;

use app\application\Auth\AuthenticationRequired;
use app\application\Auth\AuthorizationDenied;
use app\application\Auth\AuthorizationService;
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
 * 暴露媒体隔离回收区的安全列表、恢复和永久删除命令。
 *
 * 所有入口要求全局 `manage_library`，领域服务继续按删除记录所属音乐库实时校验 manage 范围。浏览器
 * 只能提交不透明 deletion ID 和固定确认文本，不能读取或提交原始路径、回收路径、inode 或 receipt。
 * 恢复与永久删除均受 CSRF 保护；永久删除不可回滚，失败只返回稳定错误码，不暴露文件系统细节。
 */
final class MediaTrashController
{
    /** 返回管理员可管理范围内的活动回收条目分页。 */
    public function index(Request $request): Response
    {
        return $this->execute($request, static fn (array $actor): array => (new MediaDeletionService())->page(
            $actor,
            is_numeric($request->get('limit')) ? (int) $request->get('limit') : 50,
            is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0,
        ));
    }

    /** 恢复单个回收条目；请求体必须为空对象，路径与文件身份全部由服务端凭据确定。 */
    public function restore(Request $request, string $deletionId): Response
    {
        return $this->execute($request, static function (array $actor, string $requestId) use ($request, $deletionId): array {
            $payload = $request->post();
            if (!is_array($payload) || $payload !== []) throw new MediaDeletionInvalid('恢复请求必须为空对象。');
            return ['entry' => (new MediaDeletionService())->restore($actor, $deletionId, $requestId)];
        });
    }

    /** 永久删除单个回收条目；固定确认文本用于阻止通用删除表单误调用不可逆命令。 */
    public function purge(Request $request, string $deletionId): Response
    {
        return $this->execute($request, static function (array $actor, string $requestId) use ($request, $deletionId): array {
            $payload = $request->post();
            if (!is_array($payload) || array_keys($payload) !== ['confirmation']
                || !is_string($payload['confirmation'])) throw new MediaDeletionInvalid('永久删除请求无效。');
            return ['entry' => (new MediaDeletionService())->purge(
                $actor, $deletionId, $payload['confirmation'], $requestId,
            )];
        });
    }

    /** 统一执行认证、能力校验和稳定错误映射；未知异常日志不包含路径或请求正文。 */
    private function execute(Request $request, callable $operation): Response
    {
        $requestId = RequestContext::requestId();
        try {
            $actor = (new AuthorizationService())->requireCapability($request, 'manage_library');
            return JsonResponseFactory::create(['data' => $operation($actor, $requestId), 'meta' => [
                'requestId' => $requestId, 'timestamp' => gmdate('c'),
            ]], 200, $requestId);
        } catch (AuthenticationRequired) {
            return JsonResponseFactory::error('AUTHENTICATION_REQUIRED', '请先登录。', 401, $requestId);
        } catch (AuthorizationDenied) {
            return JsonResponseFactory::error('PERMISSION_DENIED', '没有管理音乐库的权限。', 403, $requestId);
        } catch (MediaDeletionInvalid) {
            return JsonResponseFactory::error('MEDIA_TRASH_INVALID', '回收站请求无效。', 422, $requestId);
        } catch (MediaDeletionNotFound) {
            return JsonResponseFactory::error('MEDIA_TRASH_NOT_FOUND', '回收条目不存在或不可管理。', 404, $requestId);
        } catch (MediaDeletionConflict) {
            return JsonResponseFactory::error('MEDIA_TRASH_CONFLICT', '回收条目或文件已经变化，请刷新后重试。', 409, $requestId);
        } catch (MediaDeletionUnavailable) {
            return JsonResponseFactory::error('MEDIA_TRASH_UNAVAILABLE', '回收站操作未完成，请检查媒体存储状态。', 503, $requestId);
        } catch (Throwable $throwable) {
            Log::error('Media trash request failed.', [
                'request_id' => $requestId, 'exception_class' => $throwable::class,
            ]);
            return JsonResponseFactory::error('MEDIA_TRASH_UNAVAILABLE', '回收站服务暂时不可用。', 503, $requestId);
        }
    }
}
